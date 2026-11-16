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
	 * PHP time limit in seconds for a request that answers a reply.
	 *
	 * Above AI_HTTP_TIMEOUT so the AI call can finish, and below
	 * Reply_Cron_Dispatcher::CLAIM_TIMEOUT so a retry only takes over a
	 * request that PHP has already stopped.
	 */
	public const REQUEST_TIME_LIMIT = 75;

	/**
	 * Sampling temperature for replies. Slightly above reviews for a conversational tone.
	 */
	public const AI_TEMPERATURE = 0.4;

	/**
	 * Token cap for replies, enough for the requested 2-3 sentences.
	 */
	public const AI_MAX_TOKENS = 400;

	/**
	 * Maximum number of prior thread replies sent to the AI as context.
	 */
	public const MAX_THREAD_ENTRIES = 20;

	/**
	 * Reply returned instead of calling the AI when AI_FEEDBACK_MOCK_MODE is on.
	 */
	public const MOCK_REPLY = 'This is a mock AI reply for testing purposes. The conversation loop is working correctly.';

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
	 * Claim token this request wrote, or null when it holds no claim.
	 *
	 * @var string|null
	 */
	private ?string $claim_token = null;

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
	 * The fallback for replies the editor did not answer through process(),
	 * such as replies posted outside the block editor. Skips a reply that a
	 * request already claimed.
	 *
	 * @param  int    $comment_id Reply comment ID.
	 * @param  int    $parent_id  Parent (original AI note) comment ID.
	 * @param  int    $post_id    Post ID.
	 * @param  string $block_id   Block ID from the parent note.
	 */
	public function on_reply_received( int $comment_id, int $parent_id, int $post_id, string $block_id ): void {
		if ( ! $this->claim( $comment_id, false ) ) {
			return;
		}

		$this->generate( $comment_id, $parent_id, $post_id, $block_id );
	}

	/**
	 * Answer a queued reply within the current request.
	 *
	 * Lets the editor get an answer on sites where WP-Cron does not run. The
	 * fallback cron event is dropped once the reply is claimed.
	 *
	 * @param int  $comment_id Reply comment ID.
	 * @param bool $retry      Whether to also take over a failed reply or a stale claim.
	 * @return bool Whether this request answered the reply.
	 */
	public function process( int $comment_id, bool $retry = false ): bool {
		$job = $this->get_job( $comment_id );
		if ( null === $job ) {
			return false;
		}

		list( $parent_id, $post_id, $block_id ) = $job;

		if ( ! $this->claim( $comment_id, $retry ) ) {
			return false;
		}

		$args      = array( $comment_id, $parent_id, $post_id, $block_id );
		$timestamp = wp_next_scheduled( Reply_Cron_Dispatcher::CRON_HOOK, $args );
		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, Reply_Cron_Dispatcher::CRON_HOOK, $args );
		}

		$this->generate( $comment_id, $parent_id, $post_id, $block_id );
		return true;
	}

	/**
	 * Get the stored job for a queued reply.
	 *
	 * @param int $comment_id Reply comment ID.
	 * @return array{int, int, string}|null Parent ID, post ID and block ID, or null when the reply was never queued.
	 */
	private function get_job( int $comment_id ): ?array {
		$job = get_comment_meta( $comment_id, Reply_Cron_Dispatcher::JOB_META_KEY, true );
		if ( ! is_array( $job ) ) {
			return $this->rebuild_job( $comment_id );
		}
		if ( 3 !== count( $job ) ) {
			return null;
		}

		list( $parent_id, $post_id, $block_id ) = array_values( $job );
		if ( ! is_numeric( $parent_id ) || ! is_numeric( $post_id ) || ! is_string( $block_id ) ) {
			return null;
		}

		return array( (int) $parent_id, (int) $post_id, $block_id );
	}

	/**
	 * Rebuild the job for a reply queued before the job was stored with it.
	 *
	 * Only a pending or failed user reply in an AI note's thread qualifies,
	 * matching what Reply_Detector would have queued.
	 *
	 * @param int $comment_id Reply comment ID.
	 * @return array{int, int, string}|null Parent ID, post ID and block ID, or null.
	 */
	private function rebuild_job( int $comment_id ): ?array {
		$status = self::meta_string( $comment_id, Reply_Cron_Dispatcher::STATUS_META_KEY );
		if ( 'pending' !== $status && 'failed' !== $status ) {
			return null;
		}

		$reply = get_comment( $comment_id );
		if ( ! is_object( $reply ) || get_comment_meta( $comment_id, 'ai_feedback', true ) ) {
			return null;
		}

		$direct_parent = is_numeric( $reply->comment_parent ) ? (int) $reply->comment_parent : 0;
		$post_id       = is_numeric( $reply->comment_post_ID ) ? (int) $reply->comment_post_ID : 0;
		if ( $direct_parent <= 0 || $post_id <= 0 ) {
			return null;
		}

		$parent_id = Reply_Detector::find_thread_root( $direct_parent );
		if ( ! get_comment_meta( $parent_id, 'ai_feedback', true ) ) {
			return null;
		}

		$block_id = sanitize_text_field( self::meta_string( $parent_id, 'block_id' ) );
		if ( '' === $block_id ) {
			return null;
		}

		return array( $parent_id, $post_id, $block_id );
	}

	/**
	 * Claim a reply so only one request answers it.
	 *
	 * Moves the status from 'pending' to 'processing' with a compare-and-set
	 * update, which only one concurrent request can win. A retry may also
	 * claim a failed reply once Reply_Cron_Dispatcher::RETRY_COOLDOWN has
	 * passed, or take over a stale claim. Every claim then swaps in a new
	 * unique token with a compare-and-set, so of two racing requests only
	 * one ends up owning the reply.
	 *
	 * @param int  $comment_id Reply comment ID.
	 * @param bool $retry      Whether the user asked to retry.
	 * @return bool Whether this request now owns the reply.
	 */
	private function claim( int $comment_id, bool $retry ): bool {
		// Read the token first: a racing claim writes its claim time before its token.
		$previous_token = get_comment_meta( $comment_id, Reply_Cron_Dispatcher::CLAIM_TOKEN_META_KEY, true );
		$status         = self::meta_string( $comment_id, Reply_Cron_Dispatcher::STATUS_META_KEY );
		$claimed_at     = get_comment_meta( $comment_id, Reply_Cron_Dispatcher::CLAIMED_AT_META_KEY, true );
		$now            = time();

		if ( 'pending' === $status || ( $retry && 'failed' === $status ) ) {
			if ( 'failed' === $status && is_numeric( $claimed_at ) && $now - (int) $claimed_at < Reply_Cron_Dispatcher::RETRY_COOLDOWN ) {
				return false;
			}
			if ( ! update_comment_meta( $comment_id, Reply_Cron_Dispatcher::STATUS_META_KEY, 'processing', $status ) ) {
				return false;
			}
		} elseif ( ! $retry || 'processing' !== $status || ! self::is_stale_claim( $comment_id, $claimed_at, $now ) ) {
			return false;
		}

		update_comment_meta( $comment_id, Reply_Cron_Dispatcher::CLAIMED_AT_META_KEY, $now );

		$token = wp_generate_uuid4();
		$taken = '' === $previous_token
			? add_comment_meta( $comment_id, Reply_Cron_Dispatcher::CLAIM_TOKEN_META_KEY, $token, true )
			: update_comment_meta( $comment_id, Reply_Cron_Dispatcher::CLAIM_TOKEN_META_KEY, $token, $previous_token );
		if ( false === $taken ) {
			// Another request claimed the reply first.
			return false;
		}

		$this->claim_token = $token;
		// Restart the timeout: a reply can sit pending past it before a request claims it.
		update_comment_meta( $comment_id, Reply_Cron_Dispatcher::PENDING_SINCE_META_KEY, $now );
		if ( $retry ) {
			// Clear the last error for the new attempt.
			delete_comment_meta( $comment_id, Reply_Cron_Dispatcher::REPLY_ERROR_META_KEY );
		}

		return true;
	}

	/**
	 * Whether a 'processing' claim is old enough for a retry to take over.
	 *
	 * Without a valid claim time, for a request that died mid-claim, falls
	 * back to when the reply was queued so a fresh claim is never taken.
	 *
	 * @param int   $comment_id Reply comment ID.
	 * @param mixed $claimed_at Claim time meta value.
	 * @param int   $now        Current Unix timestamp.
	 * @return bool
	 */
	private static function is_stale_claim( int $comment_id, mixed $claimed_at, int $now ): bool {
		if ( ! is_numeric( $claimed_at ) ) {
			$claimed_at = get_comment_meta( $comment_id, Reply_Cron_Dispatcher::PENDING_SINCE_META_KEY, true );
		}

		return is_numeric( $claimed_at ) && $now - (int) $claimed_at > Reply_Cron_Dispatcher::CLAIM_TIMEOUT;
	}

	/**
	 * Whether this request still owns its claim on a reply.
	 *
	 * A retry can take over a slow request's claim. Without this check both
	 * requests would save an answer.
	 *
	 * @param int $comment_id Reply comment ID.
	 * @return bool True when the claim is still ours, or no claim was made.
	 */
	private function owns_claim( int $comment_id ): bool {
		if ( null === $this->claim_token ) {
			return true;
		}

		// Another request may have written the meta since this one cached it.
		wp_cache_delete( $comment_id, 'comment_meta' );

		return 'processing' === self::meta_string( $comment_id, Reply_Cron_Dispatcher::STATUS_META_KEY )
			&& self::meta_string( $comment_id, Reply_Cron_Dispatcher::CLAIM_TOKEN_META_KEY ) === $this->claim_token;
	}

	/**
	 * Generate the AI answer for a claimed reply and record the result.
	 *
	 * Wraps handle_reply() and records the final status on the reply comment
	 * so the frontend can poll for completion.
	 *
	 * @param  int    $comment_id Reply comment ID.
	 * @param  int    $parent_id  Parent (original AI note) comment ID.
	 * @param  int    $post_id    Post ID.
	 * @param  string $block_id   Block ID from the parent note.
	 */
	private function generate( int $comment_id, int $parent_id, int $post_id, string $block_id ): void {
		try {
			$result = $this->handle_reply( $comment_id, $parent_id, $post_id, $block_id );
		} catch ( \Throwable $e ) {
			// Record a terminal state so the frontend stops waiting.
			Logger::debug( sprintf( 'Reply_Service: reply %d threw %s: %s', $comment_id, get_class( $e ), $e->getMessage() ) );
			$result = new WP_Error( 'reply_failed', __( 'The AI reply failed unexpectedly.', 'copydesk-ai' ) );
		} finally {
			$this->claim_token = null;
		}

		if ( is_wp_error( $result ) && 'reply_claim_lost' === $result->get_error_code() ) {
			// The request that took over records the result.
			Logger::debug( sprintf( 'Reply_Service: reply %d was taken over, discarding this answer', $comment_id ) );
			return;
		}

		// A non-positive ID means persistence silently failed. Marking that
		// 'complete' would leave the frontend waiting on a reply that does not exist.
		if ( ! is_wp_error( $result ) && $result <= 0 ) {
			$result = new WP_Error( 'reply_not_saved', __( 'The AI reply could not be saved.', 'copydesk-ai' ) );
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
		$model              = self::option_string( 'ai_feedback_default_model', AI_FEEDBACK_DEFAULT_MODEL );

		$ai_text = $this->call_ai( $prompt, $system_instruction, $model );
		if ( is_wp_error( $ai_text ) ) {
			Logger::debug( 'Reply_Service: AI call failed: ' . $ai_text->get_error_message() );
			return $ai_text;
		}

		if ( '' === trim( $ai_text ) ) {
			Logger::debug( 'Reply_Service: AI returned an empty reply.' );
			return new WP_Error( 'ai_empty_reply', __( 'The AI returned an empty reply.', 'copydesk-ai' ) );
		}

		if ( ! $this->owns_claim( $comment_id ) ) {
			return new WP_Error( 'reply_claim_lost', __( 'Another request is answering this reply.', 'copydesk-ai' ) );
		}

		$feedback_item = array(
			'feedback' => $ai_text,
			'block_id' => $block_id,
		);
		$review_data   = array(
			'review_id' => 'reply-' . $comment_id,
			'post_id'   => $post_id,
			'model'     => $model,
			'timestamp' => current_time( 'c', true ),
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
		foreach ( array_reverse( $siblings ) as $sibling ) {
			if ( (int) $sibling->comment_ID === $comment_id ) {
				continue;
			}
			$thread[] = array(
				'author'  => $sibling->comment_author,
				'content' => $sibling->comment_content,
				'is_ai'   => (bool) get_comment_meta( (int) $sibling->comment_ID, 'ai_feedback', true ),
			);
		}
		$thread = array_slice( $thread, -self::MAX_THREAD_ENTRIES );

		$block      = $this->find_note_block( $post_id, $parent_id );
		$block_type = self::meta_string( $parent_id, 'block_name' );
		if ( '' === $block_type && null !== $block && is_string( $block['blockName'] ?? null ) ) {
			$block_type = $block['blockName'];
		}

		return array(
			'block_content'     => null !== $block ? self::block_text( $block ) : '',
			'block_is_text'     => true,
			'block_type'        => $block_type,
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
	 * Find the block a note is attached to in the saved post content.
	 *
	 * The editor links a block to its note thread through the block's
	 * `metadata.noteId` attribute, which holds the thread's root note ID.
	 *
	 * @param int $post_id Post ID.
	 * @param int $note_id Root note ID.
	 * @return array<mixed>|null Parsed block, or null when the post or block is missing.
	 */
	private function find_note_block( int $post_id, int $note_id ): ?array {
		$post = get_post( $post_id );
		if ( null === $post || '' === $post->post_content ) {
			return null;
		}

		return self::search_blocks( parse_blocks( $post->post_content ), $note_id );
	}

	/**
	 * Depth-first search for the block whose `metadata.noteId` matches.
	 *
	 * @param array<mixed> $blocks  Parsed blocks.
	 * @param int          $note_id Root note ID.
	 * @return array<mixed>|null Matching block, or null.
	 */
	private static function search_blocks( array $blocks, int $note_id ): ?array {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$attrs    = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$metadata = is_array( $attrs['metadata'] ?? null ) ? $attrs['metadata'] : array();
			if ( self::links_note( $metadata['noteId'] ?? null, $note_id ) ) {
				return $block;
			}

			if ( is_array( $block['innerBlocks'] ?? null ) ) {
				$found = self::search_blocks( $block['innerBlocks'], $note_id );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Whether a block's `metadata.noteId` value includes a note ID.
	 *
	 * The editor stores a single thread as a scalar and several threads as an
	 * array, and either form may hold numeric strings.
	 *
	 * @param mixed $linked  Raw `metadata.noteId` value.
	 * @param int   $note_id Root note ID.
	 * @return bool
	 */
	private static function links_note( mixed $linked, int $note_id ): bool {
		foreach ( is_array( $linked ) ? $linked : array( $linked ) as $id ) {
			if ( is_numeric( $id ) && (int) $id === $note_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get a parsed block's plain text with entities decoded and whitespace collapsed.
	 *
	 * With the `u` flag, `\s` also matches the non-breaking spaces that
	 * `&nbsp;` decodes to.
	 *
	 * @param array<mixed> $block Parsed block.
	 * @return string Block text.
	 */
	private static function block_text( array $block ): string {
		// Keep a break between cells, lines and list items once tags are stripped.
		$html = (string) preg_replace( '#<(?:br|/(?:p|li|td|th|tr|h[1-6]|div|figcaption|blockquote|pre))\b[^>]*>#i', '$0 ', self::get_block_html( $block ) );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Rebuild a parsed block's HTML, including its inner blocks.
	 *
	 * `innerContent` holds HTML strings with a null placeholder where each
	 * inner block goes, so walking it keeps the inner blocks in order.
	 *
	 * @param array<mixed> $block Parsed block.
	 * @return string Block HTML.
	 */
	private static function get_block_html( array $block ): string {
		$inner_blocks  = is_array( $block['innerBlocks'] ?? null ) ? array_values( $block['innerBlocks'] ) : array();
		$inner_content = is_array( $block['innerContent'] ?? null ) ? $block['innerContent'] : array();
		$html          = '';
		$index         = 0;

		foreach ( $inner_content as $chunk ) {
			if ( is_string( $chunk ) ) {
				$html .= $chunk;
				continue;
			}

			$inner = $inner_blocks[ $index ] ?? null;
			++$index;
			if ( is_array( $inner ) ) {
				// Separate blocks so their text does not run together once tags are stripped.
				$html .= ' ' . self::get_block_html( $inner ) . ' ';
			}
		}

		return $html;
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
	 * Returns MOCK_REPLY in AI_FEEDBACK_MOCK_MODE, like Review_Service, so
	 * the reply loop can run end to end without a provider.
	 *
	 * Protected so tests can subclass and override.
	 *
	 * @param  string $prompt             User prompt.
	 * @param  string $system_instruction System instruction.
	 * @param  string $model              Model preference.
	 * @return string|WP_Error AI reply text or error.
	 */
	protected function call_ai( string $prompt, string $system_instruction, string $model ): string|WP_Error {
		if ( defined( 'AI_FEEDBACK_MOCK_MODE' ) && AI_FEEDBACK_MOCK_MODE ) {
			Logger::debug( 'Reply_Service: using mock reply' );
			return self::MOCK_REPLY;
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'ai_client_missing',
				__( 'The WordPress core AI Client is unavailable. WordPress 7.0 or higher is required.', 'copydesk-ai' )
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
				->using_temperature( self::AI_TEMPERATURE )
				->using_max_tokens( self::AI_MAX_TOKENS )
				->generate_text();
		} catch ( \Throwable $e ) {
			// Core converts SDK exceptions to WP_Error; this catches anything else.
			Logger::debug( sprintf( 'Reply_Service: AI request threw %s: %s', get_class( $e ), $e->getMessage() ) );
			return new WP_Error(
				'ai_request_failed',
				__( 'The AI reply request failed unexpectedly. Please try again.', 'copydesk-ai' )
			);
		} finally {
			remove_filter( 'wp_ai_client_default_request_timeout', $timeout_filter, PHP_INT_MAX );
		}
	}
}
