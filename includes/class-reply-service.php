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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reply Service class.
 */
class Reply_Service {

	/**
	 * HTTP timeout in seconds for reply generation.
	 *
	 * Replies are short, so this stays below the review timeout.
	 */
	public const AI_HTTP_TIMEOUT = 45;

	/**
	 * Maximum number of prior thread replies sent to the AI as context.
	 */
	public const MAX_THREAD_ENTRIES = 20;

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

		// A non-positive ID means persistence silently failed. Marking that
		// 'complete' would leave the frontend waiting on a reply that does not exist.
		if ( ! is_wp_error( $result ) && $result <= 0 ) {
			$result = new WP_Error( 'reply_not_saved', __( 'The AI reply could not be saved.', 'ai-feedback' ) );
		}

		if ( is_wp_error( $result ) ) {
			Logger::debug( sprintf( 'Reply_Service: reply %d failed: %s', $comment_id, $result->get_error_code() ) );
			update_comment_meta( $comment_id, Reply_Cron_Dispatcher::STATUS_META_KEY, 'failed' );
			update_comment_meta(
				$comment_id,
				Reply_Cron_Dispatcher::REPLY_ERROR_META_KEY,
				mb_substr( sanitize_text_field( $result->get_error_message() ), 0, 300 )
			);
			return;
		}

		update_comment_meta( $comment_id, Reply_Cron_Dispatcher::STATUS_META_KEY, 'complete' );
		update_comment_meta( $comment_id, Reply_Cron_Dispatcher::REPLY_COMMENT_ID_META_KEY, $result );
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
	public function handle_reply( int $comment_id, int $parent_id, int $post_id, string $block_id ): int|WP_Error {
		$context = $this->build_context( $comment_id, $parent_id, $post_id );
		if ( is_wp_error( $context ) ) {
			Logger::debug( 'Reply_Service: failed to build context: ' . $context->get_error_message() );
			return $context;
		}

		$prompt             = $this->prompt_builder->build_reply_prompt( $context );
		$system_instruction = $this->prompt_builder->get_reply_system_instruction();
		$model              = self::option_string( 'ai_feedback_default_model', 'claude-sonnet-4' );

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
	 * @param  int $post_id    Post ID - used to scope the siblings query.
	 * @return array<string, mixed>|WP_Error Context for Prompt_Builder::build_reply_prompt(), or error.
	 */
	private function build_context( int $comment_id, int $parent_id, int $post_id ): array|WP_Error {
		$reply = get_comment( $comment_id );
		if ( ! is_object( $reply ) ) {
			return new WP_Error( 'reply_not_found', 'Reply comment not found.' );
		}

		$parent = get_comment( $parent_id );
		if ( ! is_object( $parent ) ) {
			return new WP_Error( 'parent_not_found', 'Parent note not found.' );
		}

		// Fetch the most recent siblings, newest first, then restore
		// chronological order. The new reply is already persisted, so fetch one
		// extra and drop it to avoid echoing the user back to themselves.
		$siblings = get_comments(
			array(
				'parent'  => $parent_id,
				'post_id' => $post_id,
				'type'    => 'note',
				'status'  => 'all',
				'orderby' => array(
					'comment_date' => 'DESC',
					'comment_ID'   => 'DESC',
				),
				'number'  => self::MAX_THREAD_ENTRIES + 1,
			)
		);

		$thread = array();
		foreach ( is_array( $siblings ) ? array_reverse( $siblings ) : array() as $sibling ) {
			if ( ! is_object( $sibling ) || (int) $sibling->comment_ID === $comment_id ) {
				continue;
			}
			$thread[] = array(
				'author'  => $sibling->comment_author,
				'content' => $sibling->comment_content,
				'is_ai'   => (bool) get_comment_meta( (int) $sibling->comment_ID, 'ai_feedback', true ),
			);
		}
		$thread = array_slice( $thread, -self::MAX_THREAD_ENTRIES );

		return array(
			'block_content'     => '', // Future enhancement: derive from post_content.
			'block_type'        => self::meta_string( $parent_id, 'block_name' ),
			'original_feedback' => $parent->comment_content,
			'original_category' => self::meta_string( $parent_id, 'feedback_category' ),
			'original_severity' => self::meta_string( $parent_id, 'feedback_severity' ),
			'thread'            => $thread,
			'user_reply'        => $reply->comment_content,
			'target_tone'       => self::option_string( 'ai_feedback_default_tone', 'professional' ),
			'locale'            => self::option_string( 'ai_feedback_feedback_locale', get_locale() ),
		);
	}

	/**
	 * Read a string comment meta value.
	 *
	 * @param int    $comment_id Comment ID.
	 * @param string $key        Meta key.
	 * @return string Meta value, or '' when missing or not a string.
	 */
	private static function meta_string( int $comment_id, string $key ): string {
		$value = get_comment_meta( $comment_id, $key, true );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Read a string option value.
	 *
	 * @param string $name          Option name.
	 * @param string $default_value Value used when the option is missing or not a string.
	 * @return string
	 */
	private static function option_string( string $name, string $default_value ): string {
		$value = get_option( $name, $default_value );
		return is_string( $value ) && '' !== $value ? $value : $default_value;
	}

	/**
	 * Call the WordPress 7.0 core AI Client to generate the reply text.
	 *
	 * Protected so tests can subclass and override.
	 *
	 * @param  string $prompt             User prompt.
	 * @param  string $system_instruction System instruction.
	 * @param  string $model              Model preference.
	 * @return string|WP_Error AI reply text or error.
	 */
	protected function call_ai( string $prompt, string $system_instruction, string $model ): string|WP_Error {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'ai_client_missing',
				__( 'The WordPress core AI Client is unavailable. WordPress 7.0 or higher is required.', 'ai-feedback' )
			);
		}

		$timeout_filter = static function (): int {
			return self::AI_HTTP_TIMEOUT;
		};
		add_filter( 'wp_ai_client_default_request_timeout', $timeout_filter, PHP_INT_MAX );

		try {
			return wp_ai_client_prompt( $prompt )
				->using_system_instruction( $system_instruction )
				->using_model_preference( $model )
				->using_temperature( 0.4 )
				->using_max_tokens( 400 )
				->generate_text();
		} catch ( \Throwable $e ) {
			// Core converts SDK exceptions to WP_Error; this catches anything else.
			Logger::debug( sprintf( 'Reply_Service: AI request threw %s: %s', get_class( $e ), $e->getMessage() ) );
			return new WP_Error(
				'ai_request_failed',
				__( 'The AI reply request failed unexpectedly. Please try again.', 'ai-feedback' )
			);
		} finally {
			remove_filter( 'wp_ai_client_default_request_timeout', $timeout_filter, PHP_INT_MAX );
		}
	}
}
