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
 * A "reply" is any `note`-type comment in a thread whose root is an
 * AI-authored note (a comment with `ai_feedback` meta set to '1'), which
 * was not itself authored by AI Feedback, and which has text. Resolve and
 * reopen status markers are not replies.
 *
 * The second check stops our own replies from re-triggering detection. It
 * relies on AI replies passing `ai_feedback` through `comment_meta` in
 * wp_insert_comment(), which saves meta before `wp_insert_comment` fires.
 * Adding that meta afterwards would cause a reply loop.
 *
 * WordPress 6.9+ stores block-level notes as comments with
 * `comment_type = 'note'` (see Notes_Manager::create_note()).
 */
class Reply_Detector {

	/**
	 * Action fired when a user reply to an AI Feedback note is detected.
	 *
	 * Args: int $comment_id, int $parent_comment_id (the thread's root AI
	 * note), int $post_id, string $block_id.
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
	 * @param int    $comment_id Inserted comment ID.
	 * @param object $comment    WP_Comment (stdClass accepted for unit tests).
	 */
	public function maybe_dispatch_reply( int $comment_id, object $comment ): void {
		// Only care about block-level notes.
		if ( 'note' !== ( $comment->comment_type ?? '' ) ) {
			return;
		}

		if ( in_array( $comment->comment_approved ?? '', array( 'spam', 'trash' ), true ) ) {
			return;
		}

		$parent_id = self::int_field( $comment, 'comment_parent' );
		if ( $parent_id <= 0 ) {
			// Top-level note, not a reply.
			return;
		}

		// A reply to another reply belongs to the same conversation.
		$parent_id = self::find_thread_root( $parent_id );

		// Ignore replies authored by AI Feedback itself.
		$is_ai_reply = (bool) get_comment_meta( $comment_id, 'ai_feedback', true );
		if ( $is_ai_reply ) {
			return;
		}

		/*
		 * Resolving or reopening a thread adds a status marker reply. The
		 * REST API saves `_wp_note_status` after this action fires, so the
		 * editor's empty resolve marker is caught by its empty content.
		 */
		$note_status = get_comment_meta( $comment_id, '_wp_note_status', true );
		if ( is_string( $note_status ) && '' !== $note_status ) {
			return;
		}

		$content = $comment->comment_content ?? '';
		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return;
		}

		// The thread must start with an AI Feedback note.
		$parent_is_ai = (bool) get_comment_meta( $parent_id, 'ai_feedback', true );
		if ( ! $parent_is_ai ) {
			return;
		}

		$post_id = self::int_field( $comment, 'comment_post_ID' );
		if ( $post_id <= 0 ) {
			// Comment without a valid post association - nothing to route.
			return;
		}

		$raw_block_id = get_comment_meta( $parent_id, 'block_id', true );
		$block_id     = is_string( $raw_block_id ) ? sanitize_text_field( $raw_block_id ) : '';
		if ( '' === $block_id ) {
			// Parent note has no block association; consumers cannot resolve the target.
			return;
		}

		do_action( self::ACTION_REPLY_RECEIVED, $comment_id, $parent_id, $post_id, $block_id );
	}

	/**
	 * Walk up from a note to the top-level note of its thread.
	 *
	 * @param int $comment_id Note ID.
	 * @return int Root note ID.
	 */
	private static function find_thread_root( int $comment_id ): int {
		// Bounded so a corrupt parent cycle cannot loop forever.
		for ( $depth = 0; $depth < 10; $depth++ ) {
			$comment = get_comment( $comment_id );
			$parent  = is_object( $comment ) ? self::int_field( $comment, 'comment_parent' ) : 0;
			if ( $parent <= 0 ) {
				break;
			}
			$comment_id = $parent;
		}

		return $comment_id;
	}

	/**
	 * Read a numeric comment field as an int.
	 *
	 * WP_Comment stores IDs as numeric strings.
	 *
	 * @param object $comment Comment object.
	 * @param string $field   Property name.
	 * @return int Field value, or 0 when missing or non-numeric.
	 */
	private static function int_field( object $comment, string $field ): int {
		$value = $comment->$field ?? 0;
		return is_numeric( $value ) ? (int) $value : 0;
	}
}
