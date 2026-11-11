<?php
/**
 * Review History
 *
 * Keeps a short, per-post record of completed reviews in post meta.
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

/**
 * Review History class.
 */
class Review_History {

	/**
	 * Post meta key holding the history entries.
	 *
	 * @var string
	 */
	const META_KEY = '_ai_feedback_history';

	/**
	 * Maximum number of entries kept per post.
	 *
	 * @var int
	 */
	const MAX_ENTRIES = 10;

	/**
	 * Maximum stored length of the AI summary, in characters.
	 *
	 * @var int
	 */
	const MAX_SUMMARY_LENGTH = 2000;

	/**
	 * Register the history meta for all post types.
	 *
	 * Exposed in the REST API for the `edit` context only, and read-only:
	 * entries are written by the server when a review completes.
	 */
	public static function register_meta(): void {
		register_post_meta(
			'',
			self::META_KEY,
			array(
				'type'          => 'array',
				'single'        => true,
				'default'       => array(),
				'description'   => __( 'Recent AI Feedback reviews for this post.', 'ai-feedback' ),
				'auth_callback' => '__return_false',
				'show_in_rest'  => array(
					'schema' => array(
						'context' => array( 'edit' ),
						'items'   => self::get_entry_schema(),
					),
				),
			)
		);
	}

	/**
	 * JSON schema for a single history entry.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_entry_schema(): array {
		$count_map = array(
			'type'                 => 'object',
			'additionalProperties' => array( 'type' => 'integer' ),
		);

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'review_id'    => array( 'type' => 'string' ),
				'timestamp'    => array( 'type' => 'string' ),
				'model'        => array( 'type' => 'string' ),
				'note_count'   => array( 'type' => 'integer' ),
				'notes_failed' => array( 'type' => 'boolean' ),
				'user_id'      => array( 'type' => 'integer' ),
				'summary_text' => array(
					'type'      => 'string',
					'maxLength' => self::MAX_SUMMARY_LENGTH,
				),
				'summary'      => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'by_severity' => $count_map,
						'by_category' => $count_map,
					),
				),
			),
		);
	}

	/**
	 * Record a completed review.
	 *
	 * Hooked to `ai_feedback_after_review`.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $review  Review result returned by Review_Service.
	 */
	public static function record( int $post_id, array $review ): void {
		$entry = self::build_entry( $review );
		if ( '' === $entry['review_id'] ) {
			return;
		}

		// Read-modify-write: two reviews finishing at the same instant on one
		// post could drop an entry. Acceptable for a short display history.
		$history   = self::get( $post_id );
		$history[] = $entry;
		$history   = array_slice( $history, -self::MAX_ENTRIES );

		update_post_meta( $post_id, self::META_KEY, $history );
	}

	/**
	 * Get history entries for a post, oldest first.
	 *
	 * @param  int $post_id Post ID.
	 * @return list<array<mixed>>
	 */
	public static function get( int $post_id ): array {
		$history = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! is_array( $history ) ) {
			return array();
		}

		return array_values( array_filter( $history, 'is_array' ) );
	}

	/**
	 * Find one history entry by review ID.
	 *
	 * @param  int    $post_id   Post ID.
	 * @param  string $review_id Review ID.
	 * @return array<mixed>|null
	 */
	public static function find( int $post_id, string $review_id ): ?array {
		foreach ( self::get( $post_id ) as $entry ) {
			if ( ( $entry['review_id'] ?? '' ) === $review_id ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Reduce a full review result to the fields kept in history.
	 *
	 * @param  array<string, mixed> $review Review result.
	 * @return array{review_id: string, timestamp: string, model: string, note_count: int, notes_failed: bool, user_id: int, summary_text: string, summary: array{by_severity: array<string, int>, by_category: array<string, int>}}
	 */
	public static function build_entry( array $review ): array {
		$summary = is_array( $review['summary'] ?? null ) ? $review['summary'] : array();

		return array(
			'review_id'    => is_string( $review['review_id'] ?? null ) ? $review['review_id'] : '',
			'timestamp'    => is_string( $review['timestamp'] ?? null ) ? $review['timestamp'] : '',
			'model'        => is_string( $review['model'] ?? null ) ? $review['model'] : '',
			'note_count'   => is_numeric( $review['note_count'] ?? null ) ? (int) $review['note_count'] : 0,
			'notes_failed' => ! empty( $review['notes_error'] ),
			'user_id'      => get_current_user_id(),
			'summary_text' => self::sanitize_summary_text( $review['summary_text'] ?? '' ),
			'summary'      => array(
				'by_severity' => self::int_map( $summary['by_severity'] ?? array() ),
				'by_category' => self::int_map( $summary['by_category'] ?? array() ),
			),
		);
	}

	/**
	 * Get the stored AI summary for a review, or an empty string.
	 *
	 * Lets reviews rebuilt from notes after a reload keep their summary.
	 *
	 * @param  int    $post_id   Post ID.
	 * @param  string $review_id Review ID.
	 * @return string
	 */
	public static function get_summary_text( int $post_id, string $review_id ): string {
		$entry = self::find( $post_id, $review_id );

		return is_string( $entry['summary_text'] ?? null ) ? $entry['summary_text'] : '';
	}

	/**
	 * Sanitize the AI summary as plain text and cap its length.
	 *
	 * @param  mixed $text Raw summary.
	 * @return string
	 */
	public static function sanitize_summary_text( mixed $text ): string {
		if ( ! is_string( $text ) ) {
			return '';
		}

		return mb_substr( sanitize_textarea_field( $text ), 0, self::MAX_SUMMARY_LENGTH );
	}

	/**
	 * Normalize a count map to string keys and integer values.
	 *
	 * @param  mixed $counts Raw count map.
	 * @return array<string, int>
	 */
	private static function int_map( mixed $counts ): array {
		$normalized = array();
		if ( ! is_array( $counts ) ) {
			return $normalized;
		}

		foreach ( $counts as $key => $count ) {
			if ( is_numeric( $count ) ) {
				$normalized[ (string) $key ] = (int) $count;
			}
		}

		return $normalized;
	}
}
