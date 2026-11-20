<?php
/**
 * Reader personas
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

/**
 * The readers a review can be run as, such as a skeptical expert or a busy
 * skimmer, and the optional audience the author describes.
 *
 * The default persona is the copy editor, which adds nothing to the prompt,
 * so a review with it reads exactly as one did before personas existed.
 */
class Personas {


	/**
	 * Persona used when none is chosen, or the chosen one no longer exists.
	 */
	public const DEFAULT_PERSONA = 'copy_editor';

	/**
	 * Comment meta key holding the persona a note was written as.
	 */
	public const META_KEY = 'ai_feedback_persona';

	/**
	 * Maximum length of the "Who is this for?" audience.
	 */
	public const MAX_AUDIENCE_LENGTH = 100;

	/**
	 * Maximum length of the persona's one line takeaway in the summary.
	 */
	public const MAX_TAKEAWAY_LENGTH = 200;

	/**
	 * Maximum length of a persona's prompt guidance.
	 */
	private const MAX_GUIDANCE_LENGTH = 1000;

	/**
	 * Get every persona, keyed by ID.
	 *
	 * @return array<string, array{label: string, description: string, guidance: string}> Personas.
	 */
	public static function get_all(): array {
		$built_in = array(
			self::DEFAULT_PERSONA => array(
				'label'       => __( 'Copy editor', 'fresh-eyes' ),
				'description' => __( 'A general editorial review, the same as without a persona.', 'fresh-eyes' ),
				'guidance'    => '',
			),
			'skeptical_expert'    => array(
				'label'       => __( 'Skeptical expert', 'fresh-eyes' ),
				'description' => __( 'Questions claims and asks for sources.', 'fresh-eyes' ),
				'guidance'    => 'An expert in the subject who doubts every claim until it is backed up. Question statistics, comparisons and superlatives that have no source or baseline, point out where an argument skips a step, and ask for evidence, links or numbers. Leave minor style issues alone.',
			),
			'first_time_visitor'  => array(
				'label'       => __( 'First-time visitor', 'fresh-eyes' ),
				'description' => __( 'Flags jargon and assumed knowledge.', 'fresh-eyes' ),
				'guidance'    => 'Someone new to the subject, reading this page for the first time. Flag jargon, acronyms and terms used without explanation, steps or context the author assumes the reader already knows, and anything that would make a newcomer stop to look something up. Leave minor style issues alone.',
			),
			'busy_skimmer'        => array(
				'label'       => __( 'Busy skimmer', 'fresh-eyes' ),
				'description' => __( 'Reads headings and first sentences only, and reports what they took away.', 'fresh-eyes' ),
				'guidance'    => 'A busy reader on a phone who only reads the headings and the first sentence of each paragraph. Report what they would take away and what they would miss: key points buried in the middle of a paragraph, long paragraphs, and headings that do not say what their section is about. Leave minor style issues alone.',
			),
		);

		/**
		 * Filters the reader personas a review can be run as.
		 *
		 * Keys are persona IDs: lowercase letters, numbers, `_` and `-`, at
		 * most 40 characters. `guidance` is added to the review prompt and
		 * describes who the reader is and what they notice. The copy editor
		 * is always available and adds nothing to the prompt.
		 *
		 * @param array<string, array{label: string, description?: string, guidance: string}> $personas Personas keyed by ID.
		 */
		$personas = self::sanitize_personas( apply_filters( 'ai_feedback_personas', $built_in ) );

		// The default is always offered, and always reviews as before.
		$default             = $personas[ self::DEFAULT_PERSONA ] ?? $built_in[ self::DEFAULT_PERSONA ];
		$default['guidance'] = '';
		unset( $personas[ self::DEFAULT_PERSONA ] );

		return array( self::DEFAULT_PERSONA => $default ) + $personas;
	}

	/**
	 * Validate the personas returned by the filter.
	 *
	 * @param  mixed $personas Filtered personas.
	 * @return array<string, array{label: string, description: string, guidance: string}> Valid personas.
	 */
	private static function sanitize_personas( mixed $personas ): array {
		$valid = array();
		foreach ( is_array( $personas ) ? $personas : array() as $id => $persona ) {
			$clean = self::sanitize_persona( $id, $persona );
			if ( null !== $clean && is_string( $id ) ) {
				$valid[ $id ] = $clean;
			}
		}
		return $valid;
	}

	/**
	 * Validate one persona from the filter.
	 *
	 * @param  mixed $id      Persona ID.
	 * @param  mixed $persona Persona definition.
	 * @return array{label: string, description: string, guidance: string}|null Persona, or null when invalid.
	 */
	private static function sanitize_persona( mixed $id, mixed $persona ): ?array {
		if ( ! is_string( $id ) || 1 !== preg_match( '/^[a-z0-9_-]{1,40}$/', $id ) || ! is_array( $persona ) ) {
			return null;
		}

		$label = is_string( $persona['label'] ?? null ) ? sanitize_text_field( $persona['label'] ) : '';
		if ( '' === $label ) {
			return null;
		}

		$description = is_string( $persona['description'] ?? null ) ? sanitize_text_field( $persona['description'] ) : '';
		$guidance    = is_string( $persona['guidance'] ?? null ) ? sanitize_textarea_field( $persona['guidance'] ) : '';

		return array(
			'label'       => $label,
			'description' => $description,
			'guidance'    => mb_substr( $guidance, 0, self::MAX_GUIDANCE_LENGTH ),
		);
	}

	/**
	 * Whether a persona ID is available.
	 *
	 * @param  mixed $id Persona ID.
	 * @return bool
	 */
	public static function is_valid( mixed $id ): bool {
		return is_string( $id ) && isset( self::get_all()[ $id ] );
	}

	/**
	 * Get an available persona ID, falling back to the default.
	 *
	 * @param  mixed $id Persona ID.
	 * @return string Persona ID.
	 */
	public static function normalize( mixed $id ): string {
		return self::is_valid( $id ) && is_string( $id ) ? $id : self::DEFAULT_PERSONA;
	}

	/**
	 * Whether a persona changes the review, which every persona but the
	 * default does.
	 *
	 * @param  mixed $id Persona ID.
	 * @return bool
	 */
	public static function is_custom( mixed $id ): bool {
		return self::DEFAULT_PERSONA !== self::normalize( $id );
	}

	/**
	 * Get a persona's label.
	 *
	 * @param  mixed $id Persona ID.
	 * @return string Label, or an empty string for an unknown persona.
	 */
	public static function get_label( mixed $id ): string {
		return is_string( $id ) ? ( self::get_all()[ $id ]['label'] ?? '' ) : '';
	}

	/**
	 * Get a persona's prompt guidance.
	 *
	 * @param  mixed $id Persona ID.
	 * @return string Guidance, or an empty string for the default or an unknown persona.
	 */
	public static function get_guidance( mixed $id ): string {
		return is_string( $id ) ? ( self::get_all()[ $id ]['guidance'] ?? '' ) : '';
	}

	/**
	 * List the personas for the settings endpoint.
	 *
	 * @return list<array{id: string, label: string, description: string}> Personas.
	 */
	public static function get_for_rest(): array {
		$list = array();
		foreach ( self::get_all() as $id => $persona ) {
			$list[] = array(
				'id'          => $id,
				'label'       => $persona['label'],
				'description' => $persona['description'],
			);
		}
		return $list;
	}

	/**
	 * Sanitize the "Who is this for?" audience as one line of plain text.
	 *
	 * @param  mixed $audience Raw audience.
	 * @return string Audience, at most MAX_AUDIENCE_LENGTH characters.
	 */
	public static function sanitize_audience( mixed $audience ): string {
		if ( ! is_string( $audience ) ) {
			return '';
		}
		return trim( mb_substr( sanitize_text_field( $audience ), 0, self::MAX_AUDIENCE_LENGTH ) );
	}

	/**
	 * Sanitize the persona's takeaway from the AI response.
	 *
	 * @param  mixed $takeaway Raw takeaway.
	 * @return string Takeaway as one line of plain text, capped in length.
	 */
	public static function sanitize_takeaway( mixed $takeaway ): string {
		if ( ! is_string( $takeaway ) ) {
			return '';
		}
		$takeaway = sanitize_text_field( $takeaway );
		if ( mb_strlen( $takeaway ) > self::MAX_TAKEAWAY_LENGTH ) {
			$takeaway = mb_substr( $takeaway, 0, self::MAX_TAKEAWAY_LENGTH - 3 ) . '...';
		}
		return $takeaway;
	}
}
