<?php
/**
 * Editor text
 *
 * Reads block HTML as the editor's rich text does.
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads block HTML into the text `create( { html } ).text` gives in the editor.
 *
 * An edit's `find` is matched against this text on the server and against
 * rich text in the editor, so the two must agree. Rich text reads some
 * markup as one object character (U+FFFC) instead of its text: a format
 * the user can't edit, like a footnote or a formula, a comment, a script,
 * and an empty element its format keeps, like an anchor with an id. That
 * depends on the formats the editor registers, listed in FORMATS. Only
 * core's formats are listed: a plugin that registers its own non-editable
 * format can still read differently in the editor.
 */
class Editor_Text {

	/**
	 * The character rich text reads an object as.
	 */
	public const OBJECT = "\u{FFFC}";

	/**
	 * The rich text format types core registers in the editor, in order.
	 *
	 * Each has the tag and class it handles (null for a bare element, `*`
	 * for any tag), the HTML attributes it keeps, whether its text can be
	 * edited, and whether rich text reads it as plain text. Mirrors the
	 * `formats` in tests/fixtures/editor-text.json, which the e2e suite
	 * checks against the editor.
	 *
	 * @var array<string, array{tagName: string, className: string|null, attributes: list<string>, contentEditable: bool, asText: bool}>
	 */
	public const FORMATS = array(
		'core/note'               => array(
			'tagName'         => 'mark',
			'className'       => 'wp-note',
			'attributes'      => array( 'data-id' ),
			'contentEditable' => true,
			'asText'          => false,
		),
		'core/bold'               => array(
			'tagName'         => 'strong',
			'className'       => null,
			'attributes'      => array(),
			'contentEditable' => true,
			'asText'          => false,
		),
		'core/code'               => array(
			'tagName'         => 'code',
			'className'       => null,
			'attributes'      => array(),
			'contentEditable' => true,
			'asText'          => false,
		),
		'core/image'              => array(
			'tagName'         => 'img',
			'className'       => null,
			'attributes'      => array( 'class', 'style', 'src', 'alt' ),
			'contentEditable' => true,
			'asText'          => false,
		),
		'core/italic'             => array(
			'tagName'         => 'em',
			'className'       => null,
			'attributes'      => array(),
			'contentEditable' => true,
			'asText'          => false,
		),
		'core/link'               => array(
			'tagName'         => 'a',
			'className'       => null,
			'attributes'      => array( 'href', 'data-type', 'data-id', 'id', 'target', 'rel', 'class' ),
			'contentEditable' => true,
			'asText'          => false,
		),
		'core/strikethrough'      => array(
			'tagName'         => 's',
			'className'       => null,
			'attributes'      => array(),
			'contentEditable' => true,
			'asText'          => false,
		),
		'core/underline'          => array(
			'tagName'         => 'span',
			'className'       => null,
			'attributes'      => array( 'style' ),
			'contentEditable' => true,
			'asText'          => false,
		),
		'core/text-color'         => array(
			'tagName'         => 'mark',
			'className'       => 'has-inline-color',
			'attributes'      => array( 'style', 'class' ),
			'contentEditable' => true,
			'asText'          => false,
		),
		'core/subscript'          => array(
			'tagName'         => 'sub',
			'className'       => null,
			'attributes'      => array(),
			'contentEditable' => true,
			'asText'          => false,
		),
		'core/superscript'        => array(
			'tagName'         => 'sup',
			'className'       => null,
			'attributes'      => array(),
			'contentEditable' => true,
			'asText'          => false,
		),
		'core/keyboard'           => array(
			'tagName'         => 'kbd',
			'className'       => null,
			'attributes'      => array(),
			'contentEditable' => true,
			'asText'          => false,
		),
		'core/unknown'            => array(
			'tagName'         => '*',
			'className'       => null,
			'attributes'      => array(),
			'contentEditable' => true,
			'asText'          => false,
		),
		'core/language'           => array(
			'tagName'         => 'bdo',
			'className'       => null,
			'attributes'      => array( 'lang', 'dir' ),
			'contentEditable' => true,
			'asText'          => false,
		),
		'core/math'               => array(
			'tagName'         => 'math',
			'className'       => null,
			'attributes'      => array( 'data-latex' ),
			'contentEditable' => false,
			'asText'          => false,
		),
		'core/non-breaking-space' => array(
			'tagName'         => 'span',
			'className'       => 'non-breaking-space',
			'attributes'      => array(),
			'contentEditable' => true,
			'asText'          => true,
		),
		'core/footnote'           => array(
			'tagName'         => 'sup',
			'className'       => 'fn',
			'attributes'      => array( 'data-fn' ),
			'contentEditable' => false,
			'asText'          => false,
		),
	);

	/**
	 * Elements whose closing tag ends a line of text, so their text doesn't
	 * run together in the prompt. The editor never matches across them.
	 */
	private const LINE_ENDS = array( 'p', 'li', 'td', 'th', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'figcaption', 'blockquote', 'pre' );

	/**
	 * Elements whose content is text, not markup.
	 */
	private const RAW_TEXT = array( 'script', 'style', 'textarea', 'title', 'iframe', 'noembed', 'noframes', 'xmp' );

	/**
	 * Elements with no closing tag.
	 */
	private const VOID = array( 'area', 'base', 'basefont', 'bgsound', 'col', 'embed', 'frame', 'hr', 'img', 'input', 'keygen', 'link', 'meta', 'param', 'source', 'track', 'wbr' );

	/**
	 * Largest block HTML read, in bytes. A larger block reads as '', so no
	 * edit is matched against it.
	 */
	public const MAX_BYTES = 500000;

	/**
	 * Most elements open at once. A block nested deeper reads as '', so no
	 * edit is matched against it.
	 */
	public const MAX_DEPTH = 256;

	/**
	 * One token of HTML: a comment, markup a browser reads as a comment, a
	 * tag, or text. A `<` that starts none of them is text.
	 */
	private const TOKEN = '~\G(?:<!--.*?(?:-->|\z)|<[!?][^>]*>?|</(?![a-z>])[^>]*>?|<(?<close>/)?(?<name>[a-z][^\s/>]*)(?<attributes>(?:[^>"\']|"[^"]*"|\'[^\']*\')*)>|(?<text>[^<]+|<))~is';

	/**
	 * Read block HTML's text as the editor's rich text does.
	 *
	 * A line break is "\n", whitespace is kept as written, entities are
	 * decoded and an object is U+FFFC. Block delimiters are dropped. Table
	 * cells and list items end with a space. The outer ends are trimmed.
	 *
	 * Block HTML is the editor's own output, so a small tokenizer is enough.
	 * The HTML API would read it the same way, but the unit tests run
	 * without WordPress.
	 *
	 * Fails closed: a block over MAX_BYTES or MAX_DEPTH, or one the
	 * tokenizer can't read within PCRE's limits, reads as '', so an edit is
	 * never matched against text that might differ from the editor's.
	 *
	 * @param string $html Block HTML.
	 * @return string Text, or '' when the block can't be read.
	 */
	public static function read( string $html ): string {
		if ( strlen( $html ) > self::MAX_BYTES ) {
			Logger::debug( 'Editor text: block HTML too large to read' );
			return '';
		}

		$html = str_replace( array( "\r\n", "\r" ), "\n", $html );
		// Block delimiters mark blocks, not text. Their attributes never
		// hold `--`, so the first `-->` ends one.
		if ( str_contains( $html, '<!--' ) ) {
			$html = preg_replace( '#<!--\s+/?wp:.*?-->#s', '', $html );
		}
		if ( null === $html ) {
			Logger::debug( 'Editor text: could not read block HTML' );
			return '';
		}

		$stack  = array( self::frame( '', null, false ) );
		$open   = array();
		$frames = array();
		$offset = 0;
		$length = strlen( $html );

		while ( $offset < $length ) {
			if ( 1 !== preg_match( self::TOKEN, $html, $token, 0, $offset ) ) {
				// The pattern matches any input, so this is a PCRE limit.
				Logger::debug( 'Editor text: could not read block HTML' );
				return '';
			}
			$offset += strlen( $token[0] );

			if ( isset( $token['text'] ) ) {
				self::append( $stack, self::decode( $token['text'] ) );
				continue;
			}

			$tag = strtolower( $token['name'] ?? '' );
			if ( '' === $tag ) {
				// A comment, or markup a browser reads as one.
				self::append( $stack, self::OBJECT );
				continue;
			}

			if ( 'br' === $tag ) {
				// A browser reads `</br>` as `<br>` too.
				self::append( $stack, "\n" );
				continue;
			}

			if ( '' !== ( $token['close'] ?? '' ) ) {
				self::close_to( $stack, $open, $tag );
				continue;
			}

			// Blocks repeat the same tags, so read each one once.
			$key   = $tag . ' ' . ( $token['attributes'] ?? '' );
			$frame = $frames[ $key ] ?? self::open( $tag, $token['attributes'] ?? '' );
			if ( null === $frame ) {
				Logger::debug( 'Editor text: could not read block HTML' );
				return '';
			}
			if ( count( $frames ) < 100 ) {
				$frames[ $key ] = $frame;
			}
			if ( in_array( $tag, self::RAW_TEXT, true ) ) {
				// Text up to the closing tag, read as text, not markup.
				$found = preg_match( '#</' . $tag . '[\s/>]#i', $html, $match, PREG_OFFSET_CAPTURE, $offset );
				if ( false === $found ) {
					Logger::debug( 'Editor text: could not read block HTML' );
					return '';
				}
				$closer = 1 === $found ? (int) $match[0][1] : $length;
				$raw    = substr( $html, $offset, $closer - $offset );
				$end    = strpos( $html, '>', $closer );
				$offset = false === $end ? $length : $end + 1;
				if ( 'script' === $tag ) {
					self::append( $stack, self::OBJECT );
					continue;
				}
				$frame['text'] = self::decode( $raw, in_array( $tag, array( 'textarea', 'title' ), true ) );
				self::close( $stack, $frame );
				continue;
			}

			if ( in_array( $tag, self::VOID, true ) ) {
				self::close( $stack, $frame );
				continue;
			}

			if ( count( $stack ) > self::MAX_DEPTH ) {
				Logger::debug( 'Editor text: block HTML nested too deeply to read' );
				return '';
			}
			$stack[]      = $frame;
			$open[ $tag ] = ( $open[ $tag ] ?? 0 ) + 1;
		}

		for ( $depth = count( $stack ); $depth > 1; --$depth ) {
			$frame = array_pop( $stack );
			if ( null !== $frame ) {
				self::close( $stack, $frame );
			}
		}

		return trim( $stack[0]['text'] );
	}

	/**
	 * Find the format type rich text reads an element with.
	 *
	 * A class names a format first, then the tag does, then `core/unknown`
	 * takes any other element.
	 *
	 * @param string                $tag        Lower case tag name.
	 * @param array<string, string> $attributes Attribute values by name.
	 * @return array{0: array{tagName: string, className: string|null, attributes: list<string>, contentEditable: bool, asText: bool}|null, 1: array<string, string>} Format type, or null, and the attributes it reads.
	 */
	private static function find_format( string $tag, array $attributes ): array {
		$class = $attributes['class'] ?? '';
		if ( '' !== $class ) {
			foreach ( self::FORMATS as $format ) {
				if ( null === $format['className'] ) {
					continue;
				}
				$at = strpos( " {$class} ", " {$format['className']} " );
				if ( false !== $at ) {
					// Rich text keeps the other classes.
					$class = trim( substr_replace( " {$class} ", ' ', $at, strlen( $format['className'] ) + 2 ) );
					if ( '' === $class ) {
						unset( $attributes['class'] );
					} else {
						$attributes['class'] = $class;
					}
					return array( $format, $attributes );
				}
			}
		}

		$any = null;
		foreach ( self::FORMATS as $format ) {
			if ( null !== $format['className'] ) {
				continue;
			}
			if ( $tag === $format['tagName'] ) {
				return array( $format, $attributes );
			}
			if ( null === $any && '*' === $format['tagName'] ) {
				$any = $format;
			}
		}
		return array( $any, $attributes );
	}

	/**
	 * Start reading an element.
	 *
	 * @param string $tag        Lower case tag name.
	 * @param string $attributes The tag's attributes as written.
	 * @return array{tag: string, object: bool, kept: bool|null, text: string}|null Frame, or null when PCRE can't read the attributes.
	 */
	private static function open( string $tag, string $attributes ): ?array {
		$values = array();
		if ( false === preg_match_all( '~([^\s"\'>/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?~', $attributes, $matches, PREG_SET_ORDER ) ) {
			return null;
		}
		foreach ( $matches as $match ) {
			$name = strtolower( $match[1] );
			// The first of a repeated attribute wins.
			if ( ! isset( $values[ $name ] ) ) {
				$values[ $name ] = self::decode( ( $match[2] ?? '' ) . ( $match[3] ?? '' ) . ( $match[4] ?? '' ) );
			}
		}

		list( $format, $values ) = self::find_format( $tag, $values );
		if ( null === $format || $format['asText'] ) {
			return self::frame( $tag, null, false );
		}

		// An empty element is kept as an object when it has an attribute
		// its format reads, like an anchor's id.
		$kept = array() !== array_intersect( $format['attributes'], array_keys( $values ) );
		return self::frame( $tag, $kept, ! $format['contentEditable'] );
	}

	/**
	 * Decode text as a browser does.
	 *
	 * @param string $text   Text from the HTML.
	 * @param bool   $decode Whether entities are decoded, false for text in a style or the like.
	 * @return string Text without the characters rich text reserves, U+FFFC and U+FEFF.
	 */
	private static function decode( string $text, bool $decode = true ): string {
		if ( $decode ) {
			$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return str_replace( array( self::OBJECT, "\u{FEFF}" ), '', $text );
	}

	/**
	 * A frame for an element being read.
	 *
	 * @param string    $tag       Lower case tag name, '' for the root.
	 * @param bool|null $kept      Whether the element is kept when empty, or null when it has no format.
	 * @param bool      $is_object Whether the element is read as one object.
	 * @return array{tag: string, object: bool, kept: bool|null, text: string} Frame.
	 */
	private static function frame( string $tag, ?bool $kept, bool $is_object ): array {
		return array(
			'tag'    => $tag,
			'object' => $is_object,
			'kept'   => $kept,
			'text'   => '',
		);
	}

	/**
	 * Close open elements up to the latest one with a tag name.
	 *
	 * A closing tag with no open element to match is ignored, as a browser
	 * does.
	 *
	 * @param list<array{tag: string, object: bool, kept: bool|null, text: string}> $stack Open elements, the root first.
	 * @param array<string, int>                                                    $open  How many elements of each tag are open.
	 * @param string                                                                $tag   Lower case tag name.
	 */
	private static function close_to( array &$stack, array &$open, string $tag ): void {
		if ( empty( $open[ $tag ] ) ) {
			return;
		}
		for ( $depth = count( $stack ); $depth > 1; --$depth ) {
			$frame = array_pop( $stack );
			if ( null === $frame ) {
				return;
			}
			--$open[ $frame['tag'] ];
			self::close( $stack, $frame );
			if ( $frame['tag'] === $tag ) {
				return;
			}
		}
	}

	/**
	 * Add a closed element's text to the innermost open element.
	 *
	 * @param list<array{tag: string, object: bool, kept: bool|null, text: string}> $stack Open elements, the root first.
	 * @param array{tag: string, object: bool, kept: bool|null, text: string}       $frame Closed element.
	 */
	private static function close( array &$stack, array $frame ): void {
		$text = $frame['text'];
		if ( $frame['object'] || ( '' === $text && true === $frame['kept'] ) ) {
			$text = self::OBJECT;
		}
		if ( in_array( $frame['tag'], self::LINE_ENDS, true ) ) {
			$text .= ' ';
		}
		self::append( $stack, $text );
	}

	/**
	 * Add text to the innermost open element.
	 *
	 * @param list<array{tag: string, object: bool, kept: bool|null, text: string}> $stack Open elements, the root first.
	 * @param string                                                                $text  Text.
	 */
	private static function append( array &$stack, string $text ): void {
		$frame = array_pop( $stack );
		if ( null !== $frame ) {
			$frame['text'] .= $text;
			$stack[]        = $frame;
		}
	}
}
