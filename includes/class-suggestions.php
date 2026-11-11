<?php
/**
 * Gutenberg Suggestion Mode integration.
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the Gutenberg suggestions that AI quick fixes become.
 *
 * A suggestion is a note carrying a JSON payload in `_wp_suggestion` comment
 * meta, plus `<mark>` markers in the block content. The editor writes both
 * through the core comments endpoint, the same way Gutenberg's own suggester
 * does. Requires the Gutenberg "Suggestion Mode" experiment.
 */
class Suggestions {

	/**
	 * Gutenberg experiment that provides suggestions.
	 */
	public const EXPERIMENT = 'gutenberg-suggestion-mode';

	/**
	 * Comment meta key holding the suggestion payload.
	 */
	public const PAYLOAD_META = '_wp_suggestion';

	/**
	 * Comment meta key holding the suggestion lifecycle status.
	 */
	public const STATUS_META = '_wp_suggestion_status';

	/**
	 * Checks whether Gutenberg's suggestion storage is available.
	 *
	 * @return bool True when the experiment is on and its meta is registered.
	 */
	public static function is_available(): bool {
		$available = function_exists( 'gutenberg_is_experiment_enabled' )
			&& gutenberg_is_experiment_enabled( self::EXPERIMENT )
			&& registered_meta_key_exists( 'comment', self::PAYLOAD_META );

		/**
		 * Filters whether AI quick fixes are added as Gutenberg suggestions.
		 *
		 * @param bool $available Whether Suggestion Mode is available.
		 */
		return (bool) apply_filters( 'ai_feedback_use_suggestions', $available );
	}

	/**
	 * Returns a note's suggestion status.
	 *
	 * @param  int $note_id Note ID.
	 * @return string|null Status, or null when the note is not a suggestion.
	 */
	public function get_status( int $note_id ): ?string {
		$payload = get_comment_meta( $note_id, self::PAYLOAD_META, true );
		if ( ! is_string( $payload ) || '' === $payload ) {
			return null;
		}

		$status = get_comment_meta( $note_id, self::STATUS_META, true );
		return is_string( $status ) && '' !== $status ? $status : 'pending';
	}

	/**
	 * Lists pending AI suggestions whose markers the saved post should hold.
	 *
	 * Markers live in the post content, so a suggestion created after the
	 * last save may only exist in another editor's unsaved content. Only
	 * suggestions older than the last save are listed, and none while a
	 * newer autosave exists, since restoring it brings markers back.
	 *
	 * @param  int $post_id Post ID.
	 * @return list<int> Note IDs.
	 */
	public function get_saved_pending_ids( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! self::is_available() ) {
			return array();
		}

		$autosave = wp_get_post_autosave( $post_id );
		if ( $autosave instanceof \WP_Post && $autosave->post_modified_gmt > $post->post_modified_gmt ) {
			return array();
		}

		$notes = get_comments(
			array(
				'post_id'    => $post_id,
				'type'       => 'note',
				'status'     => 'all',
				'parent'     => 0,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'   => 'ai_feedback',
						'value' => '1',
					),
					array(
						'key'     => self::PAYLOAD_META,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$ids = array();
		foreach ( is_array( $notes ) ? $notes : array() as $note ) {
			if ( ! $note instanceof \WP_Comment ) {
				continue;
			}
			$note_id = (int) $note->comment_ID;
			if ( 'pending' === $this->get_status( $note_id ) && $post->post_modified_gmt > $note->comment_date_gmt ) {
				$ids[] = $note_id;
			}
		}

		return $ids;
	}
}
