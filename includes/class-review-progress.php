<?php
/**
 * Review Progress
 *
 * Works out how much of the earlier feedback on a post has been addressed,
 * so a follow-up review can report what changed since the last one.
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

/**
 * Review Progress class.
 *
 * A note counts as addressed when it was dismissed (or its quick fix
 * applied) in the sidebar, its suggested edit was accepted or rejected, or
 * its thread was resolved in the Notes panel. A note that was deleted is
 * not counted at all. src/utils/progress.js applies the same rules in the
 * editor.
 */
class Review_Progress {

	/**
	 * Suggestion statuses that mean the author acted on a suggested edit.
	 *
	 * @var string[]
	 */
	const ADDRESSED_SUGGESTION_STATUSES = array( 'applied', 'applied-unsaved', 'rejected', 'rejected-unsaved' );

	/**
	 * Most open note IDs kept with a history entry.
	 *
	 * @var int
	 */
	const MAX_OPEN_NOTES = 500;

	/**
	 * Whether the author has dealt with a note.
	 *
	 * @param  \WP_Comment $note Note.
	 * @return bool
	 */
	public static function is_addressed( \WP_Comment $note ): bool {
		$note_id = (int) $note->comment_ID;

		if ( get_comment_meta( $note_id, 'ai_feedback_resolved', true ) ) {
			return true;
		}

		$status = ( new Suggestions() )->get_status( $note_id );
		if ( null !== $status && in_array( $status, self::ADDRESSED_SUGGESTION_STATUSES, true ) ) {
			return true;
		}

		// Core marks a resolved thread by approving its top-level note.
		$parent = (int) $note->comment_parent;
		$thread = $parent > 0 ? get_comment( $parent ) : $note;

		return $thread instanceof \WP_Comment && '1' === (string) $thread->comment_approved;
	}

	/**
	 * IDs of the AI feedback notes a review created on a post.
	 *
	 * @param  int    $post_id   Post ID.
	 * @param  string $review_id Review ID.
	 * @return list<int>
	 */
	public static function get_review_note_ids( int $post_id, string $review_id ): array {
		if ( '' === $review_id ) {
			return array();
		}

		$notes = get_comments(
			array(
				'post_id'    => $post_id,
				'type'       => 'note',
				'status'     => 'all',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query' => array(
					array(
						'key'   => 'review_id',
						'value' => $review_id,
					),
				),
			)
		);

		$ids = array();
		foreach ( $notes as $note ) {
			if ( (int) $note->comment_post_ID === $post_id ) {
				$ids[] = (int) $note->comment_ID;
			}
		}

		return $ids;
	}

	/**
	 * Compare the notes left open by the previous review with their state now.
	 *
	 * Uses the open note IDs the previous history entry kept. Entries
	 * recorded before those were kept fall back to the notes the previous
	 * review created.
	 *
	 * @param  int          $post_id  Post ID.
	 * @param  array<mixed> $previous Previous history entry.
	 * @return array{addressed: int, still_open: int, open_ids: list<int>}
	 */
	public static function compare( int $post_id, array $previous ): array {
		if ( is_array( $previous['open_note_ids'] ?? null ) ) {
			$note_ids = array_filter( $previous['open_note_ids'], 'is_int' );
		} else {
			$review_id = is_string( $previous['review_id'] ?? null ) ? $previous['review_id'] : '';
			$note_ids  = self::get_review_note_ids( $post_id, $review_id );
		}

		$addressed = 0;
		$open_ids  = array();
		foreach ( array_unique( $note_ids ) as $note_id ) {
			$note = get_comment( $note_id );
			// Deleted notes, and IDs that are not notes on this post, don't count.
			if ( ! $note instanceof \WP_Comment || 'note' !== $note->comment_type || (int) $note->comment_post_ID !== $post_id ) {
				continue;
			}

			if ( self::is_addressed( $note ) ) {
				++$addressed;
			} else {
				$open_ids[] = (int) $note->comment_ID;
			}
		}

		return array(
			'addressed'  => $addressed,
			'still_open' => count( $open_ids ),
			'open_ids'   => $open_ids,
		);
	}

	/**
	 * Merge note ID lists, keeping the newest when there are too many.
	 *
	 * @param  array<mixed> ...$lists Note ID lists, oldest first.
	 * @return list<int>
	 */
	public static function merge_ids( array ...$lists ): array {
		$ids = array_values( array_unique( array_filter( array_merge( ...$lists ), 'is_int' ) ) );

		return array_slice( $ids, -self::MAX_OPEN_NOTES );
	}
}
