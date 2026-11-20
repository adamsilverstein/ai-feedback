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
	 * Token cap for replies: 2-3 sentences plus an edit that can rewrite a
	 * block of up to Prompt_Builder::REPLY_BLOCK_CONTENT_LIMIT characters.
	 */
	public const AI_MAX_TOKENS = 1500;

	/**
	 * Maximum number of prior thread replies sent to the AI as context.
	 */
	public const MAX_THREAD_ENTRIES = 20;

	/**
	 * Blocks listed on each side of the note's block in a reply prompt, so
	 * a reply can move the block next to one of them.
	 */
	public const NEARBY_BLOCKS = 5;

	/**
	 * Reply returned instead of calling the AI when AI_FEEDBACK_MOCK_MODE is on.
	 *
	 * Filterable through `ai_feedback_mock_reply`, so tests can return an edit.
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
	 * Response parser instance.
	 *
	 * @var Response_Parser
	 */
	private Response_Parser $response_parser;

	/**
	 * Claim token this request wrote, or null when it holds no claim.
	 *
	 * @var string|null
	 */
	private ?string $claim_token = null;

	/**
	 * Review service, for the hourly limit a mention shares with reviews.
	 *
	 * @var Review_Service|null
	 */
	private ?Review_Service $review_service;

	/**
	 * Constructor.
	 *
	 * @param Prompt_Builder|null  $prompt_builder Optional prompt builder (for testing).
	 * @param Notes_Manager|null   $notes_manager   Optional notes manager (for testing).
	 * @param Response_Parser|null $response_parser Optional response parser (for testing).
	 * @param Review_Service|null  $review_service  Optional review service (for testing).
	 */
	public function __construct( ?Prompt_Builder $prompt_builder = null, ?Notes_Manager $notes_manager = null, ?Response_Parser $response_parser = null, ?Review_Service $review_service = null ) {
		$this->prompt_builder  = $prompt_builder ?? new Prompt_Builder();
		$this->notes_manager   = $notes_manager ?? new Notes_Manager();
		$this->response_parser = $response_parser ?? new Response_Parser();
		$this->review_service  = $review_service;
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
	 * The editor sends its unsaved post content with a mention, since a new
	 * note is linked to its block in the editor before the post is saved.
	 *
	 * A mention is only answered here for the person who wrote it: the
	 * answer counts against their limit and may read their unsaved content.
	 * Anyone else's request leaves it to that person's editor or to WP-Cron.
	 *
	 * @param int    $comment_id     Reply comment ID.
	 * @param bool   $retry          Whether to also take over a failed reply or a stale claim.
	 * @param string $editor_content Optional. The editor's post content, used to find the note's block.
	 * @param int    $requester_id   Optional. User making the request.
	 * @return bool Whether this request answered the reply.
	 */
	public function process( int $comment_id, bool $retry = false, string $editor_content = '', int $requester_id = 0 ): bool {
		$job = $this->get_job( $comment_id );
		if ( null === $job ) {
			return false;
		}

		if ( get_comment_meta( $comment_id, Mentions::META_KEY, true ) ) {
			$note = get_comment( $comment_id );
			if ( ! is_object( $note ) || $requester_id <= 0 || self::author_id( $note ) !== $requester_id ) {
				return false;
			}
		} else {
			// Only a mention's block is looked up in the editor's content.
			$editor_content = '';
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

		$this->generate( $comment_id, $parent_id, $post_id, $block_id, $editor_content );
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

		$direct_parent = (int) $reply->comment_parent;
		$post_id       = (int) $reply->comment_post_ID;
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
	 * @param  string $editor_content Optional. The editor's post content.
	 */
	private function generate( int $comment_id, int $parent_id, int $post_id, string $block_id, string $editor_content = '' ): void {
		try {
			$result = $this->handle_reply( $comment_id, $parent_id, $post_id, $block_id, $editor_content );
		} catch ( \Throwable $e ) {
			// Record a terminal state so the frontend stops waiting.
			Logger::debug( sprintf( 'Reply_Service: reply %d threw %s: %s', $comment_id, get_class( $e ), $e->getMessage() ) );
			$result = new WP_Error( 'reply_failed', __( 'The AI reply failed unexpectedly.', 'fresh-eyes' ) );
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
			$result = new WP_Error( 'reply_not_saved', __( 'The AI reply could not be saved.', 'fresh-eyes' ) );
		}

		if ( is_wp_error( $result ) ) {
			Logger::debug( sprintf( 'Reply_Service: reply %d failed: %s', $comment_id, $result->get_error_code() ) );
			update_comment_meta( $comment_id, Reply_Cron_Dispatcher::STATUS_META_KEY, 'failed' );
			update_comment_meta(
				$comment_id,
				Reply_Cron_Dispatcher::REPLY_ERROR_META_KEY,
				// update_comment_meta() unslashes, which would strip backslashes.
				wp_slash( mb_substr( sanitize_text_field( $result->get_error_message() ), 0, 300 ) )
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
	 * @param  string $editor_content Optional. The editor's post content, used to find the block of a mention.
	 * @return int|WP_Error New AI reply comment ID on success, WP_Error on failure.
	 */
	public function handle_reply( int $comment_id, int $parent_id, int $post_id, string $block_id, string $editor_content = '' ): int|WP_Error {
		// Reply_Detector flags a note it queued because it mentions Fresh Eyes.
		$is_mention = (bool) get_comment_meta( $comment_id, Mentions::META_KEY, true );
		$reply      = get_comment( $comment_id );
		$user_id    = is_object( $reply ) ? self::author_id( $reply ) : 0;

		// Checked again here: the author's role can change before cron runs.
		if ( $is_mention && ! Mentions::can_ask( $user_id, $post_id ) ) {
			return new WP_Error( 'mention_forbidden', __( 'Only someone who can review this post can ask Fresh Eyes about it.', 'fresh-eyes' ) );
		}

		$edits   = $this->get_thread_edits( $parent_id, $post_id );
		$context = $this->build_context( $comment_id, $parent_id, $post_id, $edits, $is_mention, $is_mention ? $editor_content : '' );
		if ( is_wp_error( $context ) ) {
			Logger::debug( 'Reply_Service: failed to build context: ' . $context->get_error_message() );
			return $context;
		}

		$prompt             = $this->prompt_builder->build_reply_prompt( $context );
		$system_instruction = $this->prompt_builder->get_reply_system_instruction();
		$model              = self::preference_string( 'ai_feedback_default_model', AI_FEEDBACK_DEFAULT_MODEL, $user_id );

		if ( ! $is_mention ) {
			return $this->answer( $comment_id, $parent_id, $post_id, $block_id, $context, $prompt, $system_instruction, $model, false );
		}

		// A mention starts a new request, so it counts against the review limit.
		$allowed = $this->get_review_service()->check_rate_limit( $user_id );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$result = null;
		try {
			$result = $this->answer( $comment_id, $parent_id, $post_id, $block_id, $context, $prompt, $system_instruction, $model, true );
		} finally {
			// No answer was saved by this request, so give the slot back.
			if ( ! is_int( $result ) || $result <= 0 ) {
				$this->get_review_service()->refund_rate_limit( $user_id );
			}
		}

		if ( is_int( $result ) && $result > 0 ) {
			/**
			 * Fires after Fresh Eyes answers a note that mentions it.
			 *
			 * @param int                  $post_id Post ID.
			 * @param array<string, mixed> $answer  `user_id` who asked, `model`, and the answer's `note_id`.
			 */
			do_action(
				'ai_feedback_after_mention',
				$post_id,
				array(
					'user_id' => $user_id,
					'model'   => $model,
					'note_id' => $result,
				)
			);
		}

		return $result;
	}

	/**
	 * Call the AI for a reply and save its answer in the thread.
	 *
	 * @param int                  $comment_id         Reply comment ID.
	 * @param int                  $parent_id          Root note ID.
	 * @param int                  $post_id            Post ID.
	 * @param string               $block_id           Block ID from the root note.
	 * @param array<string, mixed> $context            Reply context.
	 * @param string               $prompt             Prompt.
	 * @param string               $system_instruction System instruction.
	 * @param string               $model              Model preference.
	 * @param bool                 $is_mention         Whether the reply asks Fresh Eyes through a mention.
	 * @return int|WP_Error New AI reply comment ID, or error.
	 */
	private function answer( int $comment_id, int $parent_id, int $post_id, string $block_id, array $context, string $prompt, string $system_instruction, string $model, bool $is_mention ): int|WP_Error {
		$ai_text = $this->call_ai( $prompt, $system_instruction, $model );
		if ( is_wp_error( $ai_text ) ) {
			Logger::debug( 'Reply_Service: AI call failed: ' . $ai_text->get_error_message() );
			return $ai_text;
		}

		$block_content = is_string( $context['block_text'] ?? null ) ? $context['block_text'] : '';
		$targets       = is_array( $context['block_targets'] ?? null ) ? $context['block_targets'] : array();
		$parsed        = $this->response_parser->parse_reply( $ai_text, $block_content, ! empty( $context['block_can_rewrite'] ), $targets );
		if ( null === $parsed && ! $this->response_parser->is_readable_reply( $ai_text ) ) {
			Logger::debug( 'Reply_Service: AI reply could not be read.' );
			return new WP_Error( 'ai_unreadable_reply', __( 'The AI reply could not be read. Please try again.', 'fresh-eyes' ) );
		}
		if ( null === $parsed ) {
			Logger::debug( 'Reply_Service: AI returned an empty reply.' );
			return new WP_Error( 'ai_empty_reply', __( 'The AI returned an empty reply.', 'fresh-eyes' ) );
		}

		if ( $is_mention ) {
			$parsed = self::remove_unsourced_links( $parsed, self::context_sources( $context ) );
			$parsed = self::keep_edit_in_selection( $parsed, $context, $parent_id );
		}

		if ( ! $this->owns_claim( $comment_id ) ) {
			return new WP_Error( 'reply_claim_lost', __( 'Another request is answering this reply.', 'fresh-eyes' ) );
		}

		$feedback_item = array(
			'feedback' => $parsed['reply'],
			'block_id' => $block_id,
		);
		if ( null !== $parsed['edit'] ) {
			$feedback_item['reply_edit'] = $parsed['edit'];
		}
		$review_data = array(
			'review_id' => 'reply-' . $comment_id,
			'post_id'   => $post_id,
			'model'     => $model,
			'timestamp' => current_time( 'c', true ),
			// The answer speaks as the same reader as the note it answers.
			'persona'   => is_string( $context['persona'] ?? null ) ? $context['persona'] : '',
		);

		return $this->notes_manager->add_reply_to_thread( $feedback_item, $post_id, $parent_id, $review_data );
	}

	/**
	 * Get the review service, created on first use.
	 *
	 * @return Review_Service
	 */
	private function get_review_service(): Review_Service {
		$this->review_service ??= new Review_Service();
		return $this->review_service;
	}

	/**
	 * Everything the AI was given for a reply, to check its links against.
	 *
	 * @param array<string, mixed> $context Reply context.
	 * @return string
	 */
	private static function context_sources( array $context ): string {
		$unsaved = ! empty( $context['unsaved_block'] );
		$sources = array();
		$keys    = $unsaved ? array( 'user_reply', 'original_feedback' ) : array( 'block_html', 'block_content', 'selected_text', 'user_reply', 'original_feedback' );
		foreach ( $keys as $key ) {
			$sources[] = is_string( $context[ $key ] ?? null ) ? $context[ $key ] : '';
		}
		foreach ( $unsaved ? array( 'thread' ) : array( 'thread', 'nearby_blocks' ) as $key ) {
			foreach ( is_array( $context[ $key ] ?? null ) ? $context[ $key ] : array() as $entry ) {
				$text      = is_array( $entry ) ? ( $entry['content'] ?? $entry['text'] ?? null ) : null;
				$sources[] = is_string( $text ) ? $text : '';
			}
		}
		return implode( "\n", $sources );
	}

	/**
	 * Take made-up links out of an answer to a mention.
	 *
	 * Fresh Eyes answers without web search, so a link that is not in the
	 * post or the thread was invented. It is removed from the reply, and an
	 * edit that would add one is dropped.
	 *
	 * @param array{reply: string, edit: array<string, mixed>|null} $parsed  Parsed reply.
	 * @param string                                                $sources Everything the AI was given.
	 * @return array{reply: string, edit: array<string, mixed>|null} Parsed reply without made-up links.
	 */
	private static function remove_unsourced_links( array $parsed, string $sources ): array {
		$parsed['reply'] = Mentions::remove_unsourced_urls( $parsed['reply'], $sources );
		if ( null !== $parsed['edit'] && Mentions::edit_has_unsourced_url( $parsed['edit'], $sources ) ) {
			Logger::debug( 'Reply_Service: dropped an edit that adds a link the AI was not given.' );
			$parsed['edit']   = null;
			$parsed['reply'] .= ' ' . __( '(The suggested change was left out because it added a link Fresh Eyes could not check.)', 'fresh-eyes' );
		}
		return $parsed;
	}

	/**
	 * Drop a text change outside the text the note is anchored to.
	 *
	 * The prompt asks for changes scoped to the selection; this holds the
	 * AI to it. A note on a whole block has no selection and is left alone.
	 *
	 * @param array{reply: string, edit: array<string, mixed>|null} $parsed    Parsed reply.
	 * @param array<string, mixed>                                  $context   Reply context.
	 * @param int                                                   $parent_id Root note ID.
	 * @return array{reply: string, edit: array<string, mixed>|null} Parsed reply.
	 */
	private static function keep_edit_in_selection( array $parsed, array $context, int $parent_id ): array {
		$edit = $parsed['edit'];
		if ( null === $edit || 'replace_text' !== ( $edit['type'] ?? null ) || ! is_string( $edit['find'] ?? null ) ) {
			return $parsed;
		}

		$block_html = is_string( $context['block_html'] ?? null ) ? $context['block_html'] : '';
		// The whole selection, not the prompt's shortened copy.
		$selection = Mentions::get_selected_text( $block_html, $parent_id, PHP_INT_MAX );
		$find      = trim( (string) preg_replace( '/\s+/u', ' ', $edit['find'] ) );
		if ( '' === $selection || ( '' !== $find && str_contains( $selection, $find ) ) ) {
			return $parsed;
		}

		Logger::debug( 'Reply_Service: dropped an edit outside the selected text.' );
		$parsed['edit']   = null;
		$parsed['reply'] .= ' ' . __( '(The suggested change was left out because it went beyond the selected text.)', 'fresh-eyes' );
		return $parsed;
	}

	/**
	 * Build the reply context array from a parent note and its thread.
	 *
	 * @param  int          $comment_id Reply comment ID.
	 * @param  int          $parent_id  Parent (original AI note) comment ID.
	 * @param  int          $post_id    Post ID - used to scope the siblings query.
	 * @param  array<mixed> $edits      Edits proposed in the thread, oldest first, from get_thread_edits().
	 * @param  bool         $is_mention Whether the reply asks Fresh Eyes through a mention.
	 * @param  string       $editor_content Optional. The editor's post content, searched before the saved post for a mention's block.
	 * @phpstan-param list<array{id: int, edit: array<string, mixed>}> $edits
	 * @return array<string, mixed>|WP_Error Context for Prompt_Builder::build_reply_prompt(), or error.
	 */
	private function build_context( int $comment_id, int $parent_id, int $post_id, array $edits, bool $is_mention = false, string $editor_content = '' ): array|WP_Error {
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

		$user_id = self::author_id( $reply );

		$edits_by_id = array();
		foreach ( $edits as $entry ) {
			$edits_by_id[ $entry['id'] ] = $entry['edit'];
		}

		$thread = array();
		foreach ( array_reverse( $siblings ) as $sibling ) {
			$sibling_id = (int) $sibling->comment_ID;
			if ( $sibling_id === $comment_id ) {
				continue;
			}
			$thread[] = array(
				'author'  => $sibling->comment_author,
				'content' => $sibling->comment_content,
				'is_ai'   => (bool) get_comment_meta( $sibling_id, 'ai_feedback', true ),
				'edit'    => $edits_by_id[ $sibling_id ] ?? null,
			);
		}
		if ( $is_mention && $parent_id !== $comment_id && ! get_comment_meta( $parent_id, 'ai_feedback', true ) ) {
			// The person who started the thread is part of the conversation.
			array_unshift(
				$thread,
				array(
					'author'  => $parent->comment_author,
					'content' => $parent->comment_content,
					'is_ai'   => false,
					'edit'    => null,
				)
			);
		}
		$thread = array_slice( $thread, -self::MAX_THREAD_ENTRIES );

		// The saved post wins when it already links the note. The editor's
		// content is only for a note not saved with the post yet.
		$located     = $this->locate_note_block( $post_id, $parent_id );
		$from_editor = null === $located && '' !== $editor_content;
		if ( $from_editor ) {
			$located = self::search_blocks( parse_blocks( $editor_content ), $parent_id );
		}
		$block      = $located['block'] ?? null;
		$block_type = self::meta_string( $parent_id, 'block_name' );
		if ( '' === $block_type && null !== $block && is_string( $block['blockName'] ?? null ) ) {
			$block_type = $block['blockName'];
		}

		// The text as it reads without any pending suggestion, which is how
		// the editor leaves it once a new edit supersedes that suggestion.
		$block_html = null !== $block ? self::strip_suggestion_markers( self::get_block_html( $block ) ) : '';
		// What an edit's `find` is matched against: the block's own text as
		// the editor holds it, without its inner blocks, like a list item's
		// text without its nested list.
		$block_text    = null !== $block ? self::editor_text( self::strip_suggestion_markers( self::get_own_html( $block ) ) ) : '';
		$block_content = '';
		$inner_text    = '';
		$inner_blocks  = null !== $block && is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array();
		if ( array() !== $inner_blocks && self::has_rich_text_content( $block_type ) ) {
			// An edit applies to the block's own text, like a list item's
			// text without its nested list, so the prompt shows that, with
			// the inner blocks as context.
			$block_content = $block_text;
			$inner_html    = '';
			foreach ( $inner_blocks as $inner ) {
				$inner_html .= is_array( $inner ) ? ' ' . self::get_block_html( $inner ) : '';
			}
			$inner_text = self::html_text( self::strip_suggestion_markers( $inner_html ) );
		} elseif ( null !== $block ) {
			// The prompt reads a block with inner blocks, or an image block,
			// whose <img> is the block, the collapsed way, which is easier to
			// follow.
			$block_content = empty( $block['innerBlocks'] ) && 'core/image' !== $block_type
				? $block_text
				: self::html_text( $block_html );
		}
		$nearby = null !== $located ? self::get_nearby_blocks( $located['siblings'], $located['index'] ) : array();

		return array(
			'is_mention'        => $is_mention,
			// Unsaved content is not a source a link can be credited to.
			'unsaved_block'     => $from_editor && null !== $located,
			'selected_text'     => $is_mention ? Mentions::get_selected_text( $block_html, $parent_id ) : '',
			'block_html'        => $block_html,
			'block_content'     => $block_content,
			'inner_blocks_text' => $inner_text,
			'block_text'        => $block_text,
			'block_is_text'     => true,
			'block_type'        => $block_type,
			// A rewrite replaces the whole block from the plain text the AI
			// sees, so only when that text is the whole block: not once the
			// prompt truncates it, and not when links, formatting or line
			// breaks would be lost.
			'block_can_rewrite' => '' !== $block_content
				&& ! self::has_inline_markup( $block_html )
				&& mb_strlen( $block_content ) <= Prompt_Builder::REPLY_BLOCK_CONTENT_LIMIT
				&& self::supports_rewrite( $block_type ),
			'original_feedback' => $is_mention ? '' : $parent->comment_content,
			'original_category' => self::meta_string( $parent_id, 'feedback_category' ),
			'original_severity' => self::meta_string( $parent_id, 'feedback_severity' ),
			'thread'            => $thread,
			'nearby_blocks'     => $nearby,
			'block_targets'     => self::get_block_targets( $nearby ),
			'pending_edit'      => $this->find_pending_edit( $edits, $block_text, $located ),
			'user_reply'        => $reply->comment_content,
			'target_tone'       => self::preference_string( 'ai_feedback_default_tone', 'professional', $user_id ),
			'locale'            => self::preference_string( 'ai_feedback_feedback_locale', get_locale(), $user_id ),
			'persona'           => self::meta_string( $parent_id, Personas::META_KEY ),
		);
	}

	/**
	 * List the edits proposed in a thread, oldest first.
	 *
	 * The review's structural edit, formatting fix or quick fix on the root
	 * note comes first (a note has at most one), then each AI
	 * reply's edit. Only the newest can be pending: each new edit supersedes
	 * the ones before it.
	 *
	 * @param int $parent_id Root note ID.
	 * @param int $post_id   Post ID.
	 * @return list<array{id: int, edit: array<string, mixed>}> Note ID and edit.
	 */
	private function get_thread_edits( int $parent_id, int $post_id ): array {
		$edits = array();

		$block_action = Notes_Manager::decode_block_action( get_comment_meta( $parent_id, Notes_Manager::BLOCK_ACTION_META_KEY, true ) );
		$block_change = Block_Change::decode( get_comment_meta( $parent_id, Notes_Manager::BLOCK_CHANGE_META_KEY, true ), self::meta_string( $parent_id, 'block_name' ) );
		$quick_fix    = $this->notes_manager->decode_quick_fix( get_comment_meta( $parent_id, 'feedback_quick_fix', true ) );
		if ( null !== $block_action ) {
			$edits[] = array(
				'id'   => $parent_id,
				'edit' => $block_action,
			);
		} elseif ( null !== $block_change ) {
			$edits[] = array(
				'id'   => $parent_id,
				'edit' => $block_change,
			);
		} elseif ( null !== $quick_fix ) {
			$edits[] = array(
				'id'   => $parent_id,
				'edit' => array( 'type' => 'replace_text' ) + $quick_fix,
			);
		}

		$replies = get_comments(
			array(
				'parent'  => $parent_id,
				'post_id' => $post_id,
				'type'    => 'note',
				'status'  => 'all',
				'orderby' => array(
					'comment_date' => 'ASC',
					'comment_ID'   => 'ASC',
				),
			)
		);

		foreach ( $replies as $reply ) {
			$reply_id = (int) $reply->comment_ID;
			if ( ! get_comment_meta( $reply_id, 'ai_feedback', true ) ) {
				continue;
			}
			$edit = Notes_Manager::decode_reply_edit( get_comment_meta( $reply_id, Notes_Manager::REPLY_EDIT_META_KEY, true ) );
			if ( null !== $edit ) {
				$edits[] = array(
					'id'   => $reply_id,
					'edit' => $edit,
				);
			}
		}

		return $edits;
	}

	/**
	 * Find the thread's suggested edit that the author has not acted on yet.
	 *
	 * That is the newest edit, unless the author accepted or rejected its
	 * suggestion or the block text no longer matches it, for example
	 * because the author applied it.
	 *
	 * A structural edit is pending while the note's block is in the post
	 * and the post does not already read as the edit would leave it.
	 *
	 * @param list<array{id: int, edit: array<string, mixed>}> $edits         Edits in the thread, oldest first.
	 * @param string                                           $block_content Block text without pending suggestions.
	 * @param array<string, mixed>|null                        $located       Note's block and its siblings, from locate_note_block().
	 * @return array<string, mixed>|null Pending edit, or null.
	 */
	private function find_pending_edit( array $edits, string $block_content, ?array $located = null ): ?array {
		$latest = end( $edits );
		if ( false === $latest ) {
			return null;
		}

		$status = ( new Suggestions() )->get_status( $latest['id'] );
		if ( null !== $status && 'pending' !== $status ) {
			return null;
		}

		if ( Response_Parser::is_block_action( $latest['edit'] ) ) {
			return null !== $located && ! self::is_block_action_applied( $latest['edit'], $located ) ? $latest['edit'] : null;
		}

		if ( Block_Change::is_change( $latest['edit'] ) ) {
			return null !== $located && ! self::is_block_change_applied( $latest['edit'], $located ) ? $latest['edit'] : null;
		}

		if ( '' === $block_content ) {
			return null;
		}

		return $this->response_parser->edit_applies( $latest['edit'], $block_content ) ? $latest['edit'] : null;
	}

	/**
	 * Whether the saved post already reads as a structural edit would leave
	 * it: the block sits next to its move target, or the inserted block is
	 * already next to it. A removed block is never found, so it is never
	 * applied here.
	 *
	 * @param array<string, mixed> $edit    Structural edit.
	 * @param array<string, mixed> $located Note's block and its siblings, from locate_note_block().
	 * @return bool
	 */
	private static function is_block_action_applied( array $edit, array $located ): bool {
		$siblings = is_array( $located['siblings'] ?? null ) ? array_values( $located['siblings'] ) : array();
		$index    = is_int( $located['index'] ?? null ) ? $located['index'] : 0;
		$step     = 'before' === ( $edit['position'] ?? null ) ? 1 : -1;
		$type     = $edit['type'] ?? null;

		if ( 'move_block' === $type ) {
			// Moving before a block leaves that block right after this one.
			$neighbor = $siblings[ $index + $step ] ?? null;
			$text     = is_string( $edit['target_text'] ?? null ) ? $edit['target_text'] : '';
			return is_array( $neighbor ) && '' !== $text
				&& ( $edit['target_name'] ?? null ) === ( $neighbor['blockName'] ?? null )
				&& Response_Parser::matches_target_text( self::html_text( self::strip_suggestion_markers( self::get_block_html( $neighbor ) ) ), $text );
		}

		if ( 'insert_block' === $type ) {
			// A block inserted before this one sits right before it.
			$neighbor = $siblings[ $index - $step ] ?? null;
			$block    = is_array( $edit['block'] ?? null ) ? $edit['block'] : array();
			$text     = is_array( $block['items'] ?? null )
				? implode( ' ', array_filter( $block['items'], 'is_string' ) )
				: ( is_string( $block['content'] ?? null ) ? $block['content'] : '' );
			$attrs    = is_array( $neighbor ) && is_array( $neighbor['attrs'] ?? null ) ? $neighbor['attrs'] : array();
			return is_array( $neighbor )
				&& ( $block['name'] ?? null ) === ( $neighbor['blockName'] ?? null )
				// A heading's level and a list's type are part of the edit; both blocks default to an h2 and a bulleted list.
				&& ( $block['level'] ?? 2 ) === ( $attrs['level'] ?? 2 )
				&& ( $block['ordered'] ?? false ) === ( $attrs['ordered'] ?? false )
				&& self::html_text( $text ) === self::html_text( self::strip_suggestion_markers( self::get_block_html( $neighbor ) ) );
		}

		return false;
	}

	/**
	 * Whether the saved post already reads as a formatting fix would leave
	 * it. The note's link moves with a transformed block, so the block found
	 * for the note is the new list or heading, or a split's first half.
	 *
	 * @param array<string, mixed> $edit    Formatting fix.
	 * @param array<string, mixed> $located Note's block and its siblings, from locate_note_block().
	 * @return bool
	 */
	private static function is_block_change_applied( array $edit, array $located ): bool {
		$block = is_array( $located['block'] ?? null ) ? $located['block'] : array();
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$html  = self::strip_suggestion_markers( self::get_block_html( $block ) );

		switch ( $edit['type'] ?? null ) {
			case 'transform':
				return ( $edit['to'] ?? null ) === ( $block['blockName'] ?? null );
			case 'split':
				$siblings = is_array( $located['siblings'] ?? null ) ? array_values( $located['siblings'] ) : array();
				$index    = is_int( $located['index'] ?? null ) ? $located['index'] : 0;
				$next     = $siblings[ $index + 1 ] ?? null;
				$after    = is_string( $edit['after'] ?? null ) ? $edit['after'] : '';
				$rest     = is_string( $edit['next'] ?? null ) ? $edit['next'] : '';
				return '' !== $after && str_ends_with( self::html_text( $html ), $after )
					&& is_array( $next ) && 'core/paragraph' === ( $next['blockName'] ?? null )
					&& str_starts_with( self::html_text( self::get_block_html( $next ) ), $rest );
		}

		$wanted = is_array( $edit['attributes'] ?? null ) ? $edit['attributes'] : array();
		foreach ( $wanted as $key => $value ) {
			$current = match ( $key ) {
				'level'   => $attrs['level'] ?? 2,
				'ordered' => $attrs['ordered'] ?? false,
				'alt'     => self::image_alt( $html ),
				default   => self::image_caption( $html ),
			};
			if ( $current !== $value ) {
				return false;
			}
		}
		return array() !== $wanted;
	}

	/**
	 * Alt text of the image in a block's HTML.
	 *
	 * @param string $html Block HTML.
	 * @return string Alt text, or '' when there is none.
	 */
	private static function image_alt( string $html ): string {
		if ( 1 !== preg_match( '/<img\b[^>]*\balt="([^"]*)"/i', $html, $match ) ) {
			return '';
		}
		return html_entity_decode( $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Caption text of an image block's HTML.
	 *
	 * @param string $html Block HTML.
	 * @return string Caption text, or '' when there is none.
	 */
	private static function image_caption( string $html ): string {
		return 1 === preg_match( '#<figcaption\b[^>]*>(.*?)</figcaption>#is', $html, $match ) ? self::html_text( $match[1] ) : '';
	}

	/**
	 * List the blocks around the note's block for the reply prompt.
	 *
	 * Up to NEARBY_BLOCKS on each side, at the same level, each with a short
	 * reference the AI uses to name it. The note's block is listed as
	 * Response_Parser::REPLY_SELF_REF.
	 *
	 * @param list<array<mixed>> $siblings Blocks at the note's block's level.
	 * @param int                $index    Position of the note's block in `$siblings`.
	 * @return list<array{ref: string, name: string, text: string, index: int}> Nearby blocks.
	 */
	private static function get_nearby_blocks( array $siblings, int $index ): array {
		$nearby = array();
		$first  = max( 0, $index - self::NEARBY_BLOCKS );
		$last   = min( count( $siblings ) - 1, $index + self::NEARBY_BLOCKS );
		$number = 0;
		for ( $position = $first; $position <= $last; $position++ ) {
			$block = $siblings[ $position ] ?? null;
			if ( ! is_array( $block ) ) {
				continue;
			}
			$nearby[] = array(
				'ref'   => $position === $index ? Response_Parser::REPLY_SELF_REF : 'b' . ( ++$number ),
				'name'  => is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '',
				'text'  => self::html_text( self::strip_suggestion_markers( self::get_block_html( $block ) ) ),
				'index' => $position,
			);
		}

		return $nearby;
	}

	/**
	 * Key nearby blocks by reference, as Response_Parser::parse_reply()
	 * expects. Their client IDs are not known on the server.
	 *
	 * @param list<array{ref: string, name: string, text: string, index: int}> $nearby Nearby blocks.
	 * @return array<string, array{client_id: string, name: string, text: string, index: int}> Targets keyed by reference.
	 */
	private static function get_block_targets( array $nearby ): array {
		$targets = array();
		foreach ( $nearby as $entry ) {
			$targets[ $entry['ref'] ] = array(
				'client_id' => '',
				'name'      => $entry['name'],
				'text'      => $entry['text'],
				'index'     => $entry['index'],
			);
		}
		return $targets;
	}

	/**
	 * Take Gutenberg suggestion markers out of block HTML, leaving the text
	 * as it reads with the suggestions rejected: added runs are dropped and
	 * deleted or reformatted runs keep their text.
	 *
	 * Marks are handled innermost first, so a format run inside a deleted run
	 * unwraps cleanly. Other marks, such as highlights, are set aside while
	 * the marks around them are handled, then put back.
	 *
	 * @param string $html Block HTML.
	 * @return string HTML without suggestion markers.
	 */
	private static function strip_suggestion_markers( string $html ): string {
		if ( ! str_contains( $html, 'wp-suggestion-' ) ) {
			return $html;
		}

		$input = $html;
		// Placeholders below use NUL bytes, which block HTML never needs.
		$html = str_replace( "\x00", '', $html );
		$kept = array();
		// Quoted attribute values may hold `>`, and closing tags may have
		// whitespace before it.
		$pattern = '#<mark\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>((?:(?!<mark\b).)*?)</mark\s*>#si';
		// Each pass unwraps one level of nesting. The cap guards odd markup:
		// suggestions nested more than 20 deep keep their outer markers.
		$passes = 0;
		do {
			$html = preg_replace_callback(
				$pattern,
				static function ( array $matches ) use ( &$kept ): string {
					if ( preg_match( '#\bclass\s*=\s*(["\'])[^"\']*\bwp-suggestion-(add|del|format)\b#i', $matches[1], $type ) ) {
						return 'add' === $type[2] ? '' : $matches[2];
					}
					$kept[] = $matches[0];
					return "\x00" . ( count( $kept ) - 1 ) . "\x00";
				},
				$html,
				-1,
				$count
			);
			if ( null === $html ) {
				// The regex failed, eg. on a backtrack limit. Keep the markup
				// as it was rather than lose the block's text: an edit that
				// no longer matches the editor's text is dropped, not applied.
				return $input;
			}
		} while ( $count > 0 && ++$passes < 20 );

		// Put back the other marks, which may hold one another. A kept mark
		// only holds placeholders for marks inside it, so one pass per kept
		// mark is always enough; the bound guards against anything else.
		$limit = count( $kept ) + 1;
		for ( $pass = 0; $pass < $limit; $pass++ ) {
			if ( ! str_contains( $html, "\x00" ) ) {
				break;
			}
			$html = preg_replace_callback(
				'#\x00(\d+)\x00#',
				static fn ( array $matches ): string => $kept[ (int) $matches[1] ] ?? '',
				$html
			);
			if ( null === $html ) {
				return $input;
			}
		}

		return $html;
	}

	/**
	 * Whether block HTML holds any markup inside its wrapper element, such
	 * as a link, bold text or a line break.
	 *
	 * @param string $html Block HTML.
	 * @return bool
	 */
	private static function has_inline_markup( string $html ): bool {
		$html  = trim( $html );
		$inner = (string) preg_replace( '#^<[a-zA-Z][^>]*>|</[a-zA-Z][a-zA-Z0-9-]*>$#', '', $html );
		return str_contains( $inner, '<' );
	}

	/**
	 * Whether a block type keeps its text in a rich text `content` attribute,
	 * which the editor applies a text edit to.
	 *
	 * Matches getRichTextAttributeKey() in src/utils/quick-fix.js.
	 *
	 * @param string $block_name Block name.
	 * @return bool
	 */
	private static function has_rich_text_content( string $block_name ): bool {
		if ( '' === $block_name || ! class_exists( 'WP_Block_Type_Registry' ) ) {
			return false;
		}

		$block_type = \WP_Block_Type_Registry::get_instance()->get_registered( $block_name );
		$attribute  = null !== $block_type && is_array( $block_type->attributes ) ? ( $block_type->attributes['content'] ?? null ) : null;
		return is_array( $attribute ) && (
			'rich-text' === ( $attribute['type'] ?? null )
			|| in_array( $attribute['source'] ?? null, array( 'rich-text', 'html' ), true )
		);
	}

	/**
	 * Whether a block type keeps its text in a rich text `content` attribute,
	 * which a `rewrite_block` edit replaces.
	 *
	 * Matches getRichTextAttributeKey() in src/utils/quick-fix.js.
	 *
	 * @param string $block_name Block name.
	 * @return bool
	 */
	private static function supports_rewrite( string $block_name ): bool {
		$supported = self::has_rich_text_content( $block_name );

		/**
		 * Filters whether an AI reply may rewrite a whole block of this type.
		 *
		 * @param bool   $supported  Whether the block has a rich text `content` attribute.
		 * @param string $block_name Block name.
		 */
		return (bool) apply_filters( 'ai_feedback_reply_can_rewrite_block', $supported, $block_name );
	}

	/**
	 * Find the block a note is attached to in the saved post content.
	 *
	 * The editor links a block to its note thread through the block's
	 * `metadata.noteId` attribute, which holds the thread's root note ID.
	 *
	 * @param int $post_id Post ID.
	 * @param int $note_id Root note ID.
	 * @return array{block: array<mixed>, siblings: list<array<mixed>>, index: int}|null Parsed block with its siblings and position, or null when the post or block is missing.
	 */
	private function locate_note_block( int $post_id, int $note_id ): ?array {
		$post = get_post( $post_id );
		if ( null === $post || '' === $post->post_content ) {
			return null;
		}

		return self::search_blocks( parse_blocks( $post->post_content ), $note_id );
	}

	/**
	 * Depth-first search for the block whose `metadata.noteId` matches.
	 *
	 * Whitespace between blocks parses as nameless blocks, which are left
	 * out of the siblings.
	 *
	 * @param array<mixed> $blocks  Parsed blocks.
	 * @param int          $note_id Root note ID.
	 * @return array{block: array<mixed>, siblings: list<array<mixed>>, index: int}|null Matching block with its siblings and position, or null.
	 */
	private static function search_blocks( array $blocks, int $note_id ): ?array {
		$siblings = array_values(
			array_filter(
				$blocks,
				static fn ( mixed $block ): bool => is_array( $block ) && is_string( $block['blockName'] ?? null )
			)
		);

		foreach ( $siblings as $index => $block ) {
			$attrs    = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$metadata = is_array( $attrs['metadata'] ?? null ) ? $attrs['metadata'] : array();
			if ( self::links_note( $metadata['noteId'] ?? null, $note_id ) ) {
				return array(
					'block'    => $block,
					'siblings' => $siblings,
					'index'    => $index,
				);
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
	 * Get block HTML's plain text with entities decoded and whitespace collapsed.
	 *
	 * With the `u` flag, `\s` also matches the non-breaking spaces that
	 * `&nbsp;` decodes to.
	 *
	 * @param string $html Block HTML.
	 * @return string Block text.
	 */
	private static function html_text( string $html ): string {
		// Keep a break between cells, lines and list items once tags are stripped.
		$html = (string) preg_replace( '#<(?:br|/(?:p|li|td|th|tr|h[1-6]|div|figcaption|blockquote|pre))\b[^>]*>#i', '$0 ', $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Get block HTML's plain text as the editor's rich text reads it.
	 *
	 * An edit's `find` is matched against this text here and against
	 * `create( { html } ).text` in the editor, so they must agree: a line
	 * break is "\n", a footnote, an inline image or a comment is U+FFFC,
	 * entities are decoded and whitespace is kept as written. The outer ends
	 * are trimmed. Shares its cases with extractEditorText() in
	 * src/utils/block-utils.js through tests/fixtures/editor-text.json.
	 *
	 * @see Editor_Text::read()
	 *
	 * @param string $html Block HTML.
	 * @return string Block text.
	 */
	public static function editor_text( string $html ): string {
		return Editor_Text::read( $html );
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
	 * Get a parsed block's own HTML, without its inner blocks.
	 *
	 * @param array<mixed> $block Parsed block.
	 * @return string Block HTML.
	 */
	private static function get_own_html( array $block ): string {
		$inner_content = is_array( $block['innerContent'] ?? null ) ? $block['innerContent'] : array();
		return implode( '', array_filter( $inner_content, 'is_string' ) );
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
	 * Get the ID of the user who wrote a comment.
	 *
	 * @param object $comment Comment.
	 * @return int User ID, or 0 when the comment has no user.
	 */
	private static function author_id( object $comment ): int {
		$user_id = get_object_vars( $comment )['user_id'] ?? 0;
		return is_numeric( $user_id ) ? (int) $user_id : 0;
	}

	/**
	 * Read a string preference of the user who wrote the reply, falling back
	 * to the site default.
	 *
	 * @param string $name          Option name.
	 * @param string $default_value Value used when the preference is missing or not a string.
	 * @param int    $user_id       ID of the reply's author.
	 * @return string
	 */
	private static function preference_string( string $name, string $default_value, int $user_id ): string {
		$value = User_Preferences::get( $name, $default_value, $user_id );
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

			/**
			 * Filters the mock reply returned in AI_FEEDBACK_MOCK_MODE.
			 *
			 * @param string $reply  Mock reply: prose, or the JSON a model returns.
			 * @param string $prompt Reply prompt.
			 */
			return apply_filters( 'ai_feedback_mock_reply', self::MOCK_REPLY, $prompt );
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'ai_client_missing',
				__( 'The WordPress core AI Client is unavailable. WordPress 7.0 or higher is required.', 'fresh-eyes' )
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
				__( 'The AI reply request failed unexpectedly. Please try again.', 'fresh-eyes' )
			);
		} finally {
			remove_filter( 'wp_ai_client_default_request_timeout', $timeout_filter, PHP_INT_MAX );
		}
	}
}
