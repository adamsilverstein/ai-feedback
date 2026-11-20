<?php
/**
 * Mentions
 *
 * Finds `@fresh-eyes` in a note, so a note in any thread can ask Fresh
 * Eyes about the text or blocks it is attached to.
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mention detection and the guards around answering a mention.
 *
 * Note content is user input. Only its text is read: classes, IDs and
 * attributes never decide whether a note mentions Fresh Eyes.
 */
class Mentions {

	/**
	 * Most characters of a note scanned for a mention.
	 */
	public const MAX_SCAN_LENGTH = 5000;

	/**
	 * Most characters of an inline note's selected text sent to the AI.
	 */
	public const MAX_SELECTION_LENGTH = 1000;

	/**
	 * Comment meta key flagging a note queued because it mentions Fresh Eyes.
	 */
	public const META_KEY = 'ai_feedback_mention';

	/**
	 * HTML attributes that hold a link or load something from an address.
	 */
	private const LINK_ATTRIBUTES = 'href|src|srcset|cite|action|formaction|poster|data|background|longdesc|usemap|xlink:href|ping';

	/**
	 * A web address in text: `http(s)://` or `www.` up to whitespace,
	 * quotes, brackets or a backslash.
	 */
	private const URL_PATTERN = '#\b(?:https?://|www\.)[^\s"\'<>\[\]\\\\]+#i';

	/**
	 * Elements whose text is quoted, not written by the note's author.
	 */
	private const QUOTED_TAGS = 'blockquote|q|code|pre';

	/**
	 * Names the author can type after `@`.
	 *
	 * Words may be joined by a hyphen, an underscore, a space or nothing, so
	 * `fresh eyes` matches `@fresh-eyes`, `@FreshEyes` and `@Fresh Eyes`.
	 *
	 * @return list<string> Handles, as lowercase words separated by spaces.
	 */
	public static function get_handles(): array {
		$handles = array( 'fresh eyes', mb_strtolower( __( 'Fresh Eyes', 'fresh-eyes' ) ) );

		/**
		 * Filters the names that mention Fresh Eyes in a note.
		 *
		 * @param string[] $handles Handles, as words separated by spaces.
		 */
		$filtered = apply_filters( 'ai_feedback_mention_handles', $handles );
		/**
		 * A filter may return anything.
		 *
		 * @var mixed $handles
		 */
		$handles = $filtered;

		$clean = array();
		foreach ( is_array( $handles ) ? $handles : array() as $handle ) {
			$handle = is_string( $handle ) ? trim( (string) preg_replace( '/[\s_-]+/u', ' ', mb_strtolower( $handle ) ) ) : '';
			if ( '' !== $handle && mb_strlen( $handle ) <= 50 ) {
				$clean[] = $handle;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Whether note content mentions Fresh Eyes.
	 *
	 * Reads the note's text, leaving out quoted text, so a mention someone
	 * quotes or pastes as code does not trigger an answer. A completer chip
	 * for the Fresh Eyes user counts whatever name it shows. An email address
	 * or a longer name that starts with a handle is not a mention.
	 *
	 * @param string $content Note content, as stored.
	 * @return bool
	 */
	public static function mentions_fresh_eyes( string $content ): bool {
		// A chip the Notes `@` completer saved for the Fresh Eyes user.
		if ( self::has_user_chip( $content ) ) {
			return true;
		}

		// Most notes have no at sign, plain or encoded; skip the regex work for them.
		if ( ! str_contains( $content, '@' ) && false === stripos( $content, '&#64;' ) && false === stripos( $content, '&#x40;' ) && false === stripos( $content, '&commat;' ) ) {
			return false;
		}

		$text = self::note_text( $content );
		if ( '' === $text || ! str_contains( $text, '@' ) ) {
			return false;
		}

		$names = array();
		foreach ( self::get_handles() as $handle ) {
			$words   = array_map( static fn ( string $word ): string => preg_quote( $word, '/' ), explode( ' ', $handle ) );
			$names[] = implode( '[\s_-]?', $words );
		}
		if ( array() === $names ) {
			return false;
		}

		$pattern = '/(?<![\p{L}\p{N}_.@\/-])@(?:' . implode( '|', $names ) . ')(?![\p{L}\p{N}_-])(?!\.[\p{L}\p{N}])/iu';
		return 1 === preg_match( $pattern, $text );
	}

	/**
	 * Get a note's own text: tags and quoted elements removed, entities
	 * decoded, and the length bounded.
	 *
	 * @param string $content Note content.
	 * @return string Plain text.
	 */
	public static function note_text( string $content ): string {
		// Bound the work before any regex runs on user input.
		$content = self::strip_quoted( mb_substr( $content, 0, self::MAX_SCAN_LENGTH * 2 ) );
		if ( null === $content ) {
			return '';
		}

		$text = html_entity_decode( wp_strip_all_tags( $content ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', $text ) ), 0, self::MAX_SCAN_LENGTH );
	}

	/**
	 * Remove quoted elements from note HTML.
	 *
	 * @param string $content Note content, already bounded.
	 * @return string|null HTML without quoted elements, or null when it could not be read.
	 */
	private static function strip_quoted( string $content ): ?string {
		// Inner quotes go first, so each pass removes one level of nesting.
		for ( $pass = 0; $pass < 5; $pass++ ) {
			$stripped = preg_replace( '#<(' . self::QUOTED_TAGS . ')\b[^>]*>(?:(?!<(?:' . self::QUOTED_TAGS . ')\b).)*?</\1\s*>#is', ' ', $content );
			if ( null === $stripped ) {
				// The regex failed, eg. on the backtrack limit. Read nothing
				// rather than text that may still hold a quoted mention.
				return null;
			}
			if ( $stripped === $content ) {
				break;
			}
			$content = $stripped;
		}
		// An unclosed quote runs to the end of the note.
		return preg_replace( '#<(?:' . self::QUOTED_TAGS . ')\b.*$#is', ' ', $content );
	}

	/**
	 * Whether note content holds a Notes `@` completer chip for the Fresh Eyes user:
	 * `<span class="wp-note-mention user-N">`, outside quoted text.
	 *
	 * @param string $content Note content, as stored.
	 * @return bool
	 */
	public static function has_user_chip( string $content ): bool {
		$user_id = Fresh_Eyes_User::get_id();
		if ( $user_id <= 0 || ! str_contains( $content, 'wp-note-mention' ) ) {
			return false;
		}

		$html = self::strip_quoted( mb_substr( $content, 0, self::MAX_SCAN_LENGTH * 2 ) );
		if ( null === $html || ! preg_match_all( '#<span\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>#i', $html, $tags ) ) {
			return false;
		}

		foreach ( $tags[1] as $attributes ) {
			foreach ( self::parse_attributes( $attributes ) as list( $name, $value ) ) {
				if ( 'class' !== $name ) {
					continue;
				}
				// A browser uses the first class attribute.
				$tokens = preg_split( '/\s+/', trim( $value ) );
				if ( is_array( $tokens ) && in_array( 'wp-note-mention', $tokens, true ) && in_array( 'user-' . $user_id, $tokens, true ) ) {
					return true;
				}
				break;
			}
		}
		return false;
	}

	/**
	 * Whether a user may ask Fresh Eyes about a post: the same capabilities
	 * as running a review of it.
	 *
	 * @param int $user_id User ID.
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function can_ask( int $user_id, int $post_id ): bool {
		return $user_id > 0
			&& $post_id > 0
			&& user_can( $user_id, 'edit_posts' )
			&& user_can( $user_id, 'edit_post', $post_id );
	}

	/**
	 * Get the text an inline note is anchored to in its block.
	 *
	 * Gutenberg wraps the text a note is about in
	 * `<mark class="wp-note" data-id="N">`. A note split by other formatting
	 * has several markers; their text is joined.
	 *
	 * @param string $block_html Block HTML, without suggestion markers.
	 * @param int    $note_id    Root note ID.
	 * @param int    $limit      Optional. Most characters kept before the text is cut with "...".
	 * @return string Selected text, or '' for a note on the whole block.
	 */
	public static function get_selected_text( string $block_html, int $note_id, int $limit = self::MAX_SELECTION_LENGTH ): string {
		if ( $note_id <= 0 || ! str_contains( $block_html, 'wp-note' ) ) {
			return '';
		}

		// Walk the tags, keeping a stack of open marks, so other marks inside
		// or around the note's marker, including another note's, keep its text whole.
		$count = preg_match_all( '#<(/?)([a-zA-Z][a-zA-Z0-9-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>#', $block_html, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		if ( ! $count ) {
			return '';
		}

		$stack  = array();
		$inside = 0;
		$text   = '';
		// Text between two stretches of the note, so their words join as written.
		$gap    = '';
		$offset = 0;
		foreach ( $tags as $tag ) {
			$between = substr( $block_html, $offset, $tag[0][1] - $offset );
			if ( $inside > 0 ) {
				$text .= $between;
			} else {
				$gap .= $between;
			}
			$offset = $tag[0][1] + strlen( $tag[0][0] );

			$name = strtolower( $tag[2][0] );
			if ( 'br' === $name ) {
				// A line break separates words; other tags, eg. bold, do not.
				$text .= $inside > 0 ? ' ' : '';
				$gap  .= ' ';
				continue;
			}
			if ( 'mark' !== $name ) {
				continue;
			}

			if ( '/' === $tag[1][0] ) {
				if ( true === array_pop( $stack ) ) {
					--$inside;
				}
				continue;
			}

			$is_note = self::is_note_marker( $tag[3][0], $note_id );
			if ( $is_note && 0 === $inside ) {
				// Another stretch of the same note, joined as the text reads.
				$text .= '' !== $text && 1 === preg_match( '/\s|&nbsp;/u', $gap ) ? ' ' : '';
				$gap   = '';
			}
			$stack[] = $is_note;
			$inside += $is_note ? 1 : 0;
		}

		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		return mb_strlen( $text ) > $limit ? mb_substr( $text, 0, $limit ) . '...' : $text;
	}

	/**
	 * Whether a mark's attributes make it the marker of a note.
	 *
	 * @param string $attributes Attributes of a `<mark>` tag.
	 * @param int    $note_id    Note ID.
	 * @return bool
	 */
	private static function is_note_marker( string $attributes, int $note_id ): bool {
		return 1 === preg_match( '#\bclass\s*=\s*(["\'])(?:[^"\']*\s)?wp-note(?:\s[^"\']*)?\1#i', $attributes )
			&& 1 === preg_match( '#\bdata-id\s*=\s*(["\'])\s*(\d+)\s*\1#i', $attributes, $id )
			&& (int) $id[2] === $note_id;
	}

	/**
	 * Find the web addresses in text.
	 *
	 * @param string $text Text or HTML.
	 * @return list<string> Addresses, lowercased and without a trailing slash or punctuation.
	 */
	public static function find_urls( string $text ): array {
		if ( ! preg_match_all( self::URL_PATTERN, $text, $matches ) ) {
			return array();
		}

		$urls = array();
		foreach ( $matches[0] as $url ) {
			$urls[] = self::normalize_url( self::split_url( $url )[0] );
		}
		return array_values( array_unique( $urls ) );
	}

	/**
	 * Remove addresses from an AI answer that are not in its sources.
	 *
	 * Answers to mentions are written without web search, so a link the
	 * post, the thread and the request do not already hold was made up.
	 *
	 * @param string $reply   AI reply text.
	 * @param string $sources Everything the AI was given.
	 * @return string Reply without made-up addresses.
	 */
	public static function remove_unsourced_urls( string $reply, string $sources ): string {
		$known = self::find_urls( $sources );

		$cleaned = preg_replace_callback(
			self::URL_PATTERN,
			static function ( array $found ) use ( $known ): string {
				list( $url, $rest ) = self::split_url( $found[0] );
				if ( in_array( self::normalize_url( $url ), $known, true ) ) {
					return $found[0];
				}
				return __( '[link removed]', 'fresh-eyes' ) . $rest;
			},
			$reply
		);

		return is_string( $cleaned ) ? $cleaned : $reply;
	}

	/**
	 * Whether a suggested edit adds a web address that is not in its sources.
	 *
	 * @param array<mixed> $edit    Suggested edit.
	 * @param string       $sources Everything the AI was given.
	 * @return bool
	 */
	public static function edit_has_unsourced_url( array $edit, string $sources ): bool {
		$known = array_merge( self::find_urls( self::decode( $sources ) ), self::find_link_attributes( $sources ) );

		foreach ( self::string_values( $edit ) as $value ) {
			$targets = array_merge( self::find_urls( self::decode( $value ) ), self::find_link_attributes( $value ) );
			foreach ( $targets as $target ) {
				if ( ! in_array( $target, $known, true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Find the targets of link-bearing attributes, such as `href`, in HTML.
	 *
	 * Values are decoded and read the way a browser does: whitespace and
	 * control characters are dropped, and a scheme-less `//host` link is
	 * the web address it points to. A value that still holds an entity a
	 * browser might decode is kept as it is, so it never matches a source.
	 *
	 * @param string $html HTML.
	 * @return list<string> Normalized targets. Links to a spot on the page (`#id`) are left out.
	 */
	public static function find_link_attributes( string $html ): array {
		if ( ! str_contains( $html, '<' ) ) {
			return array();
		}

		$targets = array();
		preg_match_all( '#<[a-zA-Z][a-zA-Z0-9:-]*((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>#', $html, $tags );
		$link_names = explode( '|', self::LINK_ATTRIBUTES );
		foreach ( $tags[1] as $attributes ) {
			foreach ( self::parse_attributes( $attributes ) as list( $name, $raw ) ) {
				if ( ! in_array( $name, $link_names, true ) ) {
					continue;
				}
				$target = self::normalize_link( $raw );
				if ( '' !== $target && ! str_starts_with( $target, '#' ) ) {
					$targets[] = $target;
				}
			}
		}

		return array_values( array_unique( $targets ) );
	}

	/**
	 * Split a tag's attributes into names and values, left to right.
	 *
	 * Each quoted value is consumed whole, so a name inside another
	 * attribute's value, as in `title="class='x'"`, is not an attribute,
	 * while one right after a closing quote, as in `title="x"href="y"`, is.
	 *
	 * @param string $attributes Everything between a tag's name and its `>`.
	 * @return list<array{string, string}> Lowercase names and raw values, in order.
	 */
	private static function parse_attributes( string $attributes ): array {
		preg_match_all( '#([^\s"\'>/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?#', $attributes, $found, PREG_SET_ORDER );

		$pairs = array();
		foreach ( $found as $attribute ) {
			$pairs[] = array( strtolower( $attribute[1] ), ( $attribute[2] ?? '' ) . ( $attribute[3] ?? '' ) . ( $attribute[4] ?? '' ) );
		}
		return $pairs;
	}

	/**
	 * Read a link attribute's value as a browser would, for comparison.
	 *
	 * @param string $value Raw attribute value.
	 * @return string Normalized target.
	 */
	private static function normalize_link( string $value ): string {
		$decoded = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Browsers drop tabs and line breaks anywhere in a URL, and spaces and
		// control characters around it.
		$link = (string) preg_replace( '/[\x00-\x20\x7f]+/', '', $decoded );
		if ( str_contains( $link, '&' ) ) {
			// An entity without its semicolon, which a browser may still decode.
			return 'unreadable:' . $link;
		}
		if ( str_starts_with( $link, '//' ) ) {
			$link = 'https:' . $link;
		}
		if ( preg_match( '#^https?://#i', $link ) ) {
			return self::normalize_url( $link );
		}
		// Another scheme, such as mailto: or javascript:, is case-insensitive.
		return (string) preg_replace_callback( '#^[a-z][a-z0-9+.-]*:#i', static fn ( array $scheme ): string => strtolower( $scheme[0] ), $link );
	}

	/**
	 * Decode entities in text, so an encoded address reads as one.
	 *
	 * @param string $text Text or HTML.
	 * @return string
	 */
	private static function decode( string $text ): string {
		return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Every string in a nested array.
	 *
	 * @param array<mixed> $values Values.
	 * @return list<string>
	 */
	private static function string_values( array $values ): array {
		$strings = array();
		foreach ( $values as $value ) {
			if ( is_string( $value ) ) {
				$strings[] = $value;
			} elseif ( is_array( $value ) ) {
				$strings = array_merge( $strings, self::string_values( $value ) );
			}
		}
		return $strings;
	}

	/**
	 * Normalize an address for comparison.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	private static function normalize_url( string $url ): string {
		$url = (string) preg_replace( '#^https?://#i', '', $url );
		// Only the host is case-insensitive; a path or query keeps its case.
		$parts = preg_split( '#(?=[/?\#])#', $url, 2 );
		$host  = strtolower( is_array( $parts ) ? $parts[0] : $url );
		$path  = is_array( $parts ) ? ( $parts[1] ?? '' ) : '';
		return rtrim( (string) preg_replace( '#^www\.#', '', $host ) . $path, '/.,;:!?' );
	}

	/**
	 * Split a matched address from punctuation that ends the text around
	 * it, such as the period or parenthesis after `(see https://example.com/x).`.
	 * Parentheses that balance stay part of the address.
	 *
	 * @param string $url Matched address.
	 * @return array{string, string} Address and the trailing text that is not part of it.
	 */
	private static function split_url( string $url ): array {
		$rest = '';
		while ( '' !== $url ) {
			$last = substr( $url, -1 );
			if ( ! str_contains( '.,;:!?', $last ) && ( ')' !== $last || substr_count( $url, '(' ) >= substr_count( $url, ')' ) ) ) {
				break;
			}
			$rest = $last . $rest;
			$url  = substr( $url, 0, -1 );
		}
		return array( $url, $rest );
	}
}
