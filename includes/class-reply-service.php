<?php
/**
 * Reply Service
 *
 * Listens for user replies to AI Feedback notes and generates an AI
 * response in the same thread.
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

use WP_Error;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reply Service class.
 */
class Reply_Service {

	/**
	 * Prompt builder instance.
	 *
	 * @var Prompt_Builder
	 */
	private Prompt_Builder $prompt_builder;

	/**
	 * Notes manager instance.
	 *
	 * @var Notes_Manager
	 */
	private Notes_Manager $notes_manager;

	/**
	 * Constructor.
	 *
	 * @param Prompt_Builder|null $prompt_builder Optional prompt builder (for testing).
	 * @param Notes_Manager|null  $notes_manager  Optional notes manager (for testing).
	 */
	public function __construct( ?Prompt_Builder $prompt_builder = null, ?Notes_Manager $notes_manager = null ) {
		$this->prompt_builder = $prompt_builder ?? new Prompt_Builder();
		$this->notes_manager  = $notes_manager ?? new Notes_Manager();
	}

	/**
	 * Register action listener.
	 */
	public function register(): void {
		add_action(
			Reply_Cron_Dispatcher::CRON_HOOK,
			array( $this, 'on_reply_received' ),
			10,
			4
		);
	}

	/**
	 * Cron callback for Reply_Cron_Dispatcher::CRON_HOOK.
	 *
	 * Wraps handle_reply() to satisfy the void-return contract that
	 * WordPress actions require; handle_reply() returns a value for
	 * direct testability. Also records final status on the reply comment
	 * so the frontend can poll for completion.
	 *
	 * @param  int    $comment_id Reply comment ID.
	 * @param  int    $parent_id  Parent (original AI note) comment ID.
	 * @param  int    $post_id    Post ID.
	 * @param  string $block_id   Block ID from the parent note.
	 */
	public function on_reply_received( int $comment_id, int $parent_id, int $post_id, string $block_id ): void {
		$result = $this->handle_reply( $comment_id, $parent_id, $post_id, $block_id );

		if ( is_wp_error( $result ) ) {
			update_comment_meta( $comment_id, Reply_Cron_Dispatcher::STATUS_META_KEY, 'failed' );
			update_comment_meta(
				$comment_id,
				'ai_feedback_reply_error',
				sanitize_text_field( $result->get_error_message() )
			);
			return;
		}

		$reply_id = (int) $result;

		// A non-error, non-positive return from handle_reply means persistence
		// silently failed (e.g. wp_insert_comment returned 0 or false). Marking
		// it 'complete' would leave the frontend polling indefinitely on a
		// non-existent reply, so route it through the failed branch instead.
		if ( $reply_id <= 0 ) {
			update_comment_meta( $comment_id, Reply_Cron_Dispatcher::STATUS_META_KEY, 'failed' );
			update_comment_meta(
				$comment_id,
				'ai_feedback_reply_error',
				__( 'Reply persistence returned no comment ID.', 'ai-feedback' )
			);
			return;
		}

		update_comment_meta( $comment_id, Reply_Cron_Dispatcher::STATUS_META_KEY, 'complete' );
		update_comment_meta( $comment_id, 'ai_feedback_reply_comment_id', $reply_id );
	}

	/**
	 * Handle a detected reply: build context, call AI, persist response.
	 *
	 * @param  int    $comment_id Reply comment ID.
	 * @param  int    $parent_id  Parent (original AI note) comment ID.
	 * @param  int    $post_id    Post ID.
	 * @param  string $block_id   Block ID from the parent note.
	 * @return int|WP_Error New AI reply comment ID on success, WP_Error on failure.
	 */
	public function handle_reply( int $comment_id, int $parent_id, int $post_id, string $block_id ) {
		$context = $this->build_context( $comment_id, $parent_id, $post_id );
		if ( is_wp_error( $context ) ) {
			Logger::debug( 'Reply_Service: failed to build context: ' . $context->get_error_message() );
			return $context;
		}

		$prompt             = $this->prompt_builder->build_reply_prompt( $context );
		$system_instruction = $this->prompt_builder->get_reply_system_instruction();
		$model              = (string) get_option( 'ai_feedback_default_model', 'claude-sonnet-4' );

		$ai_text = $this->call_ai( $prompt, $system_instruction, $model );
		if ( is_wp_error( $ai_text ) ) {
			Logger::debug( 'Reply_Service: AI call failed: ' . $ai_text->get_error_message() );
			return $ai_text;
		}

		$feedback_item = array(
			'feedback' => $ai_text,
			'block_id' => $block_id,
		);
		$review_data   = array(
			'review_id' => 'reply-' . $comment_id,
			'post_id'   => $post_id,
			'model'     => $model,
			'timestamp' => current_time( 'mysql' ),
		);

		return $this->notes_manager->add_reply_to_thread( $feedback_item, $post_id, $parent_id, $review_data );
	}

	/**
	 * Build the reply context array from a parent note and its thread.
	 *
	 * @param  int $comment_id Reply comment ID.
	 * @param  int $parent_id  Parent (original AI note) comment ID.
	 * @param  int $post_id    Post ID — used to scope the siblings query.
	 * @return array|WP_Error Context array for Prompt_Builder::build_reply_prompt(), or error.
	 */
	private function build_context( int $comment_id, int $parent_id, int $post_id ) {
		$reply = get_comment( $comment_id );
		if ( ! $reply ) {
			return new WP_Error( 'reply_not_found', 'Reply comment not found.' );
		}

		$parent = get_comment( $parent_id );
		if ( ! $parent ) {
			return new WP_Error( 'parent_not_found', 'Parent note not found.' );
		}

		// Fetch all siblings of the reply (chronological). The new reply is
		// already persisted at this point, so it will be included — drop it
		// from the thread history to avoid echoing the user back to themselves.
		$siblings = get_comments(
			array(
				'parent'  => $parent_id,
				'post_id' => $post_id,
				'type'    => 'note',
				'status'  => 'all',
				'orderby' => 'comment_date',
				'order'   => 'ASC',
			)
		);

		$thread = array();
		foreach ( $siblings as $sibling ) {
			if ( (int) $sibling->comment_ID === $comment_id ) {
				continue;
			}
			$thread[] = array(
				'author'  => (string) $sibling->comment_author,
				'content' => (string) $sibling->comment_content,
				'is_ai'   => (bool) get_comment_meta( (int) $sibling->comment_ID, 'ai_feedback', true ),
			);
		}

		return array(
			'block_content'     => '', // Future enhancement: derive from post_content.
			'block_type'        => (string) get_comment_meta( $parent_id, 'block_name', true ),
			'original_feedback' => (string) $parent->comment_content,
			'original_category' => (string) get_comment_meta( $parent_id, 'feedback_category', true ),
			'original_severity' => (string) get_comment_meta( $parent_id, 'feedback_severity', true ),
			'thread'            => $thread,
			'user_reply'        => (string) $reply->comment_content,
			'target_tone'       => (string) get_option( 'ai_feedback_default_tone', 'professional' ),
			'locale'            => (string) get_option( 'ai_feedback_feedback_locale', get_locale() ),
		);
	}

	/**
	 * Call the AI client to generate the reply text.
	 *
	 * Protected so tests can subclass and override.
	 *
	 * @param  string $prompt             User prompt.
	 * @param  string $system_instruction System instruction.
	 * @param  string $model              Model preference.
	 * @return string|WP_Error AI reply text or error.
	 */
	protected function call_ai( string $prompt, string $system_instruction, string $model ) {
		if ( ! class_exists( AiClient::class ) ) {
			return new WP_Error(
				'ai_client_missing',
				__( 'PHP AI Client library is not installed.', 'ai-feedback' )
			);
		}

		try {
			$options = new RequestOptions();
			$options->setTimeout( 45.0 );

			return AiClient::prompt( $prompt )
				->usingSystemInstruction( $system_instruction )
				->usingModelPreference( $model )
				->usingTemperature( 0.4 )
				->usingMaxTokens( 400 )
				->usingRequestOptions( $options )
				->generateText();
		} catch ( \Exception $e ) {
			return new WP_Error(
				'ai_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'AI reply request failed: %s', 'ai-feedback' ),
					$e->getMessage()
				)
			);
		}
	}
}
