<?php
/**
 * Gutenberg Suggestion Mode integration.
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns AI quick fixes into Gutenberg suggestions that authors accept or reject.
 *
 * A suggestion is a note carrying a JSON payload in `_wp_suggestion` comment
 * meta, plus `<mark>` markers in the block content that the editor writes.
 * Requires the Gutenberg "Suggestion Mode" experiment.
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
	 * Payload schema version this plugin writes.
	 */
	public const SCHEMA_VERSION = 2;

	/**
	 * Statuses that mean the author already acted on the suggestion.
	 */
	public const DECIDED_STATUSES = array( 'applied-unsaved', 'rejected-unsaved', 'applied', 'rejected', 'outdated' );

	/**
	 * Notes manager instance.
	 *
	 * @var Notes_Manager
	 */
	private Notes_Manager $notes_manager;

	/**
	 * Response parser used to re-validate quick fixes.
	 *
	 * @var Response_Parser
	 */
	private Response_Parser $parser;

	/**
	 * Constructor.
	 *
	 * @param Notes_Manager|null   $notes_manager Optional notes manager.
	 * @param Response_Parser|null $parser        Optional response parser.
	 */
	public function __construct( ?Notes_Manager $notes_manager = null, ?Response_Parser $parser = null ) {
		$this->notes_manager = $notes_manager ?? new Notes_Manager();
		$this->parser        = $parser ?? new Response_Parser();
	}

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
	 * Builds the suggestion payload for a quick fix.
	 *
	 * An empty replacement is a deletion; anything else is a replacement, which
	 * Gutenberg stores as an `add` run followed by a `del` run under one id.
	 *
	 * @param  string                               $block_name Block name, eg. `core/paragraph`.
	 * @param  string                               $attribute  Rich text attribute holding the markers.
	 * @param  array{find: string, replace: string} $quick_fix  Quick fix.
	 * @return array{schemaVersion: int, blockName: string, baseRevision: null, operations: list<array{type: string, attribute: string, suggestionType: string}>}
	 */
	public function build_payload( string $block_name, string $attribute, array $quick_fix ): array {
		return array(
			'schemaVersion' => self::SCHEMA_VERSION,
			'blockName'     => $block_name,
			'baseRevision'  => null,
			'operations'    => array(
				array(
					'type'           => 'inline-suggestion',
					'attribute'      => $attribute,
					'suggestionType' => self::suggestion_type( $quick_fix ),
				),
			),
		);
	}

	/**
	 * Returns the inline suggestion type for a quick fix.
	 *
	 * @param  array{find: string, replace: string} $quick_fix Quick fix.
	 * @return string `del` or `replace`.
	 */
	public static function suggestion_type( array $quick_fix ): string {
		return '' === $quick_fix['replace'] ? 'del' : 'replace';
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
	 * Turns an AI note's quick fix into a suggestion.
	 *
	 * The fix must still match the block's current text exactly once. The
	 * editor writes the matching markers into the block after this succeeds.
	 *
	 * @param  int    $note_id    Note ID.
	 * @param  string $block_name Block name.
	 * @param  string $text       Current plain text of the block.
	 * @param  string $attribute  Rich text attribute.
	 * @return array{note_id: int, payload: array<string, mixed>, suggestion_type: string, author: int, status: string}|WP_Error
	 */
	public function create_for_note( int $note_id, string $block_name, string $text, string $attribute = 'content' ): array|WP_Error {
		if ( ! self::is_available() ) {
			return new WP_Error(
				'ai_feedback_suggestions_unavailable',
				__( 'Suggestion mode is not available.', 'ai-feedback' ),
				array( 'status' => 501 )
			);
		}

		$note = $this->get_ai_note( $note_id );
		if ( is_wp_error( $note ) ) {
			return $note;
		}

		if ( (int) $note->comment_parent > 0 ) {
			return new WP_Error(
				'ai_feedback_suggestion_reply',
				__( 'Only top-level notes can carry a suggestion.', 'ai-feedback' ),
				array( 'status' => 400 )
			);
		}

		$quick_fix = $this->notes_manager->decode_quick_fix( get_comment_meta( $note_id, 'feedback_quick_fix', true ) );
		$quick_fix = null === $quick_fix ? null : $this->parser->validate_quick_fix( $quick_fix, $text );
		if ( null === $quick_fix ) {
			return new WP_Error(
				'ai_feedback_suggestion_stale',
				__( 'The suggested text no longer matches the block.', 'ai-feedback' ),
				array( 'status' => 409 )
			);
		}

		$status = $this->get_status( $note_id );
		if ( null !== $status && in_array( $status, self::DECIDED_STATUSES, true ) ) {
			return new WP_Error(
				'ai_feedback_suggestion_decided',
				__( 'This suggestion was already accepted or rejected.', 'ai-feedback' ),
				array( 'status' => 409 )
			);
		}

		$payload = $this->build_payload( $block_name, $attribute, $quick_fix );
		$json    = wp_json_encode( $payload );

		if ( false !== $json ) {
			// Meta writes unslash their value, which would corrupt escaped JSON.
			update_comment_meta( $note_id, self::PAYLOAD_META, wp_slash( $json ) );
		}

		// Gutenberg's sanitize callback stores '' for a payload it rejects.
		if ( false === $json || get_comment_meta( $note_id, self::PAYLOAD_META, true ) !== $json ) {
			return new WP_Error(
				'ai_feedback_suggestion_failed',
				__( 'The suggestion could not be saved.', 'ai-feedback' ),
				array( 'status' => 500 )
			);
		}
		update_comment_meta( $note_id, self::STATUS_META, 'pending' );

		return array(
			'note_id'         => $note_id,
			'payload'         => $payload,
			'suggestion_type' => self::suggestion_type( $quick_fix ),
			'author'          => (int) $note->user_id,
			'status'          => 'pending',
		);
	}

	/**
	 * Turns a pending suggestion back into a plain AI note.
	 *
	 * Used when the editor could not write the markers, or the markers were
	 * never saved, so the note doesn't offer an Accept that can't work.
	 *
	 * @param  int $note_id Note ID.
	 * @return true|WP_Error
	 */
	public function remove_for_note( int $note_id ): bool|WP_Error {
		$note = $this->get_ai_note( $note_id );
		if ( is_wp_error( $note ) ) {
			return $note;
		}

		$status = $this->get_status( $note_id );
		if ( null !== $status && in_array( $status, self::DECIDED_STATUSES, true ) ) {
			return new WP_Error(
				'ai_feedback_suggestion_decided',
				__( 'This suggestion was already accepted or rejected.', 'ai-feedback' ),
				array( 'status' => 409 )
			);
		}

		delete_comment_meta( $note_id, self::PAYLOAD_META );
		delete_comment_meta( $note_id, self::STATUS_META );

		return true;
	}

	/**
	 * Loads a note the plugin created.
	 *
	 * @param  int $note_id Note ID.
	 * @return \WP_Comment|WP_Error
	 */
	private function get_ai_note( int $note_id ): \WP_Comment|WP_Error {
		$note = get_comment( $note_id );

		if ( ! $note instanceof \WP_Comment || 'note' !== $note->comment_type || ! get_comment_meta( $note_id, 'ai_feedback', true ) ) {
			return new WP_Error(
				'invalid_note',
				__( 'Note not found.', 'ai-feedback' ),
				array( 'status' => 404 )
			);
		}

		return $note;
	}
}
