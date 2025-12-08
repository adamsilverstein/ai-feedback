<?php
/**
 * Response Parser
 *
 * Parses and validates AI responses.
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

/**
 * Response Parser class.
 */
class Response_Parser {

	/**
	 * Parse feedback response from AI.
	 *
	 * @param string $response AI response (expected JSON).
	 * @param array  $blocks   Original blocks for validation.
	 * @return array Parsed and validated feedback items.
	 */
	public function parse_feedback( string $response, array $blocks ): array {
		// Extract JSON from response (in case AI added text before/after).
		$json = $this->extract_json( $response );

		if ( empty( $json ) ) {
			return array();
		}

		// Decode JSON.
		$feedback_items = json_decode( $json, true );

		// Validate it's an array.
		if ( ! is_array( $feedback_items ) ) {
			return array();
		}

		// Parse and validate each feedback item.
		$parsed = array();
		foreach ( $feedback_items as $item ) {
			$validated = $this->validate_feedback_item( $item, $blocks );
			if ( $validated ) {
				$parsed[] = $validated;
			}
		}

		return $parsed;
	}

	/**
	 * Extract JSON from response text.
	 *
	 * Sometimes AI adds explanation text before/after JSON.
	 * This extracts the JSON array.
	 *
	 * @param string $response Response text.
	 * @return string JSON string or empty.
	 */
	private function extract_json( string $response ): string {
		// Try to find JSON array in response.
		if ( preg_match( '/\[.*\]/s', $response, $matches ) ) {
			return $matches[0];
		}

		// If response is already pure JSON, return it.
		$response = trim( $response );
		if ( strpos( $response, '[' ) === 0 ) {
			return $response;
		}

		return '';
	}

	/**
	 * Validate a single feedback item.
	 *
	 * @param mixed $item   Feedback item data.
	 * @param array $blocks Original blocks.
	 * @return array|null Validated item or null if invalid.
	 */
	private function validate_feedback_item( $item, array $blocks ): ?array {
		// Must be an array.
		if ( ! is_array( $item ) ) {
			return null;
		}

		// Required fields.
		$required = array( 'block_index', 'category', 'severity', 'title', 'feedback' );
		foreach ( $required as $field ) {
			if ( ! isset( $item[ $field ] ) ) {
				return null;
			}
		}

		// Validate block index exists.
		$block_index = intval( $item['block_index'] );
		if ( ! isset( $blocks[ $block_index ] ) ) {
			return null;
		}

		// Validate category.
		$valid_categories = array( 'content', 'tone', 'flow', 'design' );
		if ( ! in_array( $item['category'], $valid_categories, true ) ) {
			return null;
		}

		// Validate severity.
		$valid_severities = array( 'suggestion', 'important', 'critical' );
		if ( ! in_array( $item['severity'], $valid_severities, true ) ) {
			return null;
		}

		// Get block ID (client ID for Notes).
		$block_id = $blocks[ $block_index ]['attrs']['metadata']['id'] ?? null;

		// Build validated item.
		$validated = array(
			'block_index' => $block_index,
			'block_id'    => $block_id,
			'category'    => sanitize_text_field( $item['category'] ),
			'severity'    => sanitize_text_field( $item['severity'] ),
			'title'       => $this->sanitize_title( $item['title'] ),
			'feedback'    => $this->sanitize_feedback( $item['feedback'] ),
		);

		// Optional suggestion field.
		if ( isset( $item['suggestion'] ) && ! empty( $item['suggestion'] ) ) {
			$validated['suggestion'] = $this->sanitize_feedback( $item['suggestion'] );
		}

		return $validated;
	}

	/**
	 * Sanitize title text.
	 *
	 * @param string $title Title text.
	 * @return string Sanitized title.
	 */
	private function sanitize_title( string $title ): string {
		$title = sanitize_text_field( $title );

		// Truncate to 50 characters.
		if ( strlen( $title ) > 50 ) {
			$title = substr( $title, 0, 47 ) . '...';
		}

		return $title;
	}

	/**
	 * Sanitize feedback text.
	 *
	 * Allows basic formatting tags.
	 *
	 * @param string $feedback Feedback text.
	 * @return string Sanitized feedback.
	 */
	private function sanitize_feedback( string $feedback ): string {
		// Allow basic formatting.
		$allowed_tags = array(
			'strong' => array(),
			'em'     => array(),
			'code'   => array(),
			'br'     => array(),
		);

		$feedback = wp_kses( $feedback, $allowed_tags );

		// Truncate to 300 characters (increased from 200 to be more flexible).
		if ( strlen( $feedback ) > 300 ) {
			$feedback = substr( $feedback, 0, 297 ) . '...';
		}

		return $feedback;
	}

	/**
	 * Get summary of feedback items.
	 *
	 * @param array $feedback_items Parsed feedback items.
	 * @return array Summary data.
	 */
	public function get_feedback_summary( array $feedback_items ): array {
		$summary = array(
			'total_notes'  => count( $feedback_items ),
			'by_category'  => array(
				'content' => 0,
				'tone'    => 0,
				'flow'    => 0,
				'design'  => 0,
			),
			'by_severity'  => array(
				'suggestion' => 0,
				'important'  => 0,
				'critical'   => 0,
			),
			'has_critical' => false,
		);

		foreach ( $feedback_items as $item ) {
			// Count by category.
			if ( isset( $summary['by_category'][ $item['category'] ] ) ) {
				$summary['by_category'][ $item['category'] ]++;
			}

			// Count by severity.
			if ( isset( $summary['by_severity'][ $item['severity'] ] ) ) {
				$summary['by_severity'][ $item['severity'] ]++;
			}

			// Track if any critical items exist.
			if ( $item['severity'] === 'critical' ) {
				$summary['has_critical'] = true;
			}
		}

		return $summary;
	}
}
