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
}
