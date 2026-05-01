<?php
/**
 * Review Service
 *
 * Orchestrates document review process.
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

use WP_Error;
use AI_Feedback\Logger;

/**
 * Review Service class.
 */
class Review_Service {


	/**
	 * HTTP request timeout (seconds) applied while calling the AI Client.
	 *
	 * Default WordPress HTTP timeout is 5 seconds, which is well below the
	 * latency budget of an AI generation. Tunable via the
	 * `ai_feedback_http_timeout` filter.
	 *
	 * @var int
	 */
	const AI_HTTP_TIMEOUT = 60;

	/**
	 * Non-retryable error codes that should fail immediately without retry.
	 *
	 * Codes are matched against the WP_Error returned by
	 * wp_ai_client_prompt()->generate_text(). The defaults cover both the
	 * codes the plugin used historically and a broader set likely to surface
	 * from core's HTTP transport / AI Client. Adopters can extend the list
	 * via the `ai_feedback_non_retryable_errors` filter.
	 *
	 * @var array
	 */
	const NON_RETRYABLE_ERRORS = array(
		'rate_limit_exceeded',
		'invalid_api_key',
		'billing_error',
		'rest_forbidden',
	);

	/**
	 * Substrings that indicate a permanent (non-retryable) failure when found
	 * in an error message, regardless of the error code. This is a defensive
	 * fallback for cases where the core AI Client reports auth/billing
	 * failures under a generic code.
	 *
	 * @var array
	 */
	const NON_RETRYABLE_MESSAGE_KEYWORDS = array(
		'invalid api key',
		'unauthorized',
		'authentication',
		'billing',
		'quota',
		'insufficient',
	);

	/**
	 * Prompt builder instance.
	 *
	 * @var Prompt_Builder
	 */
	private Prompt_Builder $prompt_builder;

	/**
	 * Response parser instance.
	 *
	 * @var Response_Parser
	 */
	private Response_Parser $response_parser;

	/**
	 * Notes manager instance.
	 *
	 * @var Notes_Manager
	 */
	private Notes_Manager $notes_manager;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->prompt_builder  = new Prompt_Builder();
		$this->response_parser = new Response_Parser();
		$this->notes_manager   = new Notes_Manager();
	}

	/**
	 * Review a document.
	 *
	 * @param  int   $post_id Post ID to review.
	 * @param  array $options Review options including 'blocks' array and optional 'existing_feedback'.
	 * @return array|WP_Error Review results or error.
	 */
	public function review_document( int $post_id, array $options = array() ): array|WP_Error {
		// Get post for fallback title.
		$post = get_post( $post_id );
		if ( ! $post ) {
			Logger::debug( sprintf( 'Error: Post %d not found in database', $post_id ) );
			return new WP_Error(
				'invalid_post',
				__( 'Post not found.', 'ai-feedback' )
			);
		}

		// Get blocks from options (sent from editor with clientIds).
		$blocks = $options['blocks'] ?? array();

		if ( empty( $blocks ) ) {
			Logger::debug( 'Error: No blocks provided for review' );
			return new WP_Error(
				'no_blocks',
				__( 'No content blocks provided for review.', 'ai-feedback' )
			);
		}

		Logger::debug( sprintf( 'Processing %d blocks for review', count( $blocks ) ) );

		// Validate block count.
		if ( count( $blocks ) > 100 ) {
			Logger::debug( sprintf( 'Error: Too many blocks (%d, max 100)', count( $blocks ) ) );
			return new WP_Error(
				'too_many_blocks',
				__( 'Post has too many blocks (max 100). Please split into smaller posts.', 'ai-feedback' )
			);
		}

		// Use title from editor if provided, otherwise fall back to saved title.
		if ( empty( $options['post_title'] ) ) {
			$options['post_title'] = $post->post_title;
		}

		// Resolve locale if set to 'auto' or not provided.
		if ( empty( $options['locale'] ) || 'auto' === $options['locale'] ) {
			$options['locale'] = get_locale();
		}

		// Get existing feedback for continuation reviews if not already provided.
		$existing_feedback = $options['existing_feedback'] ?? array();
		$is_continuation   = ! empty( $existing_feedback );

		if ( $is_continuation ) {
			Logger::debug( sprintf( 'Continuation review with %d existing feedback items', count( $existing_feedback ) ) );
		}

		// Build prompt with existing feedback context if available.
		$prompt             = $this->prompt_builder->build_review_prompt( $blocks, $options, $existing_feedback );
		$system_instruction = $this->prompt_builder->get_system_instruction( $is_continuation );

		// Get AI model.
		$model = $options['model'] ?? 'claude-sonnet-4-20250514';

		Logger::debug( sprintf( 'Calling AI model: %s', $model ) );

		// Check if using mock mode.
		if ( defined( 'AI_FEEDBACK_MOCK_MODE' ) && AI_FEEDBACK_MOCK_MODE ) {
			$ai_response = $this->get_mock_response( $blocks );
			Logger::debug( 'Using mock response mode' );
		} else {
			// Call AI with retry logic.
			$ai_response = $this->call_ai_with_retry( $prompt, $system_instruction, $model );

			if ( is_wp_error( $ai_response ) ) {
				Logger::debug( sprintf( 'AI request failed: %s', $ai_response->get_error_message() ) );
				return $ai_response;
			}
		}

		Logger::debug( 'AI response received, parsing feedback' );

		// Parse response (now returns summary + feedback).
		$parsed_response = $this->response_parser->parse_feedback( $ai_response, $blocks );

		$ai_summary     = $parsed_response['summary'] ?? '';
		$feedback_items = $parsed_response['feedback'] ?? array();

		if ( empty( $feedback_items ) ) {
			Logger::debug( 'No feedback items parsed from AI response' );
			// Still allow empty feedback - might be a well-written document!
		}

		// Get statistical summary.
		$stats_summary = $this->response_parser->get_feedback_summary( $feedback_items );

		// Generate review ID.
		$review_id = wp_generate_uuid4();

		// Build review data.
		$review_data = array(
			'review_id' => $review_id,
			'post_id'   => $post_id,
			'model'     => $model,
			'timestamp' => current_time( 'mysql' ),
		);

		// Create WordPress Notes from feedback.
		// For continuation reviews, append to existing threads where possible.
		$note_result = $this->create_notes_with_threading(
			$feedback_items,
			$post_id,
			$review_data,
			$is_continuation
		);

		// Check if note creation failed.
		if ( is_wp_error( $note_result ) ) {
			Logger::debug( sprintf( 'Note creation failed: %s', $note_result->get_error_message() ) );
			// Still return the feedback data, but include error.
			return array(
				'review_id'     => $review_id,
				'post_id'       => $post_id,
				'model'         => $model,
				'notes'         => $feedback_items,
				'summary'       => $stats_summary,
				'summary_text'  => $ai_summary,
				'timestamp'     => $review_data['timestamp'],
				'note_ids'      => array(),
				'block_mapping' => array(),
				'note_count'    => count( $feedback_items ),
				'notes_error'   => $note_result->get_error_message(),
			);
		}

		// Extract note_ids and block_mapping from result.
		$note_ids      = $note_result['note_ids'] ?? array();
		$block_mapping = $note_result['block_mapping'] ?? array();

		// Fetch the actual formatted notes for the response.
		// This allows the frontend to populate the store immediately.
		$formatted_notes = $this->notes_manager->get_notes_by_review( $review_id );

		Logger::debug( sprintf( 'Created %d notes with %d block mappings', count( $note_ids ), count( $block_mapping ) ) );

		// Build response with note IDs and block mapping.
		return array(
			'review_id'     => $review_id,
			'post_id'       => $post_id,
			'model'         => $model,
			'notes'         => $formatted_notes,
			'note_ids'      => $note_ids,
			'block_mapping' => $block_mapping,
			'summary'       => $stats_summary,
			'summary_text'  => $ai_summary,
			'note_count'    => count( $note_ids ),
			'timestamp'     => $review_data['timestamp'],
		);
	}

	/**
	 * Call AI service via the WordPress 7.0 core AI Client.
	 *
	 * Wraps the request with a scoped `http_request_timeout` filter so the
	 * WordPress HTTP transport waits long enough for AI generation. The
	 * filter is removed immediately after the call so it does not affect
	 * other HTTP requests in the same pageload.
	 *
	 * @param  string $prompt             User prompt.
	 * @param  string $system_instruction System instruction.
	 * @param  string $model              Model to use.
	 * @return string|WP_Error AI response or error.
	 */
	protected function call_ai( string $prompt, string $system_instruction, string $model ): string|WP_Error {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'ai_client_missing',
				__( 'The WordPress core AI Client is unavailable. WordPress 7.0 or higher is required.', 'ai-feedback' )
			);
		}

		/**
		 * Filters the HTTP timeout (in seconds) used for AI generation requests.
		 *
		 * @param int $timeout Timeout in seconds. Default 60.
		 */
		$timeout        = (int) apply_filters( 'ai_feedback_http_timeout', self::AI_HTTP_TIMEOUT );
		$timeout_filter = static function () use ( $timeout ) {
			return $timeout;
		};
		add_filter( 'http_request_timeout', $timeout_filter, PHP_INT_MAX );

		try {
			// Lower temperature keeps feedback consistent. Max tokens covers large
			// documents while staying within all supported models' limits.
			return wp_ai_client_prompt( $prompt )
				->using_system_instruction( $system_instruction )
				->using_model_preference( $model )
				->using_temperature( 0.3 )
				->using_max_tokens( 8000 )
				->generate_text();
		} catch ( \Throwable $e ) {
			// Defensive: the core AI Client should already convert SDK
			// exceptions to WP_Error, but transport-level throws would
			// otherwise propagate as a 500 with no useful body.
			return new WP_Error( 'ai_request_failed', $e->getMessage() );
		} finally {
			remove_filter( 'http_request_timeout', $timeout_filter, PHP_INT_MAX );
		}
	}

	/**
	 * Call AI service with retry logic and exponential backoff.
	 *
	 * @param  string $prompt             User prompt.
	 * @param  string $system_instruction System instruction.
	 * @param  string $model              Model to use.
	 * @param  int    $max_retries        Maximum number of retry attempts.
	 * @return string|WP_Error AI response or error.
	 */
	protected function call_ai_with_retry(
		string $prompt,
		string $system_instruction,
		string $model,
		int $max_retries = 3
	): string|WP_Error {
		$delay_ms   = 1000; // Start at 1 second (1000ms).
		$last_error = null;

		for ( $attempt = 0; $attempt <= $max_retries; $attempt++ ) {
			// Sleep before retry attempts (not before first attempt).
			if ( $attempt > 0 ) {
				Logger::debug(
					sprintf(
						'Retry attempt %d/%d for AI call, waiting %dms',
						$attempt,
						$max_retries,
						$delay_ms
					)
				);
				usleep( $delay_ms * 1000 ); // Convert ms to microseconds.
				$delay_ms *= 2; // Exponential backoff for next attempt.
			}

			$response = $this->call_ai( $prompt, $system_instruction, $model );

			// Success - return immediately.
			if ( ! is_wp_error( $response ) ) {
				if ( $attempt > 0 ) {
					Logger::debug( sprintf( 'AI call succeeded on retry attempt %d', $attempt ) );
				}
				return $response;
			}

			$last_error = $response;
			$error_code = $response->get_error_code();

			// Check if error is non-retryable - fail immediately.
			if ( $this->is_non_retryable_error( $response ) ) {
				Logger::debug(
					sprintf(
						'Non-retryable error encountered: %s - %s',
						$error_code,
						$response->get_error_message()
					)
				);
				return $response;
			}

			// Log the failure for retryable errors.
			Logger::debug(
				sprintf(
					'AI call failed (attempt %d/%d): %s - %s',
					$attempt + 1,
					$max_retries + 1,
					$error_code,
					$response->get_error_message()
				)
			);
		}

		Logger::debug(
			sprintf(
				'All %d attempts exhausted, returning final error',
				$max_retries + 1
			)
		);
		return $last_error;
	}

	/**
	 * Whether a WP_Error returned by the AI Client should fail fast.
	 *
	 * Matches both error codes and error message keywords so that
	 * permanently-failing requests (auth, billing, quota) bypass the retry
	 * loop even when core wraps them under a generic code.
	 *
	 * @param  WP_Error $error The error returned by the AI Client.
	 * @return bool True when the request should not be retried.
	 */
	protected function is_non_retryable_error( WP_Error $error ): bool {
		/**
		 * Filters the list of error codes treated as non-retryable.
		 *
		 * @param string[] $codes Error codes that should fail immediately.
		 */
		$codes = (array) apply_filters(
			'ai_feedback_non_retryable_errors',
			self::NON_RETRYABLE_ERRORS
		);

		if ( in_array( $error->get_error_code(), $codes, true ) ) {
			return true;
		}

		$message = strtolower( (string) $error->get_error_message() );
		foreach ( self::NON_RETRYABLE_MESSAGE_KEYWORDS as $keyword ) {
			if ( '' !== $message && str_contains( $message, $keyword ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get mock AI response for testing.
	 *
	 * @param  array $blocks Document blocks with clientId, name, content.
	 * @return string Mock JSON response.
	 */
	private function get_mock_response( array $blocks ): string {
		$feedback = array();

		// Generate mock feedback for first 3 blocks.
		$mock_count = min( 3, count( $blocks ) );

		for ( $i = 0; $i < $mock_count; $i++ ) {
			$block     = $blocks[ $i ];
			$client_id = $block['clientId'] ?? 'unknown-' . $i;

			$feedback[] = array(
				'block_id'   => $client_id,
				'category'   => array( 'content', 'tone', 'flow' )[ $i % 3 ],
				'severity'   => array( 'suggestion', 'important' )[ $i % 2 ],
				'title'      => 'Mock feedback item ' . ( $i + 1 ),
				'feedback'   => 'This is mock feedback for testing purposes. The AI integration is working correctly.',
				'suggestion' => 'Consider this mock suggestion for improvement.',
			);
		}

		// Build response with summary.
		$response = array(
			'summary'  => sprintf(
				'This review generated %d notes of constructive feedback. Overall, the document has a professional tone with clear structure. Key areas for improvement include content clarity and flow between sections.',
				count( $feedback )
			),
			'feedback' => $feedback,
		);

		return wp_json_encode( $response );
	}

	/**
	 * Create notes with threading support for continuation reviews.
	 *
	 * For continuation reviews, this method checks if blocks already have note threads
	 * and appends new feedback as replies to existing threads.
	 *
	 * @param  array $feedback_items  Parsed feedback items from AI.
	 * @param  int   $post_id         Post ID.
	 * @param  array $review_data     Review metadata.
	 * @param  bool  $is_continuation Whether this is a continuation review.
	 * @return array|WP_Error Array with note_ids and block_mapping, or error.
	 */
	private function create_notes_with_threading(
		array $feedback_items,
		int $post_id,
		array $review_data,
		bool $is_continuation
	): array|WP_Error {
		// For non-continuation reviews, use the standard method.
		if ( ! $is_continuation ) {
			return $this->notes_manager->create_notes_from_feedback(
				$feedback_items,
				$post_id,
				$review_data
			);
		}

		// For continuation reviews, check for existing threads and append.
		$note_ids      = array();
		$block_mapping = array();
		$errors        = array();

		// Group feedback by block_id.
		$grouped_feedback = array();
		foreach ( $feedback_items as $item ) {
			$block_id = ! empty( $item['block_id'] ) ? $item['block_id'] : 'document';
			if ( ! isset( $grouped_feedback[ $block_id ] ) ) {
				$grouped_feedback[ $block_id ] = array();
			}
			$grouped_feedback[ $block_id ][] = $item;
		}

		foreach ( $grouped_feedback as $block_id => $items ) {
			// Check if this block already has a note thread.
			$existing_note_id = null;
			if ( 'document' !== $block_id ) {
				// Try to find existing note by block_id, block_name, or block_index.
				$block_name  = $items[0]['block_name'] ?? null;
				$block_index = $items[0]['block_index'] ?? null;

				$existing_note_id = $this->notes_manager->get_existing_note_for_block(
					$post_id,
					$block_id,
					$block_name,
					$block_index
				);
			}

			if ( null !== $existing_note_id ) {
				// Append to existing thread.
				Logger::debug(
					sprintf(
						'Appending %d feedback items to existing thread %d for block %s',
						count( $items ),
						$existing_note_id,
						$block_id
					)
				);

				foreach ( $items as $item ) {
					$note_id = $this->notes_manager->add_reply_to_thread(
						$item,
						$post_id,
						$existing_note_id,
						$review_data
					);

					if ( is_wp_error( $note_id ) ) {
								$errors[] = $note_id->get_error_message();
								continue;
					}

						$note_ids[] = $note_id;
				}

				// Map block to the existing parent note.
				$block_mapping[ $block_id ] = $existing_note_id;
			} else {
				// Create new thread for this block.
				$parent_id = 0;
				foreach ( $items as $index => $item ) {
					$note_id = $this->notes_manager->add_reply_to_thread(
						$item,
						$post_id,
						$parent_id,
						$review_data
					);

					if ( is_wp_error( $note_id ) ) {
								$errors[] = $note_id->get_error_message();
								continue;
					}

					$note_ids[] = $note_id;

					// First note becomes parent for subsequent notes.
					if ( 0 === $index ) {
						$parent_id = $note_id;
						if ( 'document' !== $block_id ) {
							$block_mapping[ $block_id ] = $note_id;
						}
					}
				}
			}
		}

		// If all notes failed, return error.
		if ( empty( $note_ids ) && ! empty( $errors ) ) {
			return new WP_Error(
				'note_creation_failed',
				sprintf(
				/* translators: %s: error messages */
					__( 'Failed to create notes: %s', 'ai-feedback' ),
					implode( ', ', $errors )
				)
			);
		}

		return array(
			'note_ids'      => $note_ids,
			'block_mapping' => $block_mapping,
		);
	}

	/**
	 * Check rate limit for user.
	 *
	 * @param  int $user_id User ID.
	 * @return bool|WP_Error True if allowed, WP_Error if rate limited.
	 */
	public function check_rate_limit( int $user_id ): bool|WP_Error {
		$rate_limit_key = 'ai_feedback_reviews';
		$reviews        = get_user_meta( $user_id, $rate_limit_key, true );

		if ( ! is_array( $reviews ) ) {
			$reviews = array();
		}

		// Remove reviews older than 1 hour.
		$one_hour_ago = time() - HOUR_IN_SECONDS;
		$reviews      = array_filter(
			$reviews,
			function ( $timestamp ) use ( $one_hour_ago ) {
				return $timestamp > $one_hour_ago;
			}
		);

		// Check if limit exceeded (10 per hour).
		if ( count( $reviews ) >= 10 ) {
			return new WP_Error(
				'rate_limit_exceeded',
				__( 'You have reached the maximum number of reviews per hour (10). Please try again later.', 'ai-feedback' )
			);
		}

		// Add current review.
		$reviews[] = time();
		update_user_meta( $user_id, $rate_limit_key, $reviews );

		return true;
	}
}
