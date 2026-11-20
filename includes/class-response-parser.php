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
	public const VALID_CATEGORIES = array( 'content', 'tone', 'flow', 'design', 'grammar' );

	/**
	 * Category of proofreading items: each one is a single quick fix.
	 */
	public const GRAMMAR_CATEGORY = 'grammar';

	/**
	 * Maximum number of quick fixes kept for one block in a review.
	 */
	public const MAX_FIXES_PER_BLOCK = 20;

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
	public const OPTIONAL_FEEDBACK_FIELDS = array( 'suggestion', 'quick_fix', 'block_action', 'block_change' );

	/**
	 * Maximum length of a quick fix's find and replace text.
	 *
	 * @var int
	 */
	public const MAX_QUICK_FIX_LENGTH = 200;

	/**
	 * Edit types an AI reply can carry.
	 */
	public const REPLY_EDIT_TYPES = array( 'replace_text', 'rewrite_block' );

	/**
	 * Maximum length of a `rewrite_block` edit's content.
	 */
	public const MAX_REWRITE_LENGTH = 4000;

	/**
	 * Inline formatting tags a `rewrite_block` edit may contain.
	 */
	public const REWRITE_ALLOWED_TAGS = array(
		'a'      => array( 'href' => true ),
		'b'      => array(),
		'br'     => array(),
		'code'   => array(),
		'em'     => array(),
		'i'      => array(),
		's'      => array(),
		'strong' => array(),
		'sub'    => array(),
		'sup'    => array(),
	);

	/**
	 * Structural edits: removing the note's block, moving it next to another
	 * block, or inserting a new block next to it.
	 */
	public const BLOCK_ACTION_TYPES = array( 'remove_block', 'move_block', 'insert_block' );

	/**
	 * Short names a review's `block_action` may use for the structural types.
	 */
	private const BLOCK_ACTION_ALIASES = array(
		'remove' => 'remove_block',
		'move'   => 'move_block',
		'insert' => 'insert_block',
	);

	/**
	 * Reference the reply prompt uses for the note's own block among the
	 * blocks a structural edit may point at.
	 */
	public const REPLY_SELF_REF = 'this';

	/**
	 * Block types an `insert_block` edit may add, unless filtered.
	 */
	public const DEFAULT_INSERTABLE_BLOCKS = array( 'core/paragraph', 'core/heading', 'core/list' );

	/**
	 * Maximum length of an inserted block's text, and of each list item.
	 */
	public const MAX_INSERT_LENGTH = 1000;

	/**
	 * Maximum number of items in an inserted list.
	 */
	public const MAX_INSERT_LIST_ITEMS = 20;

	/**
	 * Length of the target block text kept with a move, to find and name it.
	 */
	public const MAX_TARGET_TEXT_LENGTH = 80;

	/**
	 * Most strengths a review returns.
	 */
	public const MAX_STRENGTHS = 3;

	/**
	 * Maximum length of a strength, in characters.
	 */
	public const MAX_STRENGTH_LENGTH = 160;

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
	 * Severity weights used by calculate_priority().
	 */
	public const PRIORITY_SEVERITY_WEIGHTS = array(
		'critical'   => 100,
		'important'  => 50,
		'suggestion' => 10,
	);

	/**
	 * Category weights used by calculate_priority().
	 */
	public const PRIORITY_CATEGORY_WEIGHTS = array(
		'content' => 30,
		'flow'    => 20,
		'tone'    => 15,
		'design'  => 10,
		'grammar' => 5,
	);

	/**
	 * Block-type weights used by calculate_priority().
	 *
	 * Blocks not listed here fall back to PRIORITY_BLOCK_DEFAULT.
	 */
	public const PRIORITY_BLOCK_WEIGHTS = array(
		'core/heading'   => 15,
		'core/list'      => 10,
		'core/quote'     => 10,
		'core/paragraph' => 5,
	);

	/**
	 * Default block-type weight when a block is not in PRIORITY_BLOCK_WEIGHTS.
	 */
	public const PRIORITY_BLOCK_DEFAULT = 5;

	/**
	 * Position bonus for the first block, reduced by PRIORITY_POSITION_STEP per block.
	 */
	public const PRIORITY_POSITION_MAX  = 25;
	public const PRIORITY_POSITION_STEP = 5;

	/**
	 * Score thresholds clients use to bucket priorities into high / medium / low.
	 */
	public const PRIORITY_HIGH_THRESHOLD   = 100;
	public const PRIORITY_MEDIUM_THRESHOLD = 50;

	/**
	 * Parse an AI response and extract validated feedback items with an optional summary.
	 *
	 * Given the raw AI response text, attempts to extract and decode JSON, validates and
	 * sanitizes feedback items, and enriches them with block metadata drawn from $blocks.
	 *
	 * @param  string       $response        AI response text (may contain surrounding text; JSON is extracted).
	 * @param  array<mixed> $blocks      Original blocks used to validate `block_id` values and provide
	 *                                   enrichment (`name` and `index`) for validated items.
	 * @param  bool         $validate_schema If true, perform strict JSON schema validation and return
	 *                                       a WP_Error on schema or parse failures; if false, parsing
	 *                                       errors yield an empty result structure.
	 * @param  string[]     $image_blocks Client IDs of the image blocks whose image the AI was shown,
	 *                                    the only blocks it may propose alt text or a caption for.
	 * @return array<string, mixed>|\WP_Error On success, an array with keys:
	 *                         - `summary` (string): sanitized summary text.
	 *                         - `feedback` (array): list of validated, sanitized, and enriched feedback items.
	 *                         - `persona_takeaway` (string): the reader persona's one line takeaway, or ''.
	 *                         On schema validation failure (when $validate_schema is true), returns a WP_Error.
	 */
	public function parse_feedback( string $response, array $blocks, bool $validate_schema = false, array $image_blocks = array() ) {
		// Build a map of valid block IDs for validation, including block name.
		$valid_block_ids = array();
		$block_info      = array();

		Logger::debug( sprintf( 'Parsing feedback, received %d blocks', count( $blocks ) ) );

		foreach ( $blocks as $index => $block ) {
			if ( ! empty( $block['clientId'] ) ) {
				$valid_block_ids[ $block['clientId'] ] = true;
				// Store block info for enriching feedback items.
				$block_info[ $block['clientId'] ] = array(
					'name'    => $block['name'] ?? '',
					'index'   => $index,
					'content' => is_array( $block ) && is_string( $block['content'] ?? null ) ? $block['content'] : '',
					'parent'  => is_array( $block ) && is_string( $block['parent'] ?? null ) ? $block['parent'] : '',
					'anchor'  => $this->get_block_anchor( $block ),
					'attrs'   => Block_Change::get_block_attributes( $block ),
					'image'   => in_array( $block['clientId'], $image_blocks, true ),
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
			if ( $validate_schema ) {
				return new \WP_Error(
					'invalid_json',
					__( 'Could not extract valid JSON from AI response.', 'fresh-eyes' )
				);
			}
			return array(
				'summary'  => '',
				'feedback' => array(),
			);
		}

		// Decode JSON.
		$data = json_decode( $json, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			if ( $validate_schema ) {
				return new \WP_Error(
					'json_parse_error',
					sprintf(
					/* translators: %s: JSON error message */
						__( 'JSON parse error: %s', 'fresh-eyes' ),
						json_last_error_msg()
					)
				);
			}
			return array(
				'summary'  => '',
				'feedback' => array(),
			);
		}

		// Perform schema validation if requested.
		if ( $validate_schema ) {
			$schema_result = $this->validate_schema( $data );
			if ( is_wp_error( $schema_result ) ) {
				return $schema_result;
			}
		}

		// Handle new format with summary and feedback.
		if ( is_array( $data ) && isset( $data['feedback'] ) ) {
			$summary        = $data['summary'] ?? '';
			$feedback_items = $data['feedback'];
		} elseif ( is_array( $data ) && isset( $data[0] ) ) {
			// Legacy format - array of feedback items.
			$summary        = '';
			$feedback_items = $data;
		} else {
			if ( $validate_schema ) {
				return new \WP_Error(
					'invalid_format',
					__( 'Response does not contain expected feedback format.', 'fresh-eyes' )
				);
			}
			return array(
				'summary'  => '',
				'feedback' => array(),
			);
		}

		// Parse and validate each feedback item.
		$parsed   = array();
		$actioned = array();
		foreach ( $feedback_items as $item ) {
			$validated = $this->validate_feedback_item( $item, $valid_block_ids, $block_info, $actioned );
			if ( $validated ) {
				$validated['priority'] = $this->calculate_priority( $validated );
				$parsed[]              = $validated;
			}
		}

		Logger::debug( sprintf( 'Parsed %d valid feedback items', count( $parsed ) ) );

		// Group similar feedback items to reduce noise.
		$grouped = $this->group_similar_feedback( $parsed );

		// A block whose structural edit or formatting fix survived grouping
		// gets no quick fix from any item, so the review proposes one change
		// per block.
		// A heading level or list type change leaves the text alone, so
		// proofreading fixes stay beside it. Any other change rewrites, moves
		// or removes the block, and its proofreading items are dropped below.
		$changed = array();
		foreach ( $grouped as $item ) {
			if ( is_string( $item['block_id'] ?? null ) && ( isset( $item['block_action'] ) || isset( $item['block_change'] ) ) ) {
				$change                       = $item['block_change'] ?? null;
				$keeps_text                   = ! isset( $item['block_action'] ) && is_array( $change ) && 'set_attributes' === ( $change['type'] ?? null );
				$changed[ $item['block_id'] ] = $keeps_text && ( $changed[ $item['block_id'] ] ?? true );
			}
		}
		foreach ( $grouped as $index => $item ) {
			$block_id = is_string( $item['block_id'] ?? null ) ? $item['block_id'] : '';
			if ( ! isset( $changed[ $block_id ] ) || isset( $item['block_action'] ) || isset( $item['block_change'] ) ) {
				continue;
			}
			if ( ! $changed[ $block_id ] || self::GRAMMAR_CATEGORY !== ( $item['category'] ?? null ) ) {
				unset( $grouped[ $index ]['quick_fix'] );
			}
		}

		$grouped = $this->resolve_fix_conflicts( $grouped, $block_info );

		// A proofreading item is only its fix, so one that lost it is noise.
		$grouped = array_values(
			array_filter(
				$grouped,
				static fn ( array $item ): bool => self::GRAMMAR_CATEGORY !== ( $item['category'] ?? null ) || isset( $item['quick_fix'] )
			)
		);

		Logger::debug( sprintf( 'Grouped into %d feedback items', count( $grouped ) ) );

		// Sort by priority so the most impactful feedback surfaces first.
		$grouped = $this->sort_by_priority( $grouped );

		return array(
			'summary'          => $this->sanitize_summary( is_string( $summary ) ? $summary : '' ),
			'feedback'         => $grouped,
			'persona_takeaway' => Personas::sanitize_takeaway( $data['persona_takeaway'] ?? '' ),
			'strengths'        => $this->parse_strengths( $data['strengths'] ?? null, $block_info ),
		);
	}

	/**
	 * Most strengths a review may return, from 0 (none) to MAX_STRENGTHS.
	 *
	 * @return int
	 */
	public static function get_max_strengths(): int {
		/**
		 * Filters how many strengths a review asks for and keeps.
		 *
		 * Return 0 to leave strengths out of reviews.
		 *
		 * @param int $max Most strengths, at most Response_Parser::MAX_STRENGTHS.
		 */
		$max = (int) apply_filters( 'ai_feedback_max_strengths', self::MAX_STRENGTHS );

		return max( 0, min( self::MAX_STRENGTHS, $max ) );
	}

	/**
	 * Validate the strengths the AI returned.
	 *
	 * Each strength must name a block in this review and carry one short line
	 * of plain text. Invalid ones are dropped rather than failing the review,
	 * one strength is kept per block, and the list is capped.
	 *
	 * @param  mixed        $strengths  Raw `strengths` value from the AI response.
	 * @param  array<mixed> $block_info Map of block IDs to their info, from parse_feedback().
	 * @return list<array{block_id: string, block_name: string, block_index: int|null, block_anchor: string, text: string}>
	 */
	public function parse_strengths( mixed $strengths, array $block_info ): array {
		$max = self::get_max_strengths();
		if ( ! is_array( $strengths ) || 0 === $max ) {
			return array();
		}

		$parsed = array();
		foreach ( $strengths as $strength ) {
			if ( ! is_array( $strength ) ) {
				continue;
			}
			$block_id = is_string( $strength['block_id'] ?? null ) ? $strength['block_id'] : '';
			$info     = $block_info[ $block_id ] ?? null;
			if ( ! is_array( $info ) || isset( $parsed[ $block_id ] ) ) {
				continue;
			}

			$text = self::sanitize_strength_text( $strength['text'] ?? null );
			if ( '' === $text ) {
				continue;
			}

			$parsed[ $block_id ] = array(
				'block_id'     => $block_id,
				'block_name'   => is_string( $info['name'] ?? null ) ? $info['name'] : '',
				'block_index'  => is_int( $info['index'] ?? null ) ? $info['index'] : null,
				'block_anchor' => is_string( $info['anchor'] ?? null ) ? $info['anchor'] : '',
				'text'         => $text,
			);

			if ( count( $parsed ) >= $max ) {
				break;
			}
		}

		return array_values( $parsed );
	}

	/**
	 * Sanitize a strength as one line of plain text and cap its length.
	 *
	 * @param  mixed $text Raw text.
	 * @return string Text, or an empty string when there is none.
	 */
	public static function sanitize_strength_text( mixed $text ): string {
		if ( ! is_string( $text ) ) {
			return '';
		}

		$text = trim( sanitize_text_field( $text ) );
		if ( mb_strlen( $text, 'UTF-8' ) > self::MAX_STRENGTH_LENGTH ) {
			$text = rtrim( mb_substr( $text, 0, self::MAX_STRENGTH_LENGTH - 1, 'UTF-8' ) ) . '…';
		}

		return $text;
	}

	/**
	 * Calculate a priority score for a single feedback item.
	 *
	 * Combines severity (largest factor), category, block type, and a small
	 * position bonus for early blocks. The score is bucketed by clients into
	 * high / medium / low using PRIORITY_*_THRESHOLD constants.
	 *
	 * Blocks without a known `block_name` still add PRIORITY_BLOCK_DEFAULT, so
	 * the score always includes a block-type weight.
	 *
	 * @param  array<string, mixed> $item Validated feedback item. Expected keys: `severity`,
	 *                                    `category`, optional `block_name`, optional `block_index`.
	 * @return int Priority score (higher means more important).
	 */
	public function calculate_priority( array $item ): int {
		$severity   = is_string( $item['severity'] ?? null ) ? $item['severity'] : '';
		$category   = is_string( $item['category'] ?? null ) ? $item['category'] : '';
		$block_name = is_string( $item['block_name'] ?? null ) ? $item['block_name'] : '';

		$score  = self::PRIORITY_SEVERITY_WEIGHTS[ $severity ] ?? 0;
		$score += self::PRIORITY_CATEGORY_WEIGHTS[ $category ] ?? 0;
		$score += self::PRIORITY_BLOCK_WEIGHTS[ $block_name ] ?? self::PRIORITY_BLOCK_DEFAULT;

		// Early blocks get a decreasing bonus (25, 20, 15, 10, 5, then 0).
		if ( isset( $item['block_index'] ) && is_int( $item['block_index'] ) ) {
			$score += max( 0, self::PRIORITY_POSITION_MAX - ( $item['block_index'] * self::PRIORITY_POSITION_STEP ) );
		}

		return $score;
	}

	/**
	 * Sort feedback items by priority score, highest first.
	 *
	 * Items without a `priority` key are treated as zero. Ties are broken by
	 * `block_index` so equal-priority items follow document order; `usort` is
	 * stable on PHP 8+, so remaining ties keep their original order.
	 *
	 * @param  array<int, array<string, mixed>> $feedback Feedback items, each expected to have an integer `priority`.
	 * @return array<int, array<string, mixed>> Feedback items sorted by descending priority.
	 */
	public function sort_by_priority( array $feedback ): array {
		usort(
			$feedback,
			static function ( array $a, array $b ): int {
				$pa = is_int( $a['priority'] ?? null ) ? $a['priority'] : 0;
				$pb = is_int( $b['priority'] ?? null ) ? $b['priority'] : 0;
				if ( $pa !== $pb ) {
					return $pb <=> $pa;
				}
				return self::get_document_position( $a ) <=> self::get_document_position( $b );
			}
		);

		return $feedback;
	}

	/**
	 * Document position of a feedback item, used to break priority ties.
	 *
	 * Groups use their earliest member block, matching getDocumentPosition()
	 * in src/utils/priority.js.
	 *
	 * @param  array<string, mixed> $item Feedback item.
	 * @return int Block index, or PHP_INT_MAX when unknown.
	 */
	private static function get_document_position( array $item ): int {
		$indexes = is_array( $item['block_indexes'] ?? null ) ? array_filter( $item['block_indexes'], 'is_int' ) : array();
		if ( ! empty( $indexes ) ) {
			return min( $indexes );
		}
		return is_int( $item['block_index'] ?? null ) ? $item['block_index'] : PHP_INT_MAX;
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
	 * Read the anchor the editor sent for a block.
	 *
	 * @param  mixed $block Block from the review request.
	 * @return string Anchor, or an empty string when missing or malformed.
	 */
	private function get_block_anchor( mixed $block ): string {
		$anchor = is_array( $block ) ? ( $block['anchor'] ?? null ) : null;

		return is_string( $anchor ) && 1 === preg_match( '/^[a-z0-9]{1,32}$/', $anchor ) ? $anchor : '';
	}

	/**
	 * Validate and sanitize a single feedback item, enriching it with block metadata when available.
	 *
	 * Ensures the item contains a valid `block_id` present in `$valid_block_ids`, includes the required
	 * fields (`category`, `severity`, `title`, `feedback`), and that `category` and `severity` are
	 * allowed values. When valid, returns a sanitized array containing `block_id`, `category`, `severity`,
	 * `title`, and `feedback`; includes `block_name`, `block_index` and `block_anchor` from `$block_info` when available,
	 * and includes a sanitized `suggestion` if provided.
	 *
	 * @param  mixed              $item            Feedback item data.
	 * @param  array<mixed>       $valid_block_ids Map of valid block IDs.
	 * @param  array<mixed>       $block_info      Map of block IDs to their info (name, index).
	 * @param  array<string, int> $actioned        Blocks that already have a structural edit in this review, updated here.
	 * @return array<string, mixed>|null Array of validated and sanitized fields on success, `null` if the item is invalid.
	 */
	private function validate_feedback_item( $item, array $valid_block_ids, array $block_info = array(), array &$actioned = array() ): ?array {
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
		if ( ! in_array( $item['category'], self::VALID_CATEGORIES, true ) ) {
			Logger::debug( sprintf( 'Invalid category: %s', $item['category'] ) );
			return null;
		}

		// Validate severity.
		if ( ! in_array( $item['severity'], self::VALID_SEVERITIES, true ) ) {
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

			$info   = is_string( $block_id ) ? $block_info[ $block_id ] : null;
			$anchor = is_array( $info ) ? ( $info['anchor'] ?? '' ) : '';
			if ( is_string( $anchor ) && '' !== $anchor ) {
				$validated['block_anchor'] = $anchor;
			}
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

		// Optional quick fix, kept only when it applies cleanly to the block.
		if ( isset( $item['quick_fix'] ) ) {
			$info          = is_string( $block_id ) ? ( $block_info[ $block_id ] ?? null ) : null;
			$block_content = is_array( $info ) && is_string( $info['content'] ?? null ) ? $info['content'] : '';
			$quick_fix     = $this->validate_quick_fix( $item['quick_fix'], $block_content );
			if ( null !== $quick_fix ) {
				$validated['quick_fix'] = $quick_fix;
			}
		}

		// A proofreading item is a text fix and nothing else, and always a
		// suggestion: a typo is never a critical problem.
		if ( self::GRAMMAR_CATEGORY === $validated['category'] ) {
			// A missing replacement is a malformed fix here, not a deletion.
			$fix = $item['quick_fix'] ?? null;
			if ( ! isset( $validated['quick_fix'] ) || ! is_array( $fix ) || ! is_string( $fix['replace'] ?? null ) ) {
				Logger::debug( 'Grammar item without a usable quick fix, skipping' );
				return null;
			}
			$validated['severity'] = 'suggestion';
			return $validated;
		}

		// Optional structural edit, at most one per block in a review. It
		// replaces any quick fix, so the note proposes a single change.
		if ( isset( $item['block_action'] ) && is_string( $block_id ) && ! isset( $actioned[ $block_id ] ) ) {
			$action = $this->validate_block_action( $item['block_action'], $block_id, self::get_review_targets( $block_info ) );
			if ( null !== $action ) {
				$validated['block_action'] = $action;
				$actioned[ $block_id ]     = 1;
				unset( $validated['quick_fix'] );
			}
		}

		// Optional formatting fix. It shares the one change per block with
		// structural edits, which win on the same item, and also replaces any
		// quick fix.
		if ( isset( $item['block_change'] ) && is_string( $block_id ) && ! isset( $actioned[ $block_id ] ) && is_array( $block_info[ $block_id ] ?? null ) ) {
			$info   = $block_info[ $block_id ];
			$change = Block_Change::validate(
				$item['block_change'],
				array(
					'name'       => is_string( $info['name'] ?? null ) ? $info['name'] : '',
					'content'    => is_string( $info['content'] ?? null ) ? $info['content'] : '',
					'attributes' => is_array( $info['attrs'] ?? null ) ? $info['attrs'] : array(),
				),
				true === ( $info['image'] ?? false )
			);
			if ( null !== $change ) {
				$validated['block_change'] = $change;
				$actioned[ $block_id ]     = 1;
				unset( $validated['quick_fix'] );
			}
		}

		return $validated;
	}

	/**
	 * Blocks a review's structural edits may point at: the reviewed blocks,
	 * which in a chunked review are only the blocks of that chunk.
	 *
	 * @param  array<mixed> $block_info Map of block IDs to their info.
	 * @return array<string, array{client_id: string, name: string, text: string, index: int, parent: string}> Targets keyed by block ID.
	 */
	private static function get_review_targets( array $block_info ): array {
		$targets = array();
		foreach ( $block_info as $id => $info ) {
			if ( ! is_array( $info ) ) {
				continue;
			}
			$targets[ (string) $id ] = array(
				'client_id' => (string) $id,
				'name'      => is_string( $info['name'] ?? null ) ? $info['name'] : '',
				'text'      => is_string( $info['content'] ?? null ) ? $info['content'] : '',
				'index'     => is_int( $info['index'] ?? null ) ? $info['index'] : 0,
				'parent'    => is_string( $info['parent'] ?? null ) ? $info['parent'] : '',
			);
		}
		return $targets;
	}

	/**
	 * The allow-list of block types an `insert_block` edit may add.
	 *
	 * @return list<string> Block names.
	 */
	public static function get_insertable_blocks(): array {
		/**
		 * Filters the block types an AI edit may insert.
		 *
		 * The editor builds paragraphs, headings and lists, and any other
		 * block type with a rich text `content` attribute from its text.
		 *
		 * @param list<string> $blocks Block names.
		 */
		$blocks = apply_filters( 'ai_feedback_insertable_blocks', self::DEFAULT_INSERTABLE_BLOCKS );

		return array_values( array_filter( (array) $blocks, 'is_string' ) );
	}

	/**
	 * Validate a structural edit: remove the note's block, move it next to
	 * another block, or insert a new block next to it.
	 *
	 * `$targets` lists the blocks the AI was shown, keyed by the ID it was
	 * told to use, and must include the note's own block. A move keeps the
	 * target's client ID (when known), name and the start of its text, so
	 * the editor can find it again and name it. Inserted text may only hold
	 * inline formatting and is run through wp_kses().
	 *
	 * @param  mixed        $action   Structural edit from the AI response.
	 * @param  string       $block_id ID of the note's block in `$targets`.
	 * @param  array<mixed> $targets  Blocks the edit may point at, each with `client_id`, `name`, `text` and `index`.
	 * @return array<string, mixed>|null Sanitized edit with a `type` from BLOCK_ACTION_TYPES, or null if unusable.
	 */
	public function validate_block_action( mixed $action, string $block_id, array $targets ): ?array {
		if ( ! is_array( $action ) || ! is_array( $targets[ $block_id ] ?? null ) ) {
			return null;
		}

		$type = $action['type'] ?? null;
		$type = is_string( $type ) ? ( self::BLOCK_ACTION_ALIASES[ $type ] ?? $type ) : null;

		if ( 'remove_block' === $type ) {
			return array( 'type' => 'remove_block' );
		}

		$position = $action['position'] ?? null;
		if ( ! in_array( $position, array( 'before', 'after' ), true ) ) {
			return null;
		}

		if ( 'move_block' === $type ) {
			$target_id = $action['target_block_id'] ?? null;
			if ( ! is_string( $target_id ) || $target_id === $block_id || ! is_array( $targets[ $target_id ] ?? null ) ) {
				return null;
			}
			$target = $targets[ $target_id ];
			$self   = $targets[ $block_id ];

			// A block can't move next to one of its own inner blocks.
			if ( self::is_inside( $target_id, $block_id, $targets ) ) {
				return null;
			}

			// Moving a block before the sibling after it, or after the
			// sibling before it, leaves it where it is. Indexes count the
			// reviewed blocks in document order, nested ones included, so
			// only blocks with the same parent are compared; the editor
			// checks the actual siblings before applying anything.
			$offset      = self::int_value( $target['index'] ?? null ) - self::int_value( $self['index'] ?? null );
			$same_parent = ( $target['parent'] ?? '' ) === ( $self['parent'] ?? '' );
			if ( $same_parent && ( ( 'before' === $position && 1 === $offset ) || ( 'after' === $position && -1 === $offset ) ) ) {
				return null;
			}

			return array(
				'type'            => 'move_block',
				'position'        => $position,
				'target_block_id' => is_string( $target['client_id'] ?? null ) ? sanitize_text_field( $target['client_id'] ) : '',
				'target_name'     => is_string( $target['name'] ?? null ) ? sanitize_text_field( $target['name'] ) : '',
				'target_text'     => self::excerpt( is_string( $target['text'] ?? null ) ? $target['text'] : '' ),
			);
		}

		if ( 'insert_block' !== $type ) {
			return null;
		}

		$block = self::validate_inserted_block( $action['block'] ?? null );

		return null === $block ? null : array(
			'type'     => 'insert_block',
			'position' => $position,
			'block'    => $block,
		);
	}

	/**
	 * Whether a block sits inside another, following `parent` through the
	 * targets. Each block is visited at most once, so a parent loop in
	 * crafted input ends.
	 *
	 * @param  string       $block_id    Block to start from.
	 * @param  string       $ancestor_id Possible ancestor.
	 * @param  array<mixed> $targets     Targets keyed by block ID, each with an optional `parent`.
	 * @return bool
	 */
	private static function is_inside( string $block_id, string $ancestor_id, array $targets ): bool {
		$seen    = array();
		$current = $block_id;
		while ( ! isset( $seen[ $current ] ) ) {
			$seen[ $current ] = true;
			$entry            = $targets[ $current ] ?? null;
			$parent           = is_array( $entry ) && is_string( $entry['parent'] ?? null ) ? $entry['parent'] : '';
			if ( '' === $parent ) {
				return false;
			}
			if ( $parent === $ancestor_id ) {
				return true;
			}
			$current = $parent;
		}
		return false;
	}

	/**
	 * Validate the block an `insert_block` edit adds.
	 *
	 * @param  mixed $block Block from the AI response: `name`, and `content` or, for a list, `items`.
	 * @return array<string, mixed>|null Sanitized block, or null if unusable.
	 */
	private static function validate_inserted_block( mixed $block ): ?array {
		if ( ! is_array( $block ) ) {
			return null;
		}

		$name = $block['name'] ?? null;
		if ( ! is_string( $name ) || ! in_array( $name, self::get_insertable_blocks(), true ) ) {
			return null;
		}

		if ( 'core/list' === $name ) {
			$items = $block['items'] ?? null;
			if ( ! is_array( $items ) || array() === $items || count( $items ) > self::MAX_INSERT_LIST_ITEMS ) {
				return null;
			}
			$clean = array();
			foreach ( $items as $item ) {
				$text = self::sanitize_inserted_text( $item );
				if ( null === $text ) {
					return null;
				}
				$clean[] = $text;
			}
			return array(
				'name'    => $name,
				'items'   => $clean,
				'ordered' => true === ( $block['ordered'] ?? false ),
			);
		}

		$content = self::sanitize_inserted_text( $block['content'] ?? null );
		if ( null === $content ) {
			return null;
		}

		$validated = array(
			'name'    => $name,
			'content' => $content,
		);
		if ( 'core/heading' === $name ) {
			$level              = $block['level'] ?? 2;
			$validated['level'] = is_int( $level ) && $level >= 2 && $level <= 6 ? $level : 2;
		}

		return $validated;
	}

	/**
	 * Sanitize the text of an inserted block.
	 *
	 * Like a rewrite, it may only hold inline formatting; other markup drops
	 * the edit rather than being stripped.
	 *
	 * @param  mixed $text Text from the AI response.
	 * @return string|null Sanitized text, or null if unusable.
	 */
	private static function sanitize_inserted_text( mixed $text ): ?string {
		if ( ! is_string( $text ) || mb_strlen( $text ) > self::MAX_INSERT_LENGTH || ! self::has_only_inline_tags( $text ) ) {
			return null;
		}

		$text = trim( wp_kses( $text, self::REWRITE_ALLOWED_TAGS ) );
		return '' === trim( wp_strip_all_tags( $text ) ) ? null : $text;
	}

	/**
	 * Shorten block text to the part kept with a move.
	 *
	 * @param  string $text Block text.
	 * @return string Whitespace-collapsed text, at most MAX_TARGET_TEXT_LENGTH characters.
	 */
	private static function excerpt( string $text ): string {
		// Block text is plain text, so text that looks like a tag is kept:
		// the editor compares it literally.
		return mb_substr( self::normalize_text( $text ), 0, self::MAX_TARGET_TEXT_LENGTH );
	}

	/**
	 * Whether a block's text is the one a move's `target_text` was kept from.
	 *
	 * The kept text is the whole text when it is shorter than the excerpt
	 * length, so it must match exactly; otherwise the block's text must
	 * start with it. Matches matchesTargetText() in src/utils/block-action.js.
	 *
	 * @param  string $block_text  Whitespace-collapsed block text.
	 * @param  string $target_text Kept target text.
	 * @return bool
	 */
	public static function matches_target_text( string $block_text, string $target_text ): bool {
		$block_text = self::normalize_text( $block_text );
		return mb_strlen( $target_text ) < self::MAX_TARGET_TEXT_LENGTH
			? $block_text === $target_text
			: str_starts_with( $block_text, $target_text );
	}

	/**
	 * Read an integer, treating anything else as zero.
	 *
	 * @param  mixed $value Value.
	 * @return int
	 */
	private static function int_value( mixed $value ): int {
		return is_int( $value ) ? $value : 0;
	}

	/**
	 * Whether an edit is a structural one.
	 *
	 * @param  array<mixed> $edit Edit.
	 * @return bool
	 */
	public static function is_block_action( array $edit ): bool {
		return in_array( $edit['type'] ?? null, self::BLOCK_ACTION_TYPES, true );
	}

	/**
	 * Validate a quick fix against the block it targets.
	 *
	 * A quick fix is a plain text find/replace. It is dropped if it contains
	 * markup, or unless `find` appears exactly once in the block's text, so
	 * applying it is unambiguous.
	 *
	 * @param  mixed  $quick_fix     Quick fix from the AI response.
	 * @param  string $block_content Plain text content of the target block.
	 * @return array{find: string, replace: string}|null Sanitized quick fix, or null if unusable.
	 */
	public function validate_quick_fix( $quick_fix, string $block_content ): ?array {
		if ( ! is_array( $quick_fix ) ) {
			return null;
		}

		$find    = $quick_fix['find'] ?? null;
		$replace = $quick_fix['replace'] ?? '';

		if ( ! is_string( $find ) || ! is_string( $replace ) ) {
			return null;
		}

		// Plain text only; markup can't be matched against the block's text.
		// Reject rather than strip, since stripping also trims whitespace that
		// may be part of the intended edit.
		if ( str_contains( $find, '<' ) || str_contains( $replace, '<' ) ) {
			return null;
		}

		if ( '' === trim( $find ) || $find === $replace ) {
			return null;
		}

		if ( mb_strlen( $find ) > self::MAX_QUICK_FIX_LENGTH || mb_strlen( $replace ) > self::MAX_QUICK_FIX_LENGTH ) {
			return null;
		}

		if ( 1 !== $this->count_quick_fix_matches( $find, $block_content ) ) {
			Logger::debug( 'Quick fix text not found exactly once in block, dropping it' );
			return null;
		}

		return array(
			'find'    => $find,
			'replace' => $replace,
		);
	}

	/**
	 * Count matches of a quick fix's text in block content.
	 *
	 * Text that starts or ends with a letter or digit only matches at a word
	 * boundary there, so "cat" doesn't match inside "concatenate", and a
	 * space matches a non-breaking one. The editor uses the same rules
	 * (src/utils/quick-fix.js).
	 *
	 * @param  string $find    Text to find.
	 * @param  string $content Block content.
	 * @return int Number of matches.
	 */
	private function count_quick_fix_matches( string $find, string $content ): int {
		$count = preg_match_all( self::quick_fix_pattern( $find ), $content );

		return false === $count ? 0 : $count;
	}

	/**
	 * Build the regular expression that finds a quick fix's text.
	 *
	 * @param  string $find Text to find.
	 * @return string Pattern.
	 */
	private static function quick_fix_pattern( string $find ): string {
		$pattern = (string) preg_replace( '/[ \x{00A0}]/u', '[ \x{00A0}]', preg_quote( $find, '/' ) );

		if ( preg_match( '/^[\p{L}\p{N}]/u', $find ) ) {
			$pattern = '(?<![\p{L}\p{N}])' . $pattern;
		}

		if ( preg_match( '/[\p{L}\p{N}]$/u', $find ) ) {
			$pattern .= '(?![\p{L}\p{N}])';
		}

		return '/' . $pattern . '/u';
	}

	/**
	 * Find where a quick fix's text sits in block content.
	 *
	 * @param  string $find    Text to find.
	 * @param  string $content Block content.
	 * @return int|null Byte offset of the only match, or null unless there is exactly one.
	 */
	private static function find_unique_offset( string $find, string $content ): ?int {
		return self::find_unique_match( $find, $content )[0] ?? null;
	}

	/**
	 * Find where a quick fix's text sits in block content, and its length
	 * there, which differs from the find's when a space matches a
	 * non-breaking one.
	 *
	 * @param  string $find    Text to find.
	 * @param  string $content Block content.
	 * @return array{0: int, 1: int}|null Byte offset and length of the only match, or null unless there is exactly one.
	 */
	private static function find_unique_match( string $find, string $content ): ?array {
		$count = preg_match_all( self::quick_fix_pattern( $find ), $content, $matches, PREG_OFFSET_CAPTURE );

		return 1 === $count ? array( $matches[0][0][1], strlen( $matches[0][0][0] ) ) : null;
	}

	/**
	 * Keep only the proofreading fixes on each block that can be applied
	 * together, and beside the block's other quick fixes, without one
	 * changing where another applies.
	 *
	 * Other quick fixes are never changed here: each one already appears
	 * exactly once, and the editor checks it again before applying it, as
	 * before proofreading existed. Grammar fixes are settled after them, in
	 * review order, and give way to them. A grammar fix loses its quick fix
	 * when its text overlaps another kept fix, when applying any one kept fix
	 * moves its text or makes it missing or ambiguous (or the other way
	 * round), when applying all the kept grammar fixes does, and once a block
	 * has MAX_FIXES_PER_BLOCK grammar fixes. Other subsets are not checked, as
	 * their number grows exponentially: applying some fixes one at a time can
	 * still leave another ambiguous, and the editor then shows it as outdated
	 * rather than applying it. Accept all locates every fix before replacing
	 * any (src/utils/proofreading.js).
	 *
	 * @param  array<int, array<string, mixed>> $items      Feedback items.
	 * @param  array<mixed>                     $block_info Map of block IDs to their info, with `content`.
	 * @return array<int, array<string, mixed>> Items, some grammar items without their quick fix.
	 */
	private function resolve_fix_conflicts( array $items, array $block_info ): array {
		$others  = array();
		$grammar = array();
		foreach ( $items as $index => $item ) {
			$fix      = $item['quick_fix'] ?? null;
			$block_id = is_string( $item['block_id'] ?? null ) ? $item['block_id'] : '';
			if ( ! is_array( $fix ) || ! is_string( $fix['find'] ?? null ) || ! is_string( $fix['replace'] ?? null ) || '' === $block_id ) {
				continue;
			}

			$info    = $block_info[ $block_id ] ?? null;
			$content = is_array( $info ) && is_string( $info['content'] ?? null ) ? $info['content'] : '';
			// Byte offsets, compared only with each other; the editor works
			// in UTF-16 units on its own copy of the text.
			$match = self::find_unique_match( $fix['find'], $content );

			$located = null === $match ? null : array(
				'find'    => $fix['find'],
				'replace' => $fix['replace'],
				'start'   => $match[0],
				'end'     => $match[0] + $match[1],
			);

			if ( self::GRAMMAR_CATEGORY === ( $item['category'] ?? null ) ) {
				$grammar[] = array( $index, $block_id, $content, $located );
			} elseif ( null !== $located ) {
				$others[ $block_id ][] = $located;
			}
		}

		$kept = array();
		foreach ( $grammar as list( $index, $block_id, $content, $candidate ) ) {
			$block_kept = $kept[ $block_id ] ?? array();
			if (
				null === $candidate
				|| count( $block_kept ) >= self::MAX_FIXES_PER_BLOCK
				|| ! self::fits_with( $candidate, $block_kept, $others[ $block_id ] ?? array(), $content )
			) {
				Logger::debug( 'Grammar fix conflicts with another fix on its block, dropping it' );
				unset( $items[ $index ]['quick_fix'] );
				continue;
			}

			$kept[ $block_id ][] = $candidate;
		}

		return $items;
	}

	/**
	 * Whether a grammar fix can join the fixes already kept for its block.
	 *
	 * @param  array{find: string, replace: string, start: int, end: int}       $candidate Grammar fix to add.
	 * @param  list<array{find: string, replace: string, start: int, end: int}> $kept      Grammar fixes already kept.
	 * @param  list<array{find: string, replace: string, start: int, end: int}> $others    The block's other quick fixes.
	 * @param  string                                                           $content   Block content.
	 * @return bool
	 */
	private static function fits_with( array $candidate, array $kept, array $others, string $content ): bool {
		foreach ( array_merge( $others, $kept ) as $other ) {
			if ( $candidate['start'] < $other['end'] && $other['start'] < $candidate['end'] ) {
				return false;
			}
			if ( ! self::stays_put( $candidate, array( $other ), $content ) || ! self::stays_put( $other, array( $candidate ), $content ) ) {
				return false;
			}
		}

		// Kept grammar fixes already work with each other one at a time, so
		// only the whole set Accept all applies is left to check.
		$all = array_merge( $kept, array( $candidate ) );
		if ( count( $all ) > 2 ) {
			foreach ( $all as $index => $fix ) {
				$rest = $all;
				unset( $rest[ $index ] );
				if ( ! self::stays_put( $fix, array_values( $rest ), $content ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Whether a fix's text is still found once, at the same place, after
	 * other fixes are applied.
	 *
	 * @param  array{find: string, replace: string, start: int, end: int}       $fix     Fix to look for.
	 * @param  list<array{find: string, replace: string, start: int, end: int}> $applied Fixes to apply first, none overlapping it.
	 * @param  string                                                           $content Block content.
	 * @return bool
	 */
	private static function stays_put( array $fix, array $applied, string $content ): bool {
		usort( $applied, static fn ( array $a, array $b ): int => $b['start'] <=> $a['start'] );

		$expected = $fix['start'];
		foreach ( $applied as $other ) {
			$content = substr_replace( $content, $other['replace'], $other['start'], $other['end'] - $other['start'] );
			if ( $other['end'] <= $fix['start'] ) {
				$expected += strlen( $other['replace'] ) - ( $other['end'] - $other['start'] );
			}
		}

		return self::find_unique_offset( $fix['find'], $content ) === $expected;
	}

	/**
	 * Parse an AI reply into its prose and optional edit.
	 *
	 * Replies are a JSON object, `{ "reply": "...", "edit": null }`. A reply
	 * that is not JSON is used as prose with no edit. An invalid edit is
	 * dropped and the prose still posts.
	 *
	 * @param  string       $response      Raw AI response.
	 * @param  string       $block_content Plain text of the note's block, as the AI saw it.
	 * @param  bool         $can_rewrite   Whether the block can take a `rewrite_block` edit.
	 * @param  array<mixed> $targets       Blocks a structural edit may point at, keyed by the
	 *                                     reference the AI was shown, including the note's own
	 *                                     block under REPLY_SELF_REF. Empty to allow no structural edit.
	 * @return array{reply: string, edit: array<string, mixed>|null}|null Parsed reply, or null when it has no text.
	 */
	public function parse_reply( string $response, string $block_content, bool $can_rewrite, array $targets = array() ): ?array {
		$data = $this->decode_reply_json( $response );

		if ( null === $data ) {
			// Broken JSON is not prose; posting it would show the raw object.
			$reply = trim( $response );
			return '' === $reply || self::looks_like_json( $reply ) ? null : array(
				'reply' => $reply,
				'edit'  => null,
			);
		}

		$edit  = $this->validate_reply_edit( $data['edit'] ?? null, $block_content, $can_rewrite, $targets );
		$reply = is_string( $data['reply'] ?? null ) ? trim( $data['reply'] ) : '';

		if ( '' === $reply ) {
			if ( null === $edit ) {
				return null;
			}
			$reply = __( 'Here is that change.', 'fresh-eyes' );
		}

		return array(
			'reply' => $reply,
			'edit'  => $edit,
		);
	}

	/**
	 * Decode a reply's JSON object, allowing a code fence or text around it.
	 *
	 * Tries the whole response, then a fenced block, then the first `{` to
	 * the last `}`. When none decodes, for example with two objects or a
	 * truncated one, the `reply` string is read on its own if it can be, so
	 * the raw JSON is never shown to the author.
	 *
	 * @param  string $response Raw AI response.
	 * @return array<mixed>|null Decoded object with a `reply` or `edit` key, or null.
	 */
	private function decode_reply_json( string $response ): ?array {
		$response   = trim( $response );
		$candidates = array( $response );
		if ( preg_match( '/```(?:json)?\s*(.*?)```/s', $response, $fenced ) ) {
			$candidates[] = trim( $fenced[1] );
		}
		$start = strpos( $response, '{' );
		$end   = strrpos( $response, '}' );
		if ( false !== $start && false !== $end && $end > $start ) {
			$candidates[] = substr( $response, $start, $end - $start + 1 );
		}

		foreach ( $candidates as $candidate ) {
			$data = json_decode( $candidate, true );
			if ( is_array( $data ) && ( array_key_exists( 'reply', $data ) || array_key_exists( 'edit', $data ) ) ) {
				return $data;
			}
		}

		if ( preg_match( '/"reply"\s*:\s*("(?:[^"\\\\]|\\\\.)*")/s', $response, $reply ) ) {
			$text = json_decode( $reply[1] );
			if ( is_string( $text ) ) {
				return array( 'reply' => $text );
			}
		}

		return null;
	}

	/**
	 * Whether a non-empty reply could be read, as JSON or as prose.
	 *
	 * False for a reply meant as JSON whose object can't be decoded, which
	 * parse_reply() rejects rather than posting it raw.
	 *
	 * @param  string $response Raw AI response.
	 * @return bool
	 */
	public function is_readable_reply( string $response ): bool {
		return '' === trim( $response )
			|| null !== $this->decode_reply_json( $response )
			|| ! self::looks_like_json( $response );
	}

	/**
	 * Whether a response that did not decode was meant to be JSON.
	 *
	 * @param  string $response Raw AI response.
	 * @return bool
	 */
	private static function looks_like_json( string $response ): bool {
		$response = ltrim( $response );
		return str_starts_with( $response, '{' ) || str_starts_with( $response, '```' );
	}

	/**
	 * Validate the edit an AI reply proposes for its note's block.
	 *
	 * `replace_text` follows the quick fix rules. `rewrite_block` needs a
	 * block with a rich text `content` attribute and may only hold inline
	 * formatting; any other markup drops the edit. An edit naming another
	 * block is dropped.
	 *
	 * A structural edit (`remove_block`, `move_block` or `insert_block`) is
	 * checked by validate_block_action() against `$targets`.
	 *
	 * @param  mixed        $edit          Edit from the AI response.
	 * @param  string       $block_content Plain text of the note's block, as the AI saw it.
	 * @param  bool         $can_rewrite   Whether the block can take a `rewrite_block` edit.
	 * @param  array<mixed> $targets       Blocks a structural edit may point at, see parse_reply().
	 * @return array<string, mixed>|null Sanitized edit, or null if unusable.
	 */
	public function validate_reply_edit( mixed $edit, string $block_content, bool $can_rewrite, array $targets = array() ): ?array {
		if ( ! is_array( $edit ) || isset( $edit['block_id'] ) ) {
			return null;
		}

		$type = $edit['type'] ?? null;

		if ( is_string( $type ) && ( in_array( $type, self::BLOCK_ACTION_TYPES, true ) || isset( self::BLOCK_ACTION_ALIASES[ $type ] ) ) ) {
			return $this->validate_block_action( $edit, self::REPLY_SELF_REF, $targets );
		}

		if ( 'replace_text' === $type ) {
			// A missing replacement is not a deletion; only an empty string is.
			if ( ! is_string( $edit['replace'] ?? null ) ) {
				return null;
			}
			$fix = $this->validate_quick_fix( $edit, $block_content );
			return null === $fix ? null : array( 'type' => 'replace_text' ) + $fix;
		}

		if ( 'rewrite_block' !== $type || ! $can_rewrite || '' === trim( $block_content ) ) {
			return null;
		}

		$content = $edit['content'] ?? null;
		if ( ! is_string( $content ) || '' === trim( $content ) || mb_strlen( $content ) > self::MAX_REWRITE_LENGTH ) {
			return null;
		}

		if ( ! self::has_only_inline_tags( $content ) ) {
			Logger::debug( 'Reply rewrite contains disallowed markup, dropping it' );
			return null;
		}

		$content = trim( wp_kses( $content, self::REWRITE_ALLOWED_TAGS ) );
		$text    = html_entity_decode( wp_strip_all_tags( $content ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( self::normalize_text( $text ) === self::normalize_text( $block_content ) ) {
			return null;
		}

		return array(
			'type'     => 'rewrite_block',
			'content'  => $content,
			// What the rewrite replaces, so the editor can tell when it is outdated.
			'original' => $block_content,
		);
	}

	/**
	 * Whether a stored edit still applies to a block's text.
	 *
	 * A `replace_text` edit (or a review's quick fix) applies while its `find`
	 * appears exactly once, and a `rewrite_block` edit while the block still
	 * reads as the text it was written for.
	 *
	 * Structural edits are not checked here; Reply_Service checks them
	 * against the post's blocks.
	 *
	 * @param  array<string, mixed> $edit          Edit, as decoded by Notes_Manager.
	 * @param  string               $block_content Plain text of the block.
	 * @return bool
	 */
	public function edit_applies( array $edit, string $block_content ): bool {
		if ( 'rewrite_block' === ( $edit['type'] ?? null ) ) {
			return is_string( $edit['original'] ?? null ) && '' !== trim( $block_content )
				&& self::normalize_text( $edit['original'] ) === self::normalize_text( $block_content );
		}

		$find = $edit['find'] ?? '';
		return is_string( $find ) && '' !== $find && 1 === $this->count_quick_fix_matches( $find, $block_content );
	}

	/**
	 * Whether HTML uses only the inline tags a rewrite may contain.
	 *
	 * @param  string $html HTML to check.
	 * @return bool
	 */
	private static function has_only_inline_tags( string $html ): bool {
		// Comments and doctype-like tags can hide block markup.
		if ( str_contains( $html, '<!' ) || str_contains( $html, '<?' ) ) {
			return false;
		}

		preg_match_all( '#</?([a-zA-Z][a-zA-Z0-9-]*)#', $html, $matches );
		foreach ( $matches[1] as $tag ) {
			if ( ! isset( self::REWRITE_ALLOWED_TAGS[ strtolower( $tag ) ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Collapse whitespace so texts compare the same however they wrap.
	 *
	 * @param  string $text Text.
	 * @return string
	 */
	private static function normalize_text( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
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
	 * Produce aggregated counts and flags for a set of parsed feedback items.
	 *
	 * Proofreading (grammar) items count toward their category only, not
	 * toward the severity counts or `has_critical`.
	 *
	 * @param  array $feedback_items Parsed feedback items. Each item is expected to include at least the `category` and `severity` keys.
	 * @return array{
	 *     total_notes: int,
	 *     by_category: array{content:int, tone:int, flow:int, design:int, grammar:int},
	 *     by_severity: array{suggestion:int, important:int, critical:int},
	 *     has_critical: bool
	 * } Summary data with total counts by category and severity, and a boolean indicating presence of any critical items.
	 */
	public function get_feedback_summary( array $feedback_items ): array {
		$summary = array(
			'total_notes'  => count( $feedback_items ),
			'by_category'  => array(
				'content' => 0,
				'tone'    => 0,
				'flow'    => 0,
				'design'  => 0,
				'grammar' => 0,
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

			// Proofreading fixes stay out of the severity counts, so a draft
			// full of typos doesn't read as one with many problems.
			if ( self::GRAMMAR_CATEGORY === ( $item['category'] ?? null ) ) {
				continue;
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
	 * Validates the decoded JSON response structure against the expected schema.
	 *
	 * Performs structural and type checks only; does not perform semantic validations
	 * (for example, it does not verify that referenced block IDs exist).
	 *
	 * @param  mixed $data Decoded JSON data to validate.
	 * @return true|\WP_Error `true` if the data conforms to the schema, a `WP_Error`
	 *                      containing one or more validation errors otherwise.
	 */
	public function validate_schema( $data ) {
		$errors = new \WP_Error();

		// Must be an array/object.
		if ( ! is_array( $data ) ) {
			$errors->add(
				'invalid_type',
				__( 'Response must be a JSON object.', 'fresh-eyes' ),
				array( 'path' => '$' )
			);
			return $errors;
		}

		// Check for required 'feedback' field.
		if ( ! isset( $data['feedback'] ) ) {
			$errors->add(
				'missing_field',
				__( 'Response must contain a "feedback" array.', 'fresh-eyes' ),
				array( 'path' => '$.feedback' )
			);
		} elseif ( ! is_array( $data['feedback'] ) ) {
			$errors->add(
				'invalid_type',
				__( 'The "feedback" field must be an array.', 'fresh-eyes' ),
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
				__( 'The "summary" field must be a string.', 'fresh-eyes' ),
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
	 * Validates a single feedback item against the parser's JSON schema rules.
	 *
	 * Ensures the item is an object/array, contains all required string fields, enforces allowed
	 * values for `category` and `severity`, and checks the optional `suggestion` is a string.
	 *
	 * @param  mixed $item  Feedback item to validate.
	 * @param  int   $index Index of the item in the feedback array (used to build error paths).
	 * @return true|\WP_Error `true` if the item is valid, or a `\WP_Error` containing one or more
	 *                        validation errors with `path` metadata indicating the failing field.
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
					__( 'Feedback item %d must be an object.', 'fresh-eyes' ),
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
						__( 'Feedback item %2$d is missing required field "%1$s".', 'fresh-eyes' ),
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
						__( 'Field "%1$s" in feedback item %2$d must be a string.', 'fresh-eyes' ),
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
						__( 'Invalid category "%1$s" in item %3$d. Must be one of: %2$s.', 'fresh-eyes' ),
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
						__( 'Invalid severity "%1$s" in item %3$d. Must be one of: %2$s.', 'fresh-eyes' ),
						$item['severity'],
						implode( ', ', self::VALID_SEVERITIES ),
						$index
					),
					array( 'path' => $path . '.severity' )
				);
			}
		}

		// A malformed quick_fix is dropped later without rejecting the item.

		// Validate optional suggestion field type.
		if ( isset( $item['suggestion'] ) && ! is_string( $item['suggestion'] ) ) {
			$errors->add(
				'invalid_type',
				sprintf(
				/* translators: %d: item index */
					__( 'Field "suggestion" in feedback item %d must be a string.', 'fresh-eyes' ),
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
	 * Convert a WP_Error of validation failures into a human-readable multi-line string.
	 *
	 * Each line is formatted as "[code] message (at path)" when path information is present.
	 *
	 * @param  \WP_Error $errors Validation errors; individual error data may include a 'path' key.
	 * @return string Multi-line formatted error report.
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

	/**
	 * Group similar feedback items to reduce noise.
	 *
	 * Groups feedback items with the same category and similar titles together.
	 * This helps users identify patterns and fix similar issues more efficiently.
	 *
	 * @param  array<int, array<string, mixed>> $feedback Parsed feedback items.
	 * @return array<int, array<string, mixed>> Grouped feedback items with is_group, count, and block_ids fields.
	 */
	public function group_similar_feedback( array $feedback ): array {
		if ( empty( $feedback ) ) {
			return array();
		}

		$groups = array();

		foreach ( $feedback as $position => $item ) {
			// Create a key based on category and normalized title. Each
			// proofreading fix stands alone, even when titles match.
			$key = self::GRAMMAR_CATEGORY === ( $item['category'] ?? null )
				? self::GRAMMAR_CATEGORY . '#' . $position
				: $this->create_group_key( $item );

			if ( isset( $groups[ $key ] ) ) {
				// Add to existing group.
				$groups[ $key ]['block_ids'][] = $item['block_id'];
				++$groups[ $key ]['count'];
				// Keep highest severity.
				if ( $this->severity_rank( $item['severity'] ) >
					$this->severity_rank( $groups[ $key ]['severity'] ) ) {
					$groups[ $key ]['severity'] = $item['severity'];
				}
				// Keep highest priority across grouped items.
				if ( isset( $item['priority'] ) && $item['priority'] > ( $groups[ $key ]['priority'] ?? 0 ) ) {
					$groups[ $key ]['priority'] = $item['priority'];
				}
				// Preserve block_name and block_index arrays.
				if ( ! empty( $item['block_name'] ) ) {
					$groups[ $key ]['block_names'][] = $item['block_name'];
				}
				if ( isset( $item['block_index'] ) ) {
					$groups[ $key ]['block_indexes'][] = $item['block_index'];
				}
			} else {
				// Create new group.
				$groups[ $key ] = array_merge(
					$item,
					array(
						'block_ids'      => array( $item['block_id'] ),
						'block_names'    => ! empty( $item['block_name'] ) ? array( $item['block_name'] ) : array(),
						'block_indexes'  => isset( $item['block_index'] ) ? array( $item['block_index'] ) : array(),
						'count'          => 1,
						'is_group'       => false,
						'original_title' => $item['title'],
					)
				);
			}
		}

		// Mark items that became groups and update their titles.
		foreach ( $groups as &$group ) {
			if ( $group['count'] > 1 ) {
				// A quick fix or structural edit targets one block, so it
				// can't apply to a group.
				unset( $group['quick_fix'], $group['block_action'], $group['block_change'] );
				$group['is_group'] = true;
				$group['title']    = sprintf(
					/* translators: 1: original title, 2: occurrence count */
					__( '%1$s (%2$d occurrences)', 'fresh-eyes' ),
					$group['original_title'],
					$group['count']
				);
			}
		}

		return array_values( $groups );
	}

	/**
	 * Create a normalized key for grouping feedback items.
	 *
	 * Generates a grouping key based on category and normalized title.
	 * Normalization converts to lowercase and replaces spaces with underscores
	 * while removing other special characters.
	 *
	 * @param  array $item Feedback item with category and title.
	 * @return string Grouping key.
	 */
	private function create_group_key( array $item ): string {
		// Normalize title for grouping.
		$normalized = strtolower( $item['title'] );
		// Replace spaces and hyphens with underscores to preserve word boundaries.
		$normalized = str_replace( array( ' ', '-' ), '_', $normalized );
		// Remove special characters but keep alphanumeric and underscores.
		$normalized = preg_replace( '/[^a-z0-9_]/', '', $normalized );

		return $item['category'] . ':' . $normalized;
	}

	/**
	 * Get numeric rank for severity level.
	 *
	 * Returns a numeric value for each severity level to enable comparison.
	 * Higher numbers indicate higher severity.
	 *
	 * @param  string $severity Severity level (suggestion, important, critical).
	 * @return int Numeric rank (1-3, or 0 for unknown).
	 */
	private function severity_rank( string $severity ): int {
		$ranks = array(
			'suggestion' => 1,
			'important'  => 2,
			'critical'   => 3,
		);

		return $ranks[ $severity ] ?? 0;
	}
}
