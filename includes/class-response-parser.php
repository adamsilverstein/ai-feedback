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
	 * @return array Parsed result with summary and validated feedback items.
	 */
	public function parse_feedback( string $response, array $blocks ): array {
		// Build a map of valid block IDs for validation.
		$valid_block_ids = array();
		foreach ( $blocks as $block ) {
			if ( ! empty( $block['clientId'] ) ) {
				$valid_block_ids[ $block['clientId'] ] = true;
			}
		}

		// Extract JSON from response (in case AI added text before/after).
		$json = $this->extract_json( $response );

		if ( empty( $json ) ) {
			return array(
				'summary'  => '',
				'feedback' => array(),
			);
		}

		// Decode JSON.
		$data = json_decode( $json, true );

		// Handle new format with summary and feedback.
		if ( is_array( $data ) && isset( $data['feedback'] ) ) {
			$summary        = $data['summary'] ?? '';
			$feedback_items = $data['feedback'];
		} elseif ( is_array( $data ) && isset( $data[0] ) ) {
			// Legacy format - array of feedback items.
			$summary        = '';
			$feedback_items = $data;
		} else {
			return array(
				'summary'  => '',
				'feedback' => array(),
			);
		}

		// Parse and validate each feedback item.
		$parsed = array();
		foreach ( $feedback_items as $item ) {
			$validated = $this->validate_feedback_item( $item, $valid_block_ids );
			if ( $validated ) {
				$parsed[] = $validated;
			}
		}

		return array(
			'summary'  => $this->sanitize_summary( $summary ),
			'feedback' => $parsed,
		);
	}

	/**
	 * Extract JSON from response text.
	 *
	 * Sometimes AI adds explanation text before/after JSON.
	 * This extracts the JSON object or array.
	 *
	 * @param string $response Response text.
	 * @return string JSON string or empty.
	 */
	private function extract_json( string $response ): string {
		$response = trim( $response );

		// Try to find JSON object first (new format with summary).
		if ( preg_match( '/\{.*\}/s', $response, $matches ) ) {
			// Verify it contains our expected structure.
			$test = json_decode( $matches[0], true );
			if ( is_array( $test ) && ( isset( $test['feedback'] ) || isset( $test['summary'] ) ) ) {
				return $matches[0];
			}
		}

		// Try to find JSON array (legacy format).
		if ( preg_match( '/\[.*\]/s', $response, $matches ) ) {
			return $matches[0];
		}

		// If response starts with { or [, return as is.
		if ( strpos( $response, '{' ) === 0 || strpos( $response, '[' ) === 0 ) {
			return $response;
		}

		return '';
	}

	/**
	 * Validate a single feedback item.
	 *
	 * @param mixed $item            Feedback item data.
	 * @param array $valid_block_ids Map of valid block IDs.
	 * @return array|null Validated item or null if invalid.
	 */
	private function validate_feedback_item( $item, array $valid_block_ids ): ?array {
		// Must be an array.
		if ( ! is_array( $item ) ) {
			return null;
		}

		// Get block_id (new format) or block_index (legacy).
		$block_id = $item['block_id'] ?? null;

		// If no block_id, skip this item.
		if ( empty( $block_id ) ) {
			return null;
		}

		// Validate block_id exists in our valid blocks.
		if ( ! isset( $valid_block_ids[ $block_id ] ) ) {
			return null;
		}

		// Required fields.
		$required = array( 'category', 'severity', 'title', 'feedback' );
		foreach ( $required as $field ) {
			if ( ! isset( $item[ $field ] ) ) {
				return null;
			}
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

		// Build validated item.
		$validated = array(
			'block_id'  => sanitize_text_field( $block_id ),
			'category'  => sanitize_text_field( $item['category'] ),
			'severity'  => sanitize_text_field( $item['severity'] ),
			'title'     => $this->sanitize_title( $item['title'] ),
			'feedback'  => $this->sanitize_feedback( $item['feedback'] ),
		);

		// Optional suggestion field.
		if ( isset( $item['suggestion'] ) && ! empty( $item['suggestion'] ) ) {
			$validated['suggestion'] = $this->sanitize_feedback( $item['suggestion'] );
		}

		return $validated;
	}

	/**
	 * Sanitize summary text.
	 *
	 * @param string $summary Summary text.
	 * @return string Sanitized summary.
	 */
	private function sanitize_summary( string $summary ): string {
		$summary = wp_kses_post( $summary );

		// Truncate to 500 characters (generous for summary).
		if ( strlen( $summary ) > 500 ) {
			$summary = substr( $summary, 0, 497 ) . '...';
		}

		return $summary;
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
