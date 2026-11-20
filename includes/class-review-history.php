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
				'description'   => __( 'Recent Fresh Eyes reviews for this post.', 'fresh-eyes' ),
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
				'review_id'        => array( 'type' => 'string' ),
				'timestamp'        => array( 'type' => 'string' ),
				'model'            => array( 'type' => 'string' ),
				'note_count'       => array( 'type' => 'integer' ),
				'notes_failed'     => array( 'type' => 'boolean' ),
				'user_id'          => array( 'type' => 'integer' ),
				'summary_text'     => array(
					'type'      => 'string',
					'maxLength' => self::MAX_SUMMARY_LENGTH,
				),
				'persona'          => array( 'type' => 'string' ),
				'persona_label'    => array( 'type' => 'string' ),
				'persona_takeaway' => array(
					'type'      => 'string',
					'maxLength' => Personas::MAX_TAKEAWAY_LENGTH,
				),
				'summary'          => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'by_severity' => $count_map,
						'by_category' => $count_map,
					),
				),
				'strengths'        => array(
					'type'     => 'array',
					'maxItems' => Response_Parser::MAX_STRENGTHS,
					'items'    => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(
							'block_id'     => array( 'type' => 'string' ),
							'block_name'   => array( 'type' => 'string' ),
							'block_index'  => array( 'type' => array( 'integer', 'null' ) ),
							'block_anchor' => array( 'type' => 'string' ),
							'text'         => array(
								'type'      => 'string',
								'maxLength' => Response_Parser::MAX_STRENGTH_LENGTH,
							),
						),
					),
				),
				'progress'         => array(
					'description'          => __( 'How the notes left open by the previous review stood when this one ran. Null for a post\'s first review.', 'fresh-eyes' ),
					'type'                 => array( 'object', 'null' ),
					'additionalProperties' => false,
					'properties'           => array(
						'addressed'  => array( 'type' => 'integer' ),
						'still_open' => array( 'type' => 'integer' ),
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
		// History is a convenience, so a failure here must not fail the review.
		try {
			$entry = self::build_entry( $review );
			if ( '' === $entry['review_id'] ) {
				return;
			}

			$note_ids = self::int_list( $review['note_ids'] ?? array() );

			// Read-modify-write: two reviews finishing at the same instant on one
			// post could drop an entry. Acceptable for a short display history.
			$history = self::get( $post_id );

			// Each part of a chunked review reports separately under one ID.
			$existing = null;
			foreach ( $history as $index => $stored ) {
				if ( ( $stored['review_id'] ?? '' ) === $entry['review_id'] ) {
					$existing = $index;
				}
			}

			if ( null !== $existing ) {
				$history[ $existing ] = self::merge_part( $history[ $existing ], $entry, $note_ids );
			} else {
				$previous = end( $history );
				if ( is_array( $previous ) ) {
					$comparison             = Review_Progress::compare( $post_id, $previous );
					$entry['progress']      = array(
						'addressed'  => $comparison['addressed'],
						'still_open' => $comparison['still_open'],
					);
					$entry['open_note_ids'] = Review_Progress::merge_ids( $comparison['open_ids'], $note_ids );
				} else {
					$entry['open_note_ids'] = Review_Progress::merge_ids( $note_ids );
				}

				// Only the newest entry's open notes are ever compared.
				foreach ( $history as $index => $stored ) {
					unset( $history[ $index ]['open_note_ids'] );
				}
				$history[] = $entry;
			}
			$history = array_slice( $history, -self::MAX_ENTRIES );

			// update_post_meta() unslashes, which would strip backslashes from the summary.
			update_post_meta( $post_id, self::META_KEY, wp_slash( $history ) );
		} catch ( \Throwable $e ) {
			Logger::debug( sprintf( 'Could not record review history: %s', $e->getMessage() ) );
		}
	}

	/**
	 * An entry as the editor sees it, without the bookkeeping for the next comparison.
	 *
	 * @param  array<mixed> $entry History entry.
	 * @return array<mixed>
	 */
	public static function for_response( array $entry ): array {
		unset( $entry['open_note_ids'] );

		return $entry;
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
	 * Fold a later part of a chunked review into its entry.
	 *
	 * The comparison with the previous review was made by the first part,
	 * before this review created any notes, so it is kept.
	 *
	 * @param  array<mixed>         $stored   Entry recorded by the earlier parts.
	 * @param  array<string, mixed> $part     Entry built from this part.
	 * @param  int[]                $note_ids Notes this part created.
	 * @return array<mixed>
	 */
	private static function merge_part( array $stored, array $part, array $note_ids ): array {
		$stored_summary = is_array( $stored['summary'] ?? null ) ? $stored['summary'] : array();
		$part_summary   = is_array( $part['summary'] ?? null ) ? $part['summary'] : array();

		$stored['timestamp']     = $part['timestamp'];
		$stored['note_count']    = ( is_int( $stored['note_count'] ?? null ) ? $stored['note_count'] : 0 ) + ( is_int( $part['note_count'] ?? null ) ? $part['note_count'] : 0 );
		$stored['notes_failed']  = ! empty( $stored['notes_failed'] ) || ! empty( $part['notes_failed'] );
		$stored['summary']       = array(
			'by_severity' => self::add_counts( $stored_summary['by_severity'] ?? array(), $part_summary['by_severity'] ?? array() ),
			'by_category' => self::add_counts( $stored_summary['by_category'] ?? array(), $part_summary['by_category'] ?? array() ),
		);
		$stored['strengths']     = self::sanitize_strengths( array_merge( is_array( $stored['strengths'] ?? null ) ? $stored['strengths'] : array(), is_array( $part['strengths'] ?? null ) ? $part['strengths'] : array() ) );
		$stored['open_note_ids'] = Review_Progress::merge_ids( self::int_list( $stored['open_note_ids'] ?? array() ), $note_ids );

		// The last part's summary covers the whole document.
		if ( is_string( $part['summary_text'] ?? null ) && '' !== $part['summary_text'] ) {
			$stored['summary_text'] = $part['summary_text'];
		}

		// Every part runs as the same persona, and the last takeaway covers the
		// whole document, like the summary.
		if ( is_string( $part['persona_takeaway'] ?? null ) && '' !== $part['persona_takeaway'] ) {
			$stored['persona_takeaway'] = $part['persona_takeaway'];
		}

		return $stored;
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
	 * @return array{review_id: string, timestamp: string, model: string, note_count: int, notes_failed: bool, user_id: int, summary_text: string, persona?: string, persona_label?: string, persona_takeaway?: string, summary: array{by_severity: array<string, int>, by_category: array<string, int>}, strengths: list<array{block_id: string, block_name: string, block_index: int|null, block_anchor: string, text: string}>}
	 */
	public static function build_entry( array $review ): array {
		$summary = is_array( $review['summary'] ?? null ) ? $review['summary'] : array();

		return self::build_persona_fields( $review ) + array(
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
			'strengths'    => self::sanitize_strengths( $review['strengths'] ?? array() ),
		);
	}

	/**
	 * Keep only well-formed strengths, as plain text, one per block and
	 * capped in number. Also merges the strengths of a chunked review's
	 * parts, in order, like mergeStrengths() in src/utils/strengths.js.
	 *
	 * @param  mixed $strengths Strengths from the review result.
	 * @return list<array{block_id: string, block_name: string, block_index: int|null, block_anchor: string, text: string}>
	 */
	public static function sanitize_strengths( mixed $strengths ): array {
		$max   = Response_Parser::get_max_strengths();
		$clean = array();
		if ( 0 === $max ) {
			return array();
		}
		foreach ( is_array( $strengths ) ? $strengths : array() as $strength ) {
			if ( ! is_array( $strength ) || ! is_string( $strength['block_id'] ?? null ) || '' === $strength['block_id'] ) {
				continue;
			}
			$text = Response_Parser::sanitize_strength_text( $strength['text'] ?? null );
			if ( '' === $text ) {
				continue;
			}
			$block_id = sanitize_text_field( $strength['block_id'] );
			if ( isset( $clean[ $block_id ] ) ) {
				continue;
			}
			$clean[ $block_id ] = array(
				'block_id'     => $block_id,
				'block_name'   => is_string( $strength['block_name'] ?? null ) ? sanitize_text_field( $strength['block_name'] ) : '',
				'block_index'  => is_int( $strength['block_index'] ?? null ) ? $strength['block_index'] : null,
				'block_anchor' => is_string( $strength['block_anchor'] ?? null ) && 1 === preg_match( '/^[a-z0-9]{1,32}$/', $strength['block_anchor'] ) ? $strength['block_anchor'] : '',
				'text'         => $text,
			);
			if ( count( $clean ) >= $max ) {
				break;
			}
		}

		return array_values( $clean );
	}

	/**
	 * Get what a review stored for the editor to show after a reload.
	 *
	 * @param  int    $post_id   Post ID.
	 * @param  string $review_id Review ID.
	 * @return array{strengths: list<array<mixed>>, progress: array{addressed: int, still_open: int}|null}
	 */
	public static function get_review_extras( int $post_id, string $review_id ): array {
		$entry    = self::find( $post_id, $review_id );
		$progress = is_array( $entry['progress'] ?? null ) ? $entry['progress'] : null;

		return array(
			'strengths' => self::sanitize_strengths( $entry['strengths'] ?? array() ),
			'progress'  => null === $progress ? null : array(
				'addressed'  => is_int( $progress['addressed'] ?? null ) ? $progress['addressed'] : 0,
				'still_open' => is_int( $progress['still_open'] ?? null ) ? $progress['still_open'] : 0,
			),
		);
	}

	/**
	 * Read a list of positive integer IDs.
	 *
	 * @param  mixed $ids Raw IDs.
	 * @return list<int>
	 */
	private static function int_list( mixed $ids ): array {
		$clean = array();
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$clean[] = (int) $id;
			}
		}

		return $clean;
	}

	/**
	 * Add two count maps.
	 *
	 * @param  mixed $a First counts.
	 * @param  mixed $b Second counts.
	 * @return array<string, int>
	 */
	private static function add_counts( mixed $a, mixed $b ): array {
		$sum = self::int_map( $a );
		foreach ( self::int_map( $b ) as $key => $count ) {
			$sum[ $key ] = ( $sum[ $key ] ?? 0 ) + $count;
		}

		return $sum;
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
	 * Get the persona fields of a review, for a history entry.
	 *
	 * @param  array<mixed> $review Review result or history entry.
	 * @return array{persona?: string, persona_label?: string, persona_takeaway?: string} Fields, empty for the default persona.
	 */
	public static function build_persona_fields( array $review ): array {
		$persona = $review['persona'] ?? null;
		// A persona a filter has since removed is left out, as its notes lose their badge.
		if ( ! is_string( $persona ) || ! Personas::is_custom( $persona ) ) {
			return array();
		}

		return array(
			'persona'          => $persona,
			'persona_label'    => Personas::get_label( $persona ),
			'persona_takeaway' => Personas::sanitize_takeaway( $review['persona_takeaway'] ?? '' ),
		);
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
