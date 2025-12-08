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
	 * @param int   $post_id Post ID to review.
	 * @param array $options Review options.
	 * @return array|WP_Error Review results or error.
	 */
	public function review_document( int $post_id, array $options = array() ): array|WP_Error {
		// Get post.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error(
				'invalid_post',
				__( 'Post not found.', 'ai-feedback' )
			);
		}

		// Parse blocks from content.
		$blocks = parse_blocks( $post->post_content );

		// Filter out empty blocks.
		$blocks = $this->filter_blocks( $blocks );

		if ( empty( $blocks ) ) {
			return new WP_Error(
				'no_content',
				__( 'Post has no content to review.', 'ai-feedback' )
			);
		}

		// Validate block count.
		if ( count( $blocks ) > 100 ) {
			return new WP_Error(
				'too_many_blocks',
				__( 'Post has too many blocks (max 100). Please split into smaller posts.', 'ai-feedback' )
			);
		}

		// Add post title to options.
		$options['post_title'] = $post->post_title;

		// Build prompt.
		$prompt             = $this->prompt_builder->build_review_prompt( $blocks, $options );
		$system_instruction = $this->prompt_builder->get_system_instruction();

		// Get AI model.
		$model = $options['model'] ?? 'claude-sonnet-4-20250514';

		// Check if using mock mode.
		if ( defined( 'AI_FEEDBACK_MOCK_MODE' ) && AI_FEEDBACK_MOCK_MODE ) {
			$ai_response = $this->get_mock_response( $blocks );
		} else {
			// Call AI.
			$ai_response = $this->call_ai( $prompt, $system_instruction, $model );

			if ( is_wp_error( $ai_response ) ) {
				return $ai_response;
			}
		}

		// Parse response.
		$feedback_items = $this->response_parser->parse_feedback( $ai_response, $blocks );

		if ( empty( $feedback_items ) ) {
			return new WP_Error(
				'parse_error',
				__( 'Failed to parse AI response. Please try again.', 'ai-feedback' )
			);
		}

		// Get summary.
		$summary = $this->response_parser->get_feedback_summary( $feedback_items );

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
		$note_ids = $this->notes_manager->create_notes_from_feedback(
			$feedback_items,
			$post_id,
			$review_data
		);

		// Check if note creation failed.
		if ( is_wp_error( $note_ids ) ) {
			// Still return the feedback data, but include error.
			return array(
				'review_id'       => $review_id,
				'post_id'         => $post_id,
				'model'           => $model,
				'notes'           => $feedback_items,
				'summary'         => $summary,
				'timestamp'       => $review_data['timestamp'],
				'note_ids'        => array(),
				'notes_error'     => $note_ids->get_error_message(),
			);
		}

		// Build response with note IDs.
		return array(
			'review_id' => $review_id,
			'post_id'   => $post_id,
			'model'     => $model,
			'notes'     => $feedback_items,
			'note_ids'  => $note_ids,
			'summary'   => $summary,
			'timestamp' => $review_data['timestamp'],
		);
	}

	/**
	 * Filter blocks to remove empty and invalid blocks.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array Filtered blocks.
	 */
	private function filter_blocks( array $blocks ): array {
		$filtered = array();

		foreach ( $blocks as $block ) {
			// Skip null blocks.
			if ( ! $block['blockName'] ) {
				continue;
			}

			// Skip empty blocks.
			$content = $block['innerHTML'] ?? '';
			if ( empty( trim( wp_strip_all_tags( $content ) ) ) ) {
				continue;
			}

			$filtered[] = $block;
		}

		// Re-index array.
		return array_values( $filtered );
	}

	/**
	 * Call AI service.
	 *
	 * @param string $prompt             User prompt.
	 * @param string $system_instruction System instruction.
	 * @param string $model              Model to use.
	 * @return string|WP_Error AI response or error.
	 */
	private function call_ai( string $prompt, string $system_instruction, string $model ): string|WP_Error {
		// Check if PHP AI Client is available.
		if ( ! class_exists( 'Jelix\AI\AiClient' ) ) {
			return new WP_Error(
				'ai_client_missing',
				__( 'PHP AI Client library is not installed. Please run: composer install', 'ai-feedback' )
			);
		}

		try {
			// Call AI using PHP AI Client.
			$response = \Jelix\AI\AiClient::prompt( $prompt )
				->usingSystemInstruction( $system_instruction )
				->usingModel( $model )
				->usingTemperature( 0.3 ) // Lower temperature for consistent feedback.
				->withMaxTokens( 2000 ) // Enough for detailed feedback.
				->withTimeout( 60 ) // 60 second timeout.
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
	 * @param array $blocks Document blocks.
	 * @return string Mock JSON response.
	 */
	private function get_mock_response( array $blocks ): string {
		$feedback = array();

		// Generate mock feedback for first 3 blocks.
		$mock_count = min( 3, count( $blocks ) );

		for ( $i = 0; $i < $mock_count; $i++ ) {
			$feedback[] = array(
				'block_index' => $i,
				'category'    => array( 'content', 'tone', 'flow' )[ $i % 3 ],
				'severity'    => array( 'suggestion', 'important' )[ $i % 2 ],
				'title'       => 'Mock feedback item ' . ( $i + 1 ),
				'feedback'    => 'This is mock feedback for testing purposes. The AI integration is working correctly.',
				'suggestion'  => 'Consider this mock suggestion for improvement.',
			);
		}

		return wp_json_encode( $feedback );
	}

	/**
	 * Check rate limit for user.
	 *
	 * @param int $user_id User ID.
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
		$reviews      = array_filter( $reviews, function ( $timestamp ) use ( $one_hour_ago ) {
			return $timestamp > $one_hour_ago;
		} );

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
