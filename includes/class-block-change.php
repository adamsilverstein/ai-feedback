<?php
/**
 * Block Change
 *
 * Formatting fixes a review note can carry as `block_change`.
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

/**
 * Validates and decodes formatting fixes.
 *
 * A formatting fix changes one block without touching its text:
 *
 * - `{ "type": "set_attributes", "attributes": { "level": 3 }, "from": { "level": 4 } }`
 *   sets allow-listed attributes. `from` holds the values the AI saw, so the
 *   editor can tell a stale change from one that is ready. Alt text and a
 *   caption also keep the reviewed image's URL as `image`.
 * - Heading and paragraph transforms keep the reviewed `text`, and a split
 *   the start of the text after it as `next` and its length in characters
 *   as `next_length`, for the same reason.
 * - `{ "type": "transform", "to": "core/list", "items": [ ... ] }` turns a
 *   paragraph of separated items into a list; `to` may also be a heading
 *   (with `level`) or, from a heading, a paragraph.
 * - `{ "type": "split", "after": "exact sentence ending." }` splits a
 *   paragraph in two after that sentence.
 *
 * Text in a change is checked against the block's own text, so applying it
 * never adds, drops or rewrites words. The editor checks it all again
 * against the live blocks (src/utils/block-change.js).
 */
class Block_Change {

	/**
	 * Formatting fix types.
	 */
	public const TYPES = array( 'set_attributes', 'transform', 'split' );

	/**
	 * Attributes a `set_attributes` change may set, per block type.
	 */
	public const SETTABLE_ATTRIBUTES = array(
		'core/heading' => array( 'level' ),
		'core/list'    => array( 'ordered' ),
		'core/image'   => array( 'alt', 'caption' ),
	);

	/**
	 * Attributes the AI can only propose when it was shown the image.
	 */
	public const IMAGE_ATTRIBUTES = array( 'alt', 'caption' );

	/**
	 * Block types a `transform` change may turn a block into, per block type.
	 */
	public const TRANSFORMS = array(
		'core/paragraph' => array( 'core/list', 'core/heading' ),
		'core/heading'   => array( 'core/paragraph' ),
	);

	/**
	 * Maximum length of proposed alt text.
	 */
	public const MAX_ALT_LENGTH = 250;

	/**
	 * Longest current alt text read from a request; enough to tell empty
	 * alt text from any other.
	 */
	private const MAX_READ_ALT_LENGTH = 500;

	/**
	 * Longest image URL read from a request.
	 */
	private const MAX_URL_LENGTH = 2048;

	/**
	 * Maximum length of a proposed caption.
	 */
	public const MAX_CAPTION_LENGTH = 300;

	/**
	 * Maximum length of the sentence ending a split is made after.
	 */
	public const MAX_SPLIT_LENGTH = 200;

	/**
	 * Length of the text after a split kept with it as `next`.
	 */
	public const MAX_NEXT_LENGTH = 80;

	/**
	 * Maximum length of a paragraph that may become a heading.
	 */
	public const MAX_HEADING_LENGTH = 200;

	/**
	 * Fewest items a paragraph must split into to become a list.
	 */
	public const MIN_LIST_ITEMS = 2;

	/**
	 * Text allowed between two list items: separators, then an optional
	 * conjunction. Matches LIST_GAP in src/utils/block-change.js. The
	 * conjunctions are deliberately few; any other text between items drops
	 * the change rather than the text.
	 */
	private const LIST_GAP = '/^[\s,;、，；]+(?:(?:and|or|&|und|oder|et|ou|y|o|e|en)\s+)?$/iu';

	/**
	 * Text allowed after the last list item.
	 */
	private const LIST_TAIL = '/^[\s.,;!?…。]*$/u';

	/**
	 * How a sentence a split is made after must end.
	 */
	private const SENTENCE_END = '/[.!?…。！？]["\'”’»)\]]*$/u';

	/**
	 * Read the attributes the editor sent for a block, keeping only the ones
	 * formatting fixes use, with their types checked.
	 *
	 * @param  mixed $block Block from the review request.
	 * @return array<string, mixed> Attributes: heading `level`, list `ordered`, image `alt`, `id` and `url`.
	 */
	public static function get_block_attributes( mixed $block ): array {
		if ( ! is_array( $block ) || ! is_array( $block['attributes'] ?? null ) ) {
			return array();
		}
		$raw   = $block['attributes'];
		$attrs = array();

		switch ( $block['name'] ?? '' ) {
			case 'core/heading':
				$level = $raw['level'] ?? null;
				if ( is_int( $level ) && $level >= 1 && $level <= 6 ) {
					$attrs['level'] = $level;
				}
				break;
			case 'core/list':
				if ( is_bool( $raw['ordered'] ?? null ) ) {
					$attrs['ordered'] = $raw['ordered'];
				}
				break;
			case 'core/image':
				if ( is_string( $raw['alt'] ?? null ) ) {
					$attrs['alt'] = mb_substr( $raw['alt'], 0, self::MAX_READ_ALT_LENGTH );
				}
				if ( is_int( $raw['id'] ?? null ) && $raw['id'] > 0 ) {
					$attrs['id'] = $raw['id'];
				}
				if ( is_string( $raw['url'] ?? null ) && strlen( $raw['url'] ) <= self::MAX_URL_LENGTH ) {
					$attrs['url'] = $raw['url'];
				}
				break;
		}

		return $attrs;
	}

	/**
	 * Validate a formatting fix against the block it changes.
	 *
	 * @param  mixed                $change     Formatting fix from the AI response.
	 * @param  array<string, mixed> $block      The block: `name`, `content` (its text) and `attributes`
	 *                                          from get_block_attributes().
	 * @param  bool                 $saw_image  Whether the AI was shown this block's image.
	 * @return array<string, mixed>|null Sanitized change, or null if unusable.
	 */
	public static function validate( mixed $change, array $block, bool $saw_image = false ): ?array {
		if ( ! is_array( $change ) ) {
			return null;
		}
		$name  = is_string( $block['name'] ?? null ) ? $block['name'] : '';
		$text  = self::normalize( is_string( $block['content'] ?? null ) ? $block['content'] : '' );
		$attrs = is_array( $block['attributes'] ?? null ) ? $block['attributes'] : array();

		return match ( $change['type'] ?? null ) {
			'set_attributes' => self::validate_set_attributes( $change['attributes'] ?? null, $name, $text, $attrs, $saw_image ),
			'transform'      => self::validate_transform( $change, $name, $text ),
			'split'          => self::validate_split( $change['after'] ?? null, $name, $text ),
			default          => null,
		};
	}

	/**
	 * Validate a `set_attributes` change.
	 *
	 * Every attribute must be on the block type's allow-list and differ from
	 * its current value, or the whole change is dropped. Alt text and a
	 * caption need the AI to have seen the image, and are only proposed where
	 * there are none, so nothing the author wrote is replaced.
	 *
	 * @param  mixed        $proposed  Proposed attributes.
	 * @param  string       $name      Block name.
	 * @param  string       $text      Block text, normalized.
	 * @param  array<mixed> $attrs     Current attributes.
	 * @param  bool         $saw_image Whether the AI was shown the image.
	 * @return array<string, mixed>|null Change, or null if unusable.
	 */
	private static function validate_set_attributes( mixed $proposed, string $name, string $text, array $attrs, bool $saw_image ): ?array {
		$allowed = self::SETTABLE_ATTRIBUTES[ $name ] ?? array();
		if ( ! is_array( $proposed ) || array() === $proposed || count( $proposed ) > count( $allowed ) ) {
			return null;
		}

		$to   = array();
		$from = array();
		foreach ( $proposed as $key => $value ) {
			if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) ) {
				return null;
			}
			if ( in_array( $key, self::IMAGE_ATTRIBUTES, true ) && ! $saw_image ) {
				return null;
			}

			switch ( $key ) {
				case 'level':
					$current = is_int( $attrs['level'] ?? null ) ? $attrs['level'] : 2;
					if ( ! is_int( $value ) || $value < 2 || $value > 6 || $value === $current ) {
						return null;
					}
					break;
				case 'ordered':
					$current = true === ( $attrs['ordered'] ?? false );
					if ( ! is_bool( $value ) || $value === $current ) {
						return null;
					}
					break;
				case 'alt':
					$current = is_string( $attrs['alt'] ?? null ) ? $attrs['alt'] : '';
					$value   = self::sanitize_plain_text( $value, self::MAX_ALT_LENGTH );
					if ( null === $value || '' !== trim( $current ) ) {
						return null;
					}
					break;
				default:
					// A caption: the block's text is its caption.
					$current = '';
					$value   = self::sanitize_plain_text( $value, self::MAX_CAPTION_LENGTH );
					if ( null === $value || '' !== $text ) {
						return null;
					}
					break;
			}

			$to[ $key ]   = $value;
			$from[ $key ] = $current;
		}

		$change = array(
			'type'       => 'set_attributes',
			'attributes' => $to,
			'from'       => $from,
		);
		// Alt text describes one image; a different image is a stale change.
		if ( array() !== array_intersect( array_keys( $to ), self::IMAGE_ATTRIBUTES ) ) {
			if ( ! is_string( $attrs['url'] ?? null ) || '' === $attrs['url'] ) {
				return null;
			}
			$change['image'] = $attrs['url'];
		}
		return $change;
	}

	/**
	 * Validate a `transform` change.
	 *
	 * @param  array<mixed> $change Change from the AI response.
	 * @param  string       $name   Block name.
	 * @param  string       $text   Block text, normalized.
	 * @return array<string, mixed>|null Change, or null if unusable.
	 */
	private static function validate_transform( array $change, string $name, string $text ): ?array {
		$to = $change['to'] ?? null;
		if ( ! is_string( $to ) || ! in_array( $to, self::TRANSFORMS[ $name ] ?? array(), true ) || '' === $text ) {
			return null;
		}

		if ( 'core/list' === $to ) {
			$items = self::sanitize_list_items( $change['items'] ?? null );
			if ( null === $items || null === self::match_list_items( $text, $items ) ) {
				return null;
			}
			return array(
				'type'    => 'transform',
				'to'      => $to,
				'items'   => $items,
				'ordered' => true === ( $change['ordered'] ?? false ),
			);
		}

		// A heading is short; the text kept checks the block is unchanged.
		if ( mb_strlen( $text ) > self::MAX_HEADING_LENGTH ) {
			return null;
		}

		if ( 'core/heading' === $to ) {
			$level = $change['level'] ?? 2;
			if ( ! is_int( $level ) || $level < 2 || $level > 6 ) {
				return null;
			}
			return array(
				'type'  => 'transform',
				'to'    => $to,
				'level' => $level,
				'text'  => $text,
			);
		}

		return array(
			'type' => 'transform',
			'to'   => $to,
			'text' => $text,
		);
	}

	/**
	 * Validate a `split` change: a paragraph split after a sentence that
	 * appears once in it and has more text after it.
	 *
	 * @param  mixed  $after Sentence ending to split after.
	 * @param  string $name  Block name.
	 * @param  string $text  Block text, normalized.
	 * @return array<string, mixed>|null Change, or null if unusable.
	 */
	private static function validate_split( mixed $after, string $name, string $text ): ?array {
		if ( 'core/paragraph' !== $name || ! is_string( $after ) || str_contains( $after, '<' ) ) {
			return null;
		}
		$after = self::normalize( $after );
		if ( '' === $after || mb_strlen( $after ) > self::MAX_SPLIT_LENGTH || 1 !== preg_match( self::SENTENCE_END, $after ) ) {
			return null;
		}
		if ( 1 !== mb_substr_count( $text, $after ) ) {
			return null;
		}

		// The split falls at a space, with text on both sides.
		$end  = (int) mb_strpos( $text, $after ) + mb_strlen( $after );
		$rest = mb_substr( $text, $end );
		if ( ! str_starts_with( $rest, ' ' ) || '' === trim( $rest ) ) {
			return null;
		}

		return array(
			'type'        => 'split',
			'after'       => $after,
			'next'        => mb_substr( trim( $rest ), 0, self::MAX_NEXT_LENGTH ),
			'next_length' => mb_strlen( trim( $rest ) ),
		);
	}

	/**
	 * Check list items against a paragraph's text.
	 *
	 * The items must appear in the text in order, separated only by commas,
	 * semicolons and a conjunction, with nothing but punctuation after the
	 * last one. Text before the first item stays as an introduction. So
	 * turning the paragraph into a list only drops separators.
	 *
	 * @param  string   $text  Paragraph text, normalized.
	 * @param  string[] $items Items, normalized.
	 * @return array{lead: string}|null The introduction, or null when the items don't match.
	 */
	public static function match_list_items( string $text, array $items ): ?array {
		$position = 0;
		$lead     = '';
		foreach ( $items as $index => $item ) {
			$found = mb_strpos( $text, $item, $position );
			if ( false === $found ) {
				return null;
			}
			$gap = mb_substr( $text, $position, $found - $position );
			if ( 0 === $index ) {
				// The first item starts a word.
				if ( 1 === preg_match( '/[\p{L}\p{N}]$/u', $gap ) ) {
					return null;
				}
				$lead = trim( $gap );
			} elseif ( 1 !== preg_match( self::LIST_GAP, $gap ) ) {
				return null;
			}
			$position = $found + mb_strlen( $item );
		}

		return 1 === preg_match( self::LIST_TAIL, mb_substr( $text, $position ) ) ? array( 'lead' => $lead ) : null;
	}

	/**
	 * Sanitize proposed list items.
	 *
	 * @param  mixed $items Items from the AI response.
	 * @return list<string>|null Normalized items, or null if unusable.
	 */
	private static function sanitize_list_items( mixed $items ): ?array {
		if ( ! is_array( $items ) || count( $items ) < self::MIN_LIST_ITEMS || count( $items ) > Response_Parser::MAX_INSERT_LIST_ITEMS ) {
			return null;
		}
		$clean = array();
		foreach ( $items as $item ) {
			if ( ! is_string( $item ) || str_contains( $item, '<' ) || mb_strlen( $item ) > Response_Parser::MAX_INSERT_LENGTH ) {
				return null;
			}
			$item = self::normalize( $item );
			if ( '' === $item ) {
				return null;
			}
			$clean[] = $item;
		}
		return $clean;
	}

	/**
	 * Sanitize proposed alt text or a caption: plain text only.
	 *
	 * @param  mixed $value Proposed text.
	 * @param  int   $max   Maximum length in characters.
	 * @return string|null Text, or null if unusable.
	 */
	private static function sanitize_plain_text( mixed $value, int $max ): ?string {
		if ( ! is_string( $value ) || str_contains( $value, '<' ) ) {
			return null;
		}
		$value = self::normalize( sanitize_text_field( $value ) );
		return '' === $value || mb_strlen( $value ) > $max ? null : $value;
	}

	/**
	 * Collapse whitespace, as the editor does before comparing text.
	 *
	 * @param  string $text Text.
	 * @return string Normalized text.
	 */
	public static function normalize( string $text ): string {
		$normalized = preg_replace( '/\s+/u', ' ', $text );
		return trim( is_string( $normalized ) ? $normalized : '' );
	}

	/**
	 * Decode a stored formatting fix.
	 *
	 * Only the fields each type needs are kept, with the allow-lists checked
	 * again, so the editor never acts on a malformed change or one stored
	 * before an allow-list was narrowed.
	 *
	 * @param  mixed  $stored     Stored meta value (JSON string).
	 * @param  string $block_name Name of the note's block, when known.
	 * @return array<string, mixed>|null Change, or null if none.
	 */
	public static function decode( mixed $stored, string $block_name = '' ): ?array {
		$decoded = is_string( $stored ) && '' !== $stored ? json_decode( $stored, true ) : null;
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		switch ( $decoded['type'] ?? null ) {
			case 'set_attributes':
				return self::decode_set_attributes( $decoded, $block_name );
			case 'transform':
				return self::decode_transform( $decoded, $block_name );
			case 'split':
				$after = $decoded['after'] ?? null;
				$next  = $decoded['next'] ?? null;
				$count = $decoded['next_length'] ?? null;
				return is_string( $after ) && '' !== $after && mb_strlen( $after ) <= self::MAX_SPLIT_LENGTH && ! str_contains( $after, '<' )
					&& is_string( $next ) && '' !== $next && mb_strlen( $next ) <= self::MAX_NEXT_LENGTH
					&& is_int( $count ) && $count >= mb_strlen( $next )
					? array(
						'type'        => 'split',
						'after'       => $after,
						'next'        => $next,
						'next_length' => $count,
					)
					: null;
			default:
				return null;
		}
	}

	/**
	 * Decode a stored `set_attributes` change.
	 *
	 * @param  array<mixed> $decoded    Decoded change.
	 * @param  string       $block_name Block name, or '' when unknown.
	 * @return array<string, mixed>|null Change, or null if unusable.
	 */
	private static function decode_set_attributes( array $decoded, string $block_name ): ?array {
		$to   = $decoded['attributes'] ?? null;
		$from = $decoded['from'] ?? null;
		if ( ! is_array( $to ) || ! is_array( $from ) || array() === $to ) {
			return null;
		}

		$allowed = '' === $block_name
			? array_merge( ...array_values( self::SETTABLE_ATTRIBUTES ) )
			: ( self::SETTABLE_ATTRIBUTES[ $block_name ] ?? array() );

		$clean = array(
			'type'       => 'set_attributes',
			'attributes' => array(),
			'from'       => array(),
		);
		foreach ( $to as $key => $value ) {
			if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) || ! array_key_exists( $key, $from ) ) {
				return null;
			}
			$valid = match ( $key ) {
				'level'   => is_int( $value ) && $value >= 2 && $value <= 6 && is_int( $from[ $key ] ),
				'ordered' => is_bool( $value ) && is_bool( $from[ $key ] ),
				'alt'     => is_string( $value ) && '' !== $value && mb_strlen( $value ) <= self::MAX_ALT_LENGTH && ! str_contains( $value, '<' ) && is_string( $from[ $key ] ),
				default   => is_string( $value ) && '' !== $value && mb_strlen( $value ) <= self::MAX_CAPTION_LENGTH && ! str_contains( $value, '<' ) && is_string( $from[ $key ] ),
			};
			if ( ! $valid ) {
				return null;
			}
			$clean['attributes'][ $key ] = $value;
			$clean['from'][ $key ]       = $from[ $key ];
		}

		if ( array() !== array_intersect( array_keys( $clean['attributes'] ), self::IMAGE_ATTRIBUTES ) ) {
			$image = $decoded['image'] ?? null;
			if ( ! is_string( $image ) || '' === $image || strlen( $image ) > self::MAX_URL_LENGTH ) {
				return null;
			}
			$clean['image'] = $image;
		}

		return $clean;
	}

	/**
	 * Decode a stored `transform` change.
	 *
	 * @param  array<mixed> $decoded    Decoded change.
	 * @param  string       $block_name Block name, or '' when unknown.
	 * @return array<string, mixed>|null Change, or null if unusable.
	 */
	private static function decode_transform( array $decoded, string $block_name ): ?array {
		$to      = $decoded['to'] ?? null;
		$targets = '' === $block_name
			? array_merge( ...array_values( self::TRANSFORMS ) )
			: ( self::TRANSFORMS[ $block_name ] ?? array() );
		if ( ! is_string( $to ) || ! in_array( $to, $targets, true ) ) {
			return null;
		}

		if ( 'core/list' === $to ) {
			$items = self::sanitize_list_items( $decoded['items'] ?? null );
			return null === $items ? null : array(
				'type'    => 'transform',
				'to'      => $to,
				'items'   => $items,
				'ordered' => true === ( $decoded['ordered'] ?? false ),
			);
		}

		$text = $decoded['text'] ?? null;
		if ( ! is_string( $text ) || '' === $text || mb_strlen( $text ) > self::MAX_HEADING_LENGTH ) {
			return null;
		}

		if ( 'core/heading' === $to ) {
			$level = $decoded['level'] ?? null;
			return is_int( $level ) && $level >= 2 && $level <= 6 ? array(
				'type'  => 'transform',
				'to'    => $to,
				'level' => $level,
				'text'  => $text,
			) : null;
		}

		return array(
			'type' => 'transform',
			'to'   => $to,
			'text' => $text,
		);
	}
}
