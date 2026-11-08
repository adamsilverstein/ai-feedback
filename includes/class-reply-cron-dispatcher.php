<?php
/**
 * Reply Cron Dispatcher
 *
 * Listens for the reply-detection action and schedules a WP-Cron event
 * to generate the AI response out-of-band, so the user's comment insert
 * returns immediately.
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dispatches reply generation onto WP-Cron.
 */
class Reply_Cron_Dispatcher {

	/**
	 * Cron hook name invoked to generate a reply.
	 *
	 * Args on the scheduled event: ( int $comment_id, int $parent_id, int $post_id, string $block_id ).
	 */
	public const CRON_HOOK = 'ai_feedback_generate_reply';

	/**
	 * Comment meta key tracking reply processing state.
	 *
	 * Values: 'pending' | 'complete' | 'failed'.
	 */
	public const STATUS_META_KEY = 'ai_feedback_reply_status';

	/**
	 * Comment meta key holding the generated AI reply's comment ID.
	 */
	public const REPLY_COMMENT_ID_META_KEY = 'ai_feedback_reply_comment_id';

	/**
	 * Comment meta key holding the error message when generation failed.
	 */
	public const REPLY_ERROR_META_KEY = 'ai_feedback_reply_error';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action(
			Reply_Detector::ACTION_REPLY_RECEIVED,
			array( $this, 'schedule_reply' ),
			10,
			4
		);
	}

	/**
	 * Listener for Reply_Detector::ACTION_REPLY_RECEIVED.
	 *
	 * Marks the reply as pending and schedules a single cron event. WP-Cron
	 * already deduplicates by ( hook, args ), but we also short-circuit on
	 * existing status to avoid re-pending a completed reply (e.g. if the
	 * hook fires twice for the same insert).
	 *
	 * @param int    $comment_id Reply comment ID.
	 * @param int    $parent_id  Parent note comment ID.
	 * @param int    $post_id    Post ID.
	 * @param string $block_id   Block ID from the parent note.
	 */
	public function schedule_reply( int $comment_id, int $parent_id, int $post_id, string $block_id ): void {
		$existing_status = get_comment_meta( $comment_id, self::STATUS_META_KEY, true );
		if ( '' !== $existing_status && false !== $existing_status ) {
			return;
		}

		$args = array( $comment_id, $parent_id, $post_id, $block_id );

		if ( wp_next_scheduled( self::CRON_HOOK, $args ) ) {
			return;
		}

		// Mark pending before scheduling so a fast cron run cannot finish first
		// and have its result overwritten.
		update_comment_meta( $comment_id, self::STATUS_META_KEY, 'pending' );

		$scheduled = wp_schedule_single_event( time(), self::CRON_HOOK, $args, true );
		if ( true !== $scheduled ) {
			// Without an event nothing would ever move the reply out of pending.
			update_comment_meta( $comment_id, self::STATUS_META_KEY, 'failed' );
			update_comment_meta(
				$comment_id,
				self::REPLY_ERROR_META_KEY,
				__( 'The AI reply could not be scheduled.', 'ai-feedback' )
			);
		}
	}
}
