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
	 * A web address: `http(s)://` or `www.` up to whitespace, quotes,
	 * brackets or a backslash.
	 */
	private const URL_PATTERN = '#\b(?:https?://|www\.)[^\s"\'<>()\[\]\\\\]+#i';

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
		$handles = array( 'fresh eyes', strtolower( __( 'Fresh Eyes', 'fresh-eyes' ) ) );

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
	 * quotes or pastes as code does not trigger an answer. An email address
	 * or a longer name that starts with a handle is not a mention.
	 *
	 * @param string $content Note content, as stored.
	 * @return bool
	 */
	public static function mentions_fresh_eyes( string $content ): bool {
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
		$content = mb_substr( $content, 0, self::MAX_SCAN_LENGTH * 2 );

		// Inner quotes go first, so each pass removes one level of nesting.
		for ( $pass = 0; $pass < 5; $pass++ ) {
			$stripped = preg_replace( '#<(' . self::QUOTED_TAGS . ')\b[^>]*>(?:(?!<(?:' . self::QUOTED_TAGS . ')\b).)*?</\1\s*>#is', ' ', $content );
			if ( null === $stripped || $stripped === $content ) {
				break;
			}
			$content = $stripped;
		}
		// An unclosed quote runs to the end of the note.
		$content = (string) preg_replace( '#<(?:' . self::QUOTED_TAGS . ')\b.*$#is', ' ', $content );

		$text = html_entity_decode( wp_strip_all_tags( $content ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', $text ) ), 0, self::MAX_SCAN_LENGTH );
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
	 * @return string Selected text, or '' for a note on the whole block.
	 */
	public static function get_selected_text( string $block_html, int $note_id ): string {
		if ( $note_id <= 0 || ! str_contains( $block_html, 'wp-note' ) ) {
			return '';
		}

		$count = preg_match_all( '#<mark\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>(.*?)</mark\s*>#is', $block_html, $matches, PREG_SET_ORDER );
		if ( ! $count ) {
			return '';
		}

		$parts = array();
		foreach ( $matches as $match ) {
			$attributes = $match[1];
			if ( 1 !== preg_match( '#\bclass\s*=\s*(["\'])(?:[^"\']*\s)?wp-note(?:\s[^"\']*)?\1#i', $attributes ) ) {
				continue;
			}
			if ( 1 !== preg_match( '#\bdata-id\s*=\s*(["\'])\s*(\d+)\s*\1#i', $attributes, $id ) || (int) $id[2] !== $note_id ) {
				continue;
			}
			$parts[] = $match[2];
		}

		$text = html_entity_decode( wp_strip_all_tags( implode( ' ', $parts ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		return mb_strlen( $text ) > self::MAX_SELECTION_LENGTH ? mb_substr( $text, 0, self::MAX_SELECTION_LENGTH ) . '...' : $text;
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
			$urls[] = self::normalize_url( $url );
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
				if ( in_array( self::normalize_url( $found[0] ), $known, true ) ) {
					return $found[0];
				}
				return __( '[link removed]', 'fresh-eyes' );
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
		$encoded = wp_json_encode( $edit, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$urls    = self::find_urls( is_string( $encoded ) ? $encoded : '' );

		return array() !== array_diff( $urls, self::find_urls( $sources ) );
	}

	/**
	 * Normalize an address for comparison.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	private static function normalize_url( string $url ): string {
		$url = (string) preg_replace( '#^https?://#i', '', strtolower( $url ) );
		return rtrim( (string) preg_replace( '#^www\.#', '', $url ), '/.,;:!?' );
	}
}
