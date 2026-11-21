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
	 * Request timeout (seconds) for AI generation calls.
	 *
	 * Core's AI Client defaults to 30 seconds, which a full-document review
	 * can exceed. Tunable via the `ai_feedback_http_timeout` filter.
	 *
	 * @var int
	 */
	const AI_HTTP_TIMEOUT = 60;

	/**
	 * Non-retryable error codes that should fail immediately without retry.
	 *
	 * Includes the core AI Client codes for requests that fail the same way
	 * every time. Rate limits (HTTP 429) are deliberately retried with
	 * backoff. Extend via the `ai_feedback_non_retryable_errors` filter.
	 *
	 * @var string[]
	 */
	const NON_RETRYABLE_ERRORS = array(
		'invalid_api_key',
		'billing_error',
		'rest_forbidden',
		'prompt_invalid_argument',
		'prompt_token_limit_reached',
		'prompt_prevented',
		Model_Pin::ERROR_MODEL_UNAVAILABLE,
		Model_Pin::ERROR_PROVIDER_UNAVAILABLE,
		Model_Pin::ERROR_WRONG_PROVIDER,
	);

	/**
	 * HTTP statuses, from the WP_Error data, that retrying cannot fix.
	 *
	 * @var int[]
	 */
	const NON_RETRYABLE_STATUSES = array( 400, 401, 402, 403, 404, 422 );

	/**
	 * Message phrases that mark a permanent auth or billing failure, for
	 * providers that report one under a retryable status (such as a 429 for
	 * an exhausted quota). Kept specific so transient errors still retry.
	 *
	 * @var string[]
	 */
	const NON_RETRYABLE_MESSAGE_KEYWORDS = array(
		'invalid api key',
		'invalid x-api-key',
		'incorrect api key',
		'credit balance',
		'insufficient_quota',
		'exceeded your current quota',
		'billing',
	);

	/**
	 * HTTP statuses that mean the AI provider is temporarily unavailable.
	 *
	 * @var int[]
	 */
	const UNAVAILABLE_STATUSES = array( 429, 500, 502, 503, 504 );

	/**
	 * Seconds the editor waits before retrying when the AI service is down.
	 *
	 * @var int
	 */
	const DEFAULT_RETRY_AFTER = 30;

	/**
	 * Longest retry delay the filter may set, in seconds.
	 *
	 * @var int
	 */
	const MAX_RETRY_AFTER = 300;

	/**
	 * Rate limit refunds allowed per user per hour.
	 *
	 * Bounds how many requests a user can send to a failing provider.
	 *
	 * @var int
	 */
	const MAX_REFUNDS_PER_HOUR = 10;

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
	 * Provider and model that answered the last AI call, when core said.
	 *
	 * @var array{provider: string, model: string}|null
	 */
	protected ?array $answered_by = null;

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
				__( 'Post not found.', 'fresh-eyes' )
			);
		}

		// Get blocks from options (sent from editor with clientIds).
		$blocks = $options['blocks'] ?? array();

		if ( empty( $blocks ) ) {
			Logger::debug( 'Error: No blocks provided for review' );
			return new WP_Error(
				'no_blocks',
				__( 'No content blocks provided for review.', 'fresh-eyes' )
			);
		}

		Logger::debug( sprintf( 'Processing %d blocks for review', count( $blocks ) ) );

		// Validate block count.
		if ( count( $blocks ) > 100 ) {
			Logger::debug( sprintf( 'Error: Too many blocks (%d, max 100)', count( $blocks ) ) );
			return new WP_Error(
				'too_many_blocks',
				__( 'Post has too many blocks (max 100). Please split into smaller posts.', 'fresh-eyes' )
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

		// An unknown persona, such as one a filter removed, reviews as the default.
		$options['persona']  = Personas::normalize( $options['persona'] ?? null );
		$options['audience'] = Personas::sanitize_audience( $options['audience'] ?? '' );

		// Get existing feedback for continuation reviews if not already provided.
		$existing_feedback = $options['existing_feedback'] ?? array();
		$is_continuation   = ! empty( $existing_feedback );

		$position = Review_Chunks::get_position( $options );
		if ( $position['total'] > 1 && is_array( $existing_feedback ) && is_array( $blocks ) ) {
			Logger::debug( sprintf( 'Reviewing chunk %d of %d', $position['index'] + 1, $position['total'] ) );
			$existing_feedback = $this->filter_feedback_for_chunk( $existing_feedback, $blocks, 0 === $position['index'] );
		}

		if ( $is_continuation ) {
			Logger::debug( sprintf( 'Continuation review with %d existing feedback items', count( $existing_feedback ) ) );
		}

		// Get AI model.
		$model    = $options['model'] ?? AI_FEEDBACK_DEFAULT_MODEL;
		$is_mock  = self::is_mock_mode();
		$images   = $is_mock || ! is_array( $blocks ) || ! is_string( $model ) ? array() : $this->get_review_images( $blocks, $model );
		$attached = array_column( $images, 'block_id' );

		// Build prompt with existing feedback context if available.
		$options['image_blocks'] = $attached;
		$options['house_style']  = House_Style::get_prompt_rules( get_current_user_id() );
		$prompt                  = $this->prompt_builder->build_review_prompt( $blocks, $options, $existing_feedback );
		$system_instruction      = $this->prompt_builder->get_system_instruction( $is_continuation );

		Logger::debug( sprintf( 'Calling AI model: %s', $model ) );

		// Check if using mock mode.
		if ( $is_mock ) {
			$ai_response = $this->get_mock_response( is_array( $blocks ) ? $blocks : array(), $options['persona'], $prompt );
			Logger::debug( 'Using mock response mode' );
		} else {
			// Call AI with retry logic.
			$ai_response = $this->call_ai_with_retry( $prompt, $system_instruction, $model, 3, $images );

			// A model may refuse image input; review the text alone then.
			if ( is_wp_error( $ai_response ) && array() !== $images && self::is_refused_input( $ai_response ) ) {
				Logger::debug( sprintf( 'AI request with images was refused (%s: %s), retrying without them', $ai_response->get_error_code(), $ai_response->get_error_message() ) );
				$attached                = array();
				$options['image_blocks'] = array();
				$prompt                  = $this->prompt_builder->build_review_prompt( $blocks, $options, $existing_feedback );
				// One retry at most, so a refused request can't double the wait.
				$ai_response = $this->call_ai_with_retry( $prompt, $system_instruction, $model, 1 );
			}

			if ( is_wp_error( $ai_response ) ) {
				Logger::debug( sprintf( 'AI request failed: %s', $ai_response->get_error_message() ) );
				return $this->is_service_unavailable_error( $ai_response )
					? $this->service_unavailable_error( $ai_response )
					: $ai_response;
			}
		}

		Logger::debug( 'AI response received, parsing feedback' );

		// Parse response (now returns summary + feedback).
		$parsed_response = $this->response_parser->parse_feedback( $ai_response, $blocks, false, $attached );

		$ai_summary     = $parsed_response['summary'] ?? '';
		$feedback_items = $parsed_response['feedback'] ?? array();
		$persona        = $this->build_persona_result( $options['persona'], is_array( $parsed_response ) ? ( $parsed_response['persona_takeaway'] ?? '' ) : '' );

		if ( empty( $feedback_items ) ) {
			Logger::debug( 'No feedback items parsed from AI response' );
			// Still allow empty feedback - might be a well-written document!
		}

		// Get statistical summary.
		$stats_summary = $this->response_parser->get_feedback_summary( $feedback_items );

		// Chunks of one review share the ID created for the first chunk.
		$review_id = is_string( $options['review_id'] ?? null ) && '' !== $options['review_id'] ? $options['review_id'] : wp_generate_uuid4();

		// Build review data. The timestamp is UTC with an explicit offset so
		// the editor can show it in the site timezone.
		$review_data = array(
			'review_id' => $review_id,
			'post_id'   => $post_id,
			'model'     => $model,
			'timestamp' => current_time( 'c', true ),
			'persona'   => $options['persona'],
		);
		$answered_by = $is_mock ? array() : $this->answered_by_result();
		if ( isset( $answered_by['answered_by'] ) ) {
			$review_data['answered_by'] = $answered_by['answered_by'];
		}

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
			$result = array(
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
			) + $persona + $answered_by;

			$this->fire_review_completed( $post_id, $result );

			return $result;
		}

		// Extract note_ids and block_mapping from result.
		$note_ids      = $note_result['note_ids'] ?? array();
		$block_mapping = $note_result['block_mapping'] ?? array();

		// Fetch the actual formatted notes for the response.
		// This allows the frontend to populate the store immediately.
		$formatted_notes = $this->notes_manager->get_notes_by_review( $review_id );

		Logger::debug( sprintf( 'Created %d notes with %d block mappings', count( $note_ids ), count( $block_mapping ) ) );

		// Build response with note IDs and block mapping.
		$result = array(
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
		) + $persona + $answered_by;

		$this->fire_review_completed( $post_id, $result );

		return $result;
	}

	/**
	 * Suggest house style rules from published posts.
	 *
	 * One AI request, made the way a review's is. Nothing is saved: the
	 * rules come back for the user to save or skip.
	 *
	 * @param  array<mixed> $posts Posts, each with a `title` and plain `text`, from House_Style_Suggestions.
	 * @param  string       $model Model ID.
	 * @return list<string>|WP_Error Proposed rules the user doesn't already have, or an error.
	 */
	public function suggest_house_rules( array $posts, string $model ): array|WP_Error {
		if ( array() === $posts ) {
			return new WP_Error(
				'house_style_no_posts',
				__( 'There are no published posts to suggest rules from yet.', 'fresh-eyes' ),
				array( 'status' => 400 )
			);
		}

		$existing           = House_Style::get_prompt_rules( get_current_user_id() );
		$prompt             = $this->prompt_builder->build_rule_suggestion_prompt( $posts, $existing );
		$system_instruction = $this->prompt_builder->get_rule_suggestion_system_instruction();

		if ( self::is_mock_mode() ) {
			Logger::debug( 'Using mock rule suggestions' );
			$ai_response = $this->get_mock_rule_suggestions( $posts, $prompt );
		} else {
			Logger::debug( sprintf( 'Calling AI model for rule suggestions: %s', $model ) );
			$ai_response = $this->call_ai_with_retry( $prompt, $system_instruction, $model );

			if ( is_wp_error( $ai_response ) ) {
				Logger::debug( sprintf( 'Rule suggestion request failed: %s', $ai_response->get_error_message() ) );
				return $this->is_service_unavailable_error( $ai_response )
					? $this->service_unavailable_error( $ai_response )
					: $ai_response;
			}
		}

		$rules = $this->response_parser->parse_rule_suggestions( $ai_response, $existing );
		if ( null === $rules ) {
			Logger::debug( 'Rule suggestions could not be read from the AI response' );
			return new WP_Error(
				'invalid_rule_suggestions',
				__( 'The AI response could not be read. Please try again.', 'fresh-eyes' ),
				array( 'status' => 502 )
			);
		}

		return $rules;
	}

	/**
	 * Get mock rule suggestions for testing.
	 *
	 * @param  array<mixed> $posts  Posts the rules are suggested from.
	 * @param  string       $prompt Prompt, for filters that answer from it.
	 * @return string Mock JSON response.
	 */
	private function get_mock_rule_suggestions( array $posts, string $prompt ): string {
		$response = array(
			'rules' => array( 'Headings use sentence case', 'Use the Oxford comma' ),
		);

		/**
		 * Filters the mock rule suggestions used when AI_FEEDBACK_MOCK_MODE is on.
		 *
		 * @param array<string, mixed> $response Mock response, with `rules`.
		 * @param array<mixed>         $posts    Posts the rules are suggested from.
		 * @param string               $prompt   Prompt.
		 */
		$response = apply_filters( 'ai_feedback_mock_rule_suggestions', $response, $posts, $prompt );

		return (string) wp_json_encode( $response );
	}

	/**
	 * Build the persona fields of a review result.
	 *
	 * @param  string $persona  Persona ID the review ran as.
	 * @param  mixed  $takeaway The persona's takeaway from the AI response.
	 * @return array{persona?: string, persona_label?: string, persona_takeaway?: string} Persona fields, empty for the default persona.
	 */
	private function build_persona_result( string $persona, mixed $takeaway ): array {
		if ( ! Personas::is_custom( $persona ) ) {
			return array();
		}

		return array(
			'persona'          => $persona,
			'persona_label'    => Personas::get_label( $persona ),
			'persona_takeaway' => Personas::sanitize_takeaway( $takeaway ),
		);
	}

	/**
	 * The provider and model that answered, for the review result.
	 *
	 * @return array{answered_by?: array{provider: string, model: string}} Empty when core didn't say.
	 */
	private function answered_by_result(): array {
		return null === $this->answered_by ? array() : array( 'answered_by' => $this->answered_by );
	}

	/**
	 * Whether an error looks like the provider refusing the request's input,
	 * such as images a model can't take, rather than an auth, billing, rate
	 * or availability failure, which a request without images would hit too.
	 *
	 * @param  WP_Error $error Error from the AI request.
	 * @return bool
	 */
	private static function is_refused_input( WP_Error $error ): bool {
		// The model wasn't found, so nothing was refused; a retry would fail the same way.
		if ( str_starts_with( (string) $error->get_error_code(), 'ai_feedback_' ) ) {
			return false;
		}
		$data   = $error->get_error_data();
		$status = is_array( $data ) && is_numeric( $data['status'] ?? null ) ? (int) $data['status'] : 0;
		if ( in_array( $status, array( 400, 413, 415, 422 ), true ) ) {
			return true;
		}
		// Without a status, only an error about the images themselves.
		$text = strtolower( $error->get_error_code() . ' ' . $error->get_error_message() );
		return 0 === $status && 1 === preg_match( '/\b(?:images?|vision|modalit(?:y|ies))\b/', $text );
	}

	/**
	 * Whether reviews use the built-in mock response.
	 *
	 * @return bool
	 */
	private static function is_mock_mode(): bool {
		return defined( 'AI_FEEDBACK_MOCK_MODE' ) && AI_FEEDBACK_MOCK_MODE;
	}

	/**
	 * Images to show the model so it can propose alt text, when it can see.
	 *
	 * Images are only sent to a model that reads them: given an image, core
	 * would otherwise switch to another model that does, or refuse the prompt.
	 * The model is only asked about once there are images to send, since the
	 * answer can need a request to the provider for its model list. That
	 * request uses the WordPress HTTP timeout (`http_request_timeout`, 5
	 * seconds by default), as core's `wp_ai_client_default_request_timeout`
	 * only applies to prompt requests.
	 *
	 * @param  array<mixed> $blocks Blocks being reviewed.
	 * @param  string       $model  Model ID.
	 * @return list<array{block_id: string, mime: string, data: string}> Images.
	 */
	protected function get_review_images( array $blocks, string $model ): array {
		$images = $this->collect_review_images( $blocks );
		if ( array() === $images || ! $this->model_supports_vision( $model ) ) {
			return array();
		}
		return $images;
	}

	/**
	 * Images from the blocks that could be sent, see Review_Images.
	 *
	 * @param  array<mixed> $blocks Blocks being reviewed.
	 * @return list<array{block_id: string, mime: string, data: string}> Images.
	 */
	protected function collect_review_images( array $blocks ): array {
		return ( new Review_Images() )->collect( $blocks );
	}

	/**
	 * Whether a model reads images, see Model_Capabilities.
	 *
	 * @param  string $model Model ID.
	 * @return bool
	 */
	protected function model_supports_vision( string $model ): bool {
		return ( new Model_Capabilities() )->supports_vision( $model );
	}

	/**
	 * Announce a completed review so history and other listeners can record it.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $result  Review result returned to the client.
	 */
	protected function fire_review_completed( int $post_id, array $result ): void {
		/**
		 * Fires after a review completes and its notes are created.
		 *
		 * @param int   $post_id Post ID.
		 * @param array $result  Review result returned to the client.
		 */
		do_action( 'ai_feedback_after_review', $post_id, $result );
	}

	/**
	 * Limit continuation feedback to the blocks in the current chunk.
	 *
	 * Document-level feedback, which has no block, goes with the first chunk.
	 *
	 * @param  array<mixed> $existing_feedback Feedback notes from earlier reviews.
	 * @param  array<mixed> $blocks            Blocks in this chunk.
	 * @param  bool         $is_first_chunk    Whether this is the first chunk.
	 * @return list<mixed> Feedback relevant to this chunk.
	 */
	private function filter_feedback_for_chunk( array $existing_feedback, array $blocks, bool $is_first_chunk ): array {
		$client_ids = array();
		foreach ( $blocks as $block ) {
			if ( is_array( $block ) && is_string( $block['clientId'] ?? null ) ) {
				$client_ids[ $block['clientId'] ] = true;
			}
		}

		return array_values(
			array_filter(
				$existing_feedback,
				static function ( $note ) use ( $client_ids, $is_first_chunk ): bool {
					$block_id = is_array( $note ) && is_string( $note['block_id'] ?? null ) ? $note['block_id'] : '';
					if ( '' === $block_id ) {
						return $is_first_chunk;
					}
					return isset( $client_ids[ $block_id ] );
				}
			)
		);
	}

	/**
	 * Call AI service via the WordPress 7.0 core AI Client.
	 *
	 * Raises core's AI request timeout for the duration of this call only.
	 *
	 * @param  string                                                    $prompt             User prompt.
	 * @param  string                                                    $system_instruction System instruction.
	 * @param  string                                                    $model              Model to use.
	 * @param  list<array{block_id: string, mime: string, data: string}> $images             Images to attach, in order, from Review_Images.
	 * @return string|WP_Error AI response or error.
	 */
	protected function call_ai( string $prompt, string $system_instruction, string $model, array $images = array() ): string|WP_Error {
		$this->answered_by = null;

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'ai_client_missing',
				__( 'The WordPress core AI Client is unavailable. WordPress 7.0 or higher is required.', 'fresh-eyes' )
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
		add_filter( 'wp_ai_client_default_request_timeout', $timeout_filter, PHP_INT_MAX );

		try {
			// Lower temperature keeps feedback consistent. Max tokens covers large
			// documents while staying within all supported models' limits.
			// Pinned to the chosen provider and model: core never picks another.
			$pinned = ( new Model_Pin() )->pin( wp_ai_client_prompt( $prompt ), $model );
			if ( is_wp_error( $pinned ) ) {
				return $pinned;
			}
			$builder = $pinned['builder'];
			// Inline data built here from local files, never a URL or path.
			foreach ( $images as $image ) {
				$builder = $builder->with_file( 'data:' . $image['mime'] . ';base64,' . $image['data'], $image['mime'] );
			}
			$result = Model_Pin::read_result(
				$builder
					->using_system_instruction( $system_instruction )
					->using_temperature( 0.3 )
					->using_max_tokens( 8000 )
					->generate_text_result(),
				$pinned
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$this->answered_by = $result['answered_by'];
			return $result['text'];
		} catch ( \Throwable $e ) {
			// Core converts SDK exceptions to WP_Error; this catches anything
			// else so the REST response is not a bare 500.
			Logger::debug( sprintf( 'AI request threw %s: %s', get_class( $e ), $e->getMessage() ) );
			return new WP_Error(
				'ai_request_failed',
				__( 'The AI request failed unexpectedly. Please try again.', 'fresh-eyes' ),
				array( 'status' => 500 )
			);
		} finally {
			remove_filter( 'wp_ai_client_default_request_timeout', $timeout_filter, PHP_INT_MAX );
		}
	}

	/**
	 * Call AI service with retry logic and exponential backoff.
	 *
	 * @param  string                                                    $prompt             User prompt.
	 * @param  string                                                    $system_instruction System instruction.
	 * @param  string                                                    $model              Model to use.
	 * @param  int                                                       $max_retries        Maximum number of retry attempts.
	 * @param  list<array{block_id: string, mime: string, data: string}> $images Images to attach, see call_ai().
	 * @return string|WP_Error AI response or error.
	 */
	protected function call_ai_with_retry(
		string $prompt,
		string $system_instruction,
		string $model,
		int $max_retries = 3,
		array $images = array()
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

			$response = $this->call_ai( $prompt, $system_instruction, $model, $images );

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
	 * Checks the error code, then the HTTP status core attaches to the
	 * error data, then message phrases for auth and billing failures.
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

		$data   = $error->get_error_data();
		$status = is_array( $data ) && is_numeric( $data['status'] ?? null ) ? (int) $data['status'] : 0;
		if ( in_array( $status, self::NON_RETRYABLE_STATUSES, true ) ) {
			return true;
		}

		$message = strtolower( (string) $error->get_error_message() );
		if ( '' === $message ) {
			return false;
		}

		/**
		 * Filters the message-keyword fallback used to classify errors as
		 * non-retryable when their code does not match.
		 *
		 * @param string[] $keywords Lowercase substrings to match.
		 */
		$keywords = (array) apply_filters(
			'ai_feedback_non_retryable_message_keywords',
			self::NON_RETRYABLE_MESSAGE_KEYWORDS
		);

		foreach ( $keywords as $keyword ) {
			if ( str_contains( $message, $keyword ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an AI Client error means the service is temporarily down.
	 *
	 * Network failures, provider 5xx errors and rate limits qualify, unless
	 * the error is one retrying cannot fix, such as an exhausted quota.
	 *
	 * @param  WP_Error $error The error returned after all retries.
	 * @return bool True when the review can be retried later.
	 */
	public function is_service_unavailable_error( WP_Error $error ): bool {
		if ( $this->is_non_retryable_error( $error ) ) {
			return false;
		}

		$code = (string) $error->get_error_code();
		if ( str_ends_with( $code, '_network_error' ) || str_ends_with( $code, '_upstream_server_error' ) ) {
			return true;
		}

		$data   = $error->get_error_data();
		$status = is_array( $data ) && is_numeric( $data['status'] ?? null ) ? (int) $data['status'] : 0;

		return in_array( $status, self::UNAVAILABLE_STATUSES, true );
	}

	/**
	 * Build the error returned when the AI service is temporarily down.
	 *
	 * Uses a stable code and a plain message so the editor can offer a retry
	 * without exposing provider details.
	 *
	 * @param  WP_Error $error The original AI Client error.
	 * @return WP_Error Error with code `ai_service_unavailable` and status 503.
	 */
	protected function service_unavailable_error( WP_Error $error ): WP_Error {
		/**
		 * Filters how many seconds the editor waits before retrying a review
		 * that failed because the AI service was unavailable.
		 *
		 * @param int      $retry_after Seconds to wait, between 5 and 300. Default 30.
		 * @param WP_Error $error       The original AI Client error.
		 */
		$retry_after = min( self::MAX_RETRY_AFTER, max( 5, (int) apply_filters( 'ai_feedback_retry_after', self::DEFAULT_RETRY_AFTER, $error ) ) );

		return new WP_Error(
			'ai_service_unavailable',
			__( 'The AI service is temporarily unavailable. Your content was not changed. Please try again shortly.', 'fresh-eyes' ),
			array(
				'status'        => 503,
				'retry_after'   => $retry_after,
				'original_code' => $error->get_error_code(),
			)
		);
	}

	/**
	 * Get mock AI response for testing.
	 *
	 * @param  array<mixed> $blocks  Document blocks with clientId, name, content.
	 * @param  string       $persona Persona ID the review runs as.
	 * @param  string       $prompt  Review prompt, for filters that answer from it.
	 * @return string Mock JSON response.
	 */
	private function get_mock_response( array $blocks, string $persona = Personas::DEFAULT_PERSONA, string $prompt = '' ): string {
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

			// Give tests a quick fix to work with.
			$content = is_array( $block ) && is_string( $block['content'] ?? null ) ? $block['content'] : '';
			if ( str_contains( $content, 'in order to' ) ) {
				$feedback[ $i ]['quick_fix'] = array(
					'find'    => 'in order to',
					'replace' => 'to',
				);
			}
		}

		// Build response with summary.
		$response = array(
			'summary'  => sprintf(
				'This review generated %d notes of constructive feedback. Overall, the document has a professional tone with clear structure. Key areas for improvement include content clarity and flow between sections.',
				count( $feedback )
			),
			'feedback' => $feedback,
		);

		if ( Personas::is_custom( $persona ) ) {
			$response['persona_takeaway'] = sprintf( 'Mock takeaway from the %s.', Personas::get_label( $persona ) );
		}

		/**
		 * Filters the mock review response used when AI_FEEDBACK_MOCK_MODE is on.
		 *
		 * Lets tests return feedback the built-in mock does not, such as
		 * structural edits.
		 *
		 * @param array<string, mixed> $response Mock response, with `summary` and `feedback`.
		 * @param array<mixed>         $blocks   Blocks being reviewed.
		 * @param string               $prompt   Review prompt.
		 */
		$response = apply_filters( 'ai_feedback_mock_review', $response, $blocks, $prompt );

		return (string) wp_json_encode( $response );
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
					__( 'Failed to create notes: %s', 'fresh-eyes' ),
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
				__( 'You have reached the maximum number of reviews per hour (10). Please try again later.', 'fresh-eyes' )
			);
		}

		// Add current review.
		$reviews[] = time();
		update_user_meta( $user_id, $rate_limit_key, $reviews );

		return true;
	}

	/**
	 * Give back the rate limit slot used by the user's latest review.
	 *
	 * Called when a review failed because the AI service was unavailable,
	 * so retrying does not use up the hourly limit. Refunds are capped at
	 * MAX_REFUNDS_PER_HOUR, so a failing provider still sees a bounded
	 * number of requests. Entries are plain timestamps, so removing the
	 * latest one is equivalent to removing this request's own entry.
	 *
	 * @param  int $user_id User ID.
	 * @return bool True when a slot was refunded.
	 */
	public function refund_rate_limit( int $user_id ): bool {
		$reviews = get_user_meta( $user_id, 'ai_feedback_reviews', true );

		if ( ! is_array( $reviews ) || empty( $reviews ) ) {
			return false;
		}

		$refunds      = get_user_meta( $user_id, 'ai_feedback_refunds', true );
		$one_hour_ago = time() - HOUR_IN_SECONDS;
		$refunds      = array_values(
			array_filter(
				is_array( $refunds ) ? $refunds : array(),
				static function ( $timestamp ) use ( $one_hour_ago ): bool {
					return is_int( $timestamp ) && $timestamp > $one_hour_ago;
				}
			)
		);

		if ( count( $refunds ) >= self::MAX_REFUNDS_PER_HOUR ) {
			return false;
		}

		array_pop( $reviews );
		update_user_meta( $user_id, 'ai_feedback_reviews', array_values( $reviews ) );

		$refunds[] = time();
		update_user_meta( $user_id, 'ai_feedback_refunds', $refunds );

		return true;
	}
}
