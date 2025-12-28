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
	 * Valid category values for feedback items.
	 */
	public const VALID_CATEGORIES = array( 'content', 'tone', 'flow', 'design' );

	/**
	 * Valid severity values for feedback items.
	 */
	public const VALID_SEVERITIES = array( 'suggestion', 'important', 'critical' );

	/**
	 * Required fields for each feedback item.
	 */
	public const REQUIRED_FEEDBACK_FIELDS = array( 'block_id', 'category', 'severity', 'title', 'feedback' );

	/**
	 * Optional fields for each feedback item.
	 */
	public const OPTIONAL_FEEDBACK_FIELDS = array( 'suggestion' );

	/**
	 * Maximum character lengths for feedback fields.
	 */
	public const FIELD_MAX_LENGTHS = array(
		'summary'    => 500,
		'title'      => 50,
		'feedback'   => 300,
		'suggestion' => 200,
	);

	/**
	 * Parse feedback response from AI.
	 *
	 * @param  string $response AI response (expected JSON).
	 * @param  array  $blocks   Original blocks for validation.
	 * @return array Parsed result with summary and validated feedback items.
	 */
	public function parse_feedback( string $response, array $blocks ): array {
		// Build a map of valid block IDs for validation, including block name.
		$valid_block_ids = array();
		$block_info      = array();

		Logger::debug( sprintf( 'Parsing feedback, received %d blocks', count( $blocks ) ) );

		foreach ( $blocks as $index => $block ) {
			if ( ! empty( $block['clientId'] ) ) {
				$valid_block_ids[ $block['clientId'] ] = true;
				// Store block info for enriching feedback items.
				$block_info[ $block['clientId'] ] = array(
					'name'  => $block['name'] ?? '',
					'index' => $index,
				);
				Logger::debug(
					sprintf(
						'Block %d: clientId=%s, name=%s',
						$index,
						$block['clientId'],
						$block['name'] ?? 'unknown'
					)
				);
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
			$validated = $this->validate_feedback_item( $item, $valid_block_ids, $block_info );
			if ( $validated ) {
				$parsed[] = $validated;
			}
		}

		Logger::debug( sprintf( 'Parsed %d valid feedback items', count( $parsed ) ) );

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
	 * @param  string $response Response text.
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
	 * @param  mixed $item            Feedback item data.
	 * @param  array $valid_block_ids Map of valid block IDs.
	 * @param  array $block_info      Map of block IDs to their info (name, index).
	 * @return array|null Validated item or null if invalid.
	 */
	private function validate_feedback_item( $item, array $valid_block_ids, array $block_info = array() ): ?array {
		// Must be an array.
		if ( ! is_array( $item ) ) {
			Logger::debug( 'Feedback item is not an array, skipping' );
			return null;
		}

		// Get block_id (new format) or block_index (legacy).
		$block_id = $item['block_id'] ?? null;

		// If no block_id, skip this item.
		if ( empty( $block_id ) ) {
			Logger::debug( 'Feedback item has no block_id, skipping' );
			return null;
		}

		// Validate block_id exists in our valid blocks.
		if ( ! isset( $valid_block_ids[ $block_id ] ) ) {
			Logger::debug( sprintf( 'Block ID %s not found in valid blocks, skipping', $block_id ) );
			return null;
		}

		// Required fields.
		$required = array( 'category', 'severity', 'title', 'feedback' );
		foreach ( $required as $field ) {
			if ( ! isset( $item[ $field ] ) ) {
				Logger::debug( sprintf( 'Feedback item missing required field: %s', $field ) );
				return null;
			}
		}

		// Validate category.
		$valid_categories = array( 'content', 'tone', 'flow', 'design' );
		if ( ! in_array( $item['category'], $valid_categories, true ) ) {
			Logger::debug( sprintf( 'Invalid category: %s', $item['category'] ) );
			return null;
		}

		// Validate severity.
		$valid_severities = array( 'suggestion', 'important', 'critical' );
		if ( ! in_array( $item['severity'], $valid_severities, true ) ) {
			Logger::debug( sprintf( 'Invalid severity: %s', $item['severity'] ) );
			return null;
		}

		// Build validated item.
		$validated = array(
			'block_id' => sanitize_text_field( $block_id ),
			'category' => sanitize_text_field( $item['category'] ),
			'severity' => sanitize_text_field( $item['severity'] ),
			'title'    => $this->sanitize_title( $item['title'] ),
			'feedback' => $this->sanitize_feedback( $item['feedback'] ),
		);

		// Add block name and index from block_info if available.
		if ( isset( $block_info[ $block_id ] ) ) {
			$validated['block_name']  = $block_info[ $block_id ]['name'] ?? '';
			$validated['block_index'] = $block_info[ $block_id ]['index'] ?? 0;
			Logger::debug(
				sprintf(
					'Enriched feedback item with block_name=%s, block_index=%d',
					$validated['block_name'],
					$validated['block_index']
				)
			);
		}

		// Optional suggestion field.
		if ( isset( $item['suggestion'] ) && ! empty( $item['suggestion'] ) ) {
			$validated['suggestion'] = $this->sanitize_feedback( $item['suggestion'] );
		}

		return $validated;
	}

	/**
	 * Sanitize summary text.
	 *
	 * @param  string $summary Summary text.
	 * @return string Sanitized summary.
	 */
	private function sanitize_summary( string $summary ): string {
		$summary = wp_kses_post( $summary );

		// Truncate to 500 characters (generous for summary).
		if ( mb_strlen( $summary, 'UTF-8' ) > 500 ) {
			$summary = mb_substr( $summary, 0, 497, 'UTF-8' ) . '...';
		}

		return $summary;
	}

	/**
	 * Sanitize title text.
	 *
	 * @param  string $title Title text.
	 * @return string Sanitized title.
	 */
	private function sanitize_title( string $title ): string {
		$title = sanitize_text_field( $title );

		// Truncate to 50 characters.
		if ( mb_strlen( $title, 'UTF-8' ) > 50 ) {
			$title = mb_substr( $title, 0, 47, 'UTF-8' ) . '...';
		}

		return $title;
	}

	/**
	 * Sanitize feedback text.
	 *
	 * Allows basic formatting tags.
	 *
	 * @param  string $feedback Feedback text.
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
		if ( mb_strlen( $feedback, 'UTF-8' ) > 300 ) {
			$feedback = mb_substr( $feedback, 0, 297, 'UTF-8' ) . '...';
		}

		return $feedback;
	}

	/**
	 * Get summary of feedback items.
	 *
	 * @param  array $feedback_items Parsed feedback items.
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
				++$summary['by_category'][ $item['category'] ];
			}

			// Count by severity.
			if ( isset( $summary['by_severity'][ $item['severity'] ] ) ) {
				++$summary['by_severity'][ $item['severity'] ];
			}

			// Track if any critical items exist.
			if ( 'critical' === $item['severity'] ) {
				$summary['has_critical'] = true;
			}
		}

		return $summary;
	}

	/**
	 * Validate response structure against JSON schema.
	 *
	 * This validates the structure and types of the response without
	 * checking semantic validity (e.g., whether block_ids exist).
	 *
	 * @param  mixed $data Decoded JSON data to validate.
	 * @return true|\WP_Error True if valid, WP_Error with validation errors if invalid.
	 */
	public function validate_schema( $data ) {
		$errors = new \WP_Error();

		// Must be an array/object.
		if ( ! is_array( $data ) ) {
			$errors->add(
				'invalid_type',
				__( 'Response must be a JSON object.', 'ai-feedback' ),
				array( 'path' => '$' )
			);
			return $errors;
		}

		// Check for required 'feedback' field.
		if ( ! isset( $data['feedback'] ) ) {
			$errors->add(
				'missing_field',
				__( 'Response must contain a "feedback" array.', 'ai-feedback' ),
				array( 'path' => '$.feedback' )
			);
		} elseif ( ! is_array( $data['feedback'] ) ) {
			$errors->add(
				'invalid_type',
				__( 'The "feedback" field must be an array.', 'ai-feedback' ),
				array( 'path' => '$.feedback' )
			);
		} else {
			// Validate each feedback item.
			foreach ( $data['feedback'] as $index => $item ) {
				$item_errors = $this->validate_feedback_item_schema( $item, $index );
				if ( is_wp_error( $item_errors ) ) {
					foreach ( $item_errors->get_error_codes() as $code ) {
						$error_data = $item_errors->get_error_data( $code );
						foreach ( $item_errors->get_error_messages( $code ) as $message ) {
							$errors->add( $code, $message, $error_data );
						}
					}
				}
			}
		}

		// Validate optional summary field.
		if ( isset( $data['summary'] ) && ! is_string( $data['summary'] ) ) {
			$errors->add(
				'invalid_type',
				__( 'The "summary" field must be a string.', 'ai-feedback' ),
				array( 'path' => '$.summary' )
			);
		}

		// Return true if no errors, otherwise return WP_Error.
		if ( ! $errors->has_errors() ) {
			return true;
		}

		return $errors;
	}

	/**
	 * Validate a single feedback item against schema.
	 *
	 * @param  mixed $item  Feedback item to validate.
	 * @param  int   $index Index of item in feedback array.
	 * @return true|\WP_Error True if valid, WP_Error with validation errors if invalid.
	 */
	private function validate_feedback_item_schema( $item, int $index ) {
		$errors = new \WP_Error();
		$path   = '$.feedback[' . $index . ']';

		// Must be an array/object.
		if ( ! is_array( $item ) ) {
			$errors->add(
				'invalid_type',
				sprintf(
					/* translators: %d: item index */
					__( 'Feedback item %d must be an object.', 'ai-feedback' ),
					$index
				),
				array( 'path' => $path )
			);
			return $errors;
		}

		// Check required fields.
		foreach ( self::REQUIRED_FEEDBACK_FIELDS as $field ) {
			if ( ! isset( $item[ $field ] ) ) {
				$errors->add(
					'missing_field',
					sprintf(
						/* translators: 1: field name, 2: item index */
						__( 'Feedback item %2$d is missing required field "%1$s".', 'ai-feedback' ),
						$field,
						$index
					),
					array( 'path' => $path . '.' . $field )
				);
			} elseif ( ! is_string( $item[ $field ] ) ) {
				$errors->add(
					'invalid_type',
					sprintf(
						/* translators: 1: field name, 2: item index */
						__( 'Field "%1$s" in feedback item %2$d must be a string.', 'ai-feedback' ),
						$field,
						$index
					),
					array( 'path' => $path . '.' . $field )
				);
			}
		}

		// Validate category enum.
		if ( isset( $item['category'] ) && is_string( $item['category'] ) ) {
			if ( ! in_array( $item['category'], self::VALID_CATEGORIES, true ) ) {
				$errors->add(
					'invalid_enum',
					sprintf(
						/* translators: 1: invalid value, 2: valid values, 3: item index */
						__( 'Invalid category "%1$s" in item %3$d. Must be one of: %2$s.', 'ai-feedback' ),
						$item['category'],
						implode( ', ', self::VALID_CATEGORIES ),
						$index
					),
					array( 'path' => $path . '.category' )
				);
			}
		}

		// Validate severity enum.
		if ( isset( $item['severity'] ) && is_string( $item['severity'] ) ) {
			if ( ! in_array( $item['severity'], self::VALID_SEVERITIES, true ) ) {
				$errors->add(
					'invalid_enum',
					sprintf(
						/* translators: 1: invalid value, 2: valid values, 3: item index */
						__( 'Invalid severity "%1$s" in item %3$d. Must be one of: %2$s.', 'ai-feedback' ),
						$item['severity'],
						implode( ', ', self::VALID_SEVERITIES ),
						$index
					),
					array( 'path' => $path . '.severity' )
				);
			}
		}

		// Validate optional suggestion field type.
		if ( isset( $item['suggestion'] ) && ! is_string( $item['suggestion'] ) ) {
			$errors->add(
				'invalid_type',
				sprintf(
					/* translators: %d: item index */
					__( 'Field "suggestion" in feedback item %d must be a string.', 'ai-feedback' ),
					$index
				),
				array( 'path' => $path . '.suggestion' )
			);
		}

		if ( ! $errors->has_errors() ) {
			return true;
		}

		return $errors;
	}

	/**
	 * Format validation errors for debugging output.
	 *
	 * @param  \WP_Error $errors Validation errors.
	 * @return string Formatted error report.
	 */
	public function format_validation_errors( \WP_Error $errors ): string {
		$output = array();

		foreach ( $errors->get_error_codes() as $code ) {
			$messages = $errors->get_error_messages( $code );
			foreach ( $messages as $message ) {
				$data     = $errors->get_error_data( $code );
				$path     = is_array( $data ) && isset( $data['path'] ) ? $data['path'] : '';
				$output[] = sprintf(
					'[%s] %s%s',
					$code,
					$message,
					$path ? ' (at ' . $path . ')' : ''
				);
			}
		}

		return implode( "\n", $output );
	}
}
