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
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use AI_Feedback\Logger;

/**
 * Review Service class.
 */
class Review_Service {


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
	 * @param  array $options Review options including 'blocks' array.
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

		// Build prompt.
		$prompt             = $this->prompt_builder->build_review_prompt( $blocks, $options );
		$system_instruction = $this->prompt_builder->get_system_instruction();

		// Get AI model.
		$model = $options['model'] ?? 'claude-sonnet-4-20250514';

		Logger::debug( sprintf( 'Calling AI model: %s', $model ) );

		// Check if using mock mode.
		if ( defined( 'AI_FEEDBACK_MOCK_MODE' ) && AI_FEEDBACK_MOCK_MODE ) {
			$ai_response = $this->get_mock_response( $blocks );
			Logger::debug( 'Using mock response mode' );
		} else {
			// Call AI.
			$ai_response = $this->call_ai( $prompt, $system_instruction, $model );

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
		$note_result = $this->notes_manager->create_notes_from_feedback(
			$feedback_items,
			$post_id,
			$review_data
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
	 * Call AI service.
	 *
	 * @param  string $prompt             User prompt.
	 * @param  string $system_instruction System instruction.
	 * @param  string $model              Model to use.
	 * @return string|WP_Error AI response or error.
	 */
	private function call_ai( string $prompt, string $system_instruction, string $model ): string|WP_Error {
		// Check if PHP AI Client is available.
		if ( ! class_exists( 'WordPress\AiClient\AiClient' ) ) {
			return new WP_Error(
				'ai_client_missing',
				__( 'PHP AI Client library is not installed. Please run: composer install', 'ai-feedback' )
			);
		}

		try {
			// Create request options with timeout.
			$request_options = new RequestOptions();
			$request_options->setTimeout( 60.0 ); // 60 second timeout.

			// Call AI using WordPress PHP AI Client.
			$response = AiClient::prompt( $prompt )
				->usingSystemInstruction( $system_instruction )
				->usingModelPreference( $model )
				->usingTemperature( 0.3 ) // Lower temperature for consistent feedback.
				->usingMaxTokens( 8000 ) // Sufficient for large documents; within limits of all supported models.
				->usingRequestOptions( $request_options )
				->generateText();

			return $response;

		} catch ( \Exception $e ) {
			return new WP_Error(
				'ai_request_failed',
				sprintf(
				/* translators: %s: error message */
					__( 'AI request failed: %s', 'ai-feedback' ),
					$e->getMessage()
				)
			);
		}
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
