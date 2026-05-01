<?php
/**
 * Reply Detector
 *
 * Detects user replies to AI Feedback notes and dispatches a dedicated
 * action for the Reply Service to handle asynchronously.
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects replies to AI Feedback notes.
 *
 * A "reply" is any `note`-type comment whose parent is an AI-authored
 * note (a comment with `ai_feedback` meta set to '1') AND which was
 * not itself authored by AI Feedback — the latter check prevents our
 * own generated replies from re-triggering detection.
 *
 * WordPress 6.9+ stores block-level notes as comments with
 * `comment_type = 'note'` (see Notes_Manager::create_note()).
 */
class Reply_Detector {

	/**
	 * Action fired when a user reply to an AI Feedback note is detected.
	 *
	 * Args: int $comment_id, int $parent_comment_id, int $post_id, string $block_id.
	 */
	public const ACTION_REPLY_RECEIVED = 'ai_feedback_note_reply_received';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		// wp_insert_comment fires with ( int $comment_id, WP_Comment $comment ).
		add_action( 'wp_insert_comment', array( $this, 'maybe_dispatch_reply' ), 10, 2 );
	}

	/**
	 * Inspect a newly inserted comment and dispatch the reply action when
	 * it is a user reply to an AI Feedback note.
	 *
	 * @param int                   $comment_id Inserted comment ID.
	 * @param \WP_Comment|\stdClass $comment    Comment object (stdClass accepted for unit tests).
	 */
	public function maybe_dispatch_reply( int $comment_id, $comment ): void {
		// Only care about block-level notes.
		if ( 'note' !== ( $comment->comment_type ?? '' ) ) {
			return;
		}

		$parent_id = (int) ( $comment->comment_parent ?? 0 );
		if ( $parent_id <= 0 ) {
			// Top-level note, not a reply.
			return;
		}

		// Ignore replies authored by AI Feedback itself.
		$is_ai_reply = (bool) get_comment_meta( $comment_id, 'ai_feedback', true );
		if ( $is_ai_reply ) {
			return;
		}

		// Parent must be an AI Feedback note.
		$parent_is_ai = (bool) get_comment_meta( $parent_id, 'ai_feedback', true );
		if ( ! $parent_is_ai ) {
			return;
		}

		$post_id = (int) ( $comment->comment_post_ID ?? 0 );
		if ( $post_id <= 0 ) {
			// Comment without a valid post association — nothing to route.
			return;
		}

		$block_id = sanitize_text_field( (string) get_comment_meta( $parent_id, 'block_id', true ) );
		if ( '' === $block_id ) {
			// Parent note has no block association; consumers cannot resolve the target.
			return;
		}

		do_action( self::ACTION_REPLY_RECEIVED, $comment_id, $parent_id, $post_id, $block_id );
	}
}
