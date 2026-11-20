<?php
/**
 * Tests for Editor_Text.
 *
 * @package AI_Feedback
 */

namespace AI_Feedback\Tests;

use AI_Feedback\Editor_Text;
use PHPUnit\Framework\TestCase;

/**
 * Editor_Text reads block HTML as the editor's rich text does. The shared
 * cases in tests/fixtures/editor-text.json run through
 * ReplyServiceTest::test_editor_text_matches_the_editor().
 */
class EditorTextTest extends TestCase {

	/**
	 * The formats the server reads with are the ones the JS tests register
	 * and the e2e suite checks against the editor.
	 */
	public function test_formats_match_the_shared_fixture(): void {
		$fixture = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/editor-text.json' ), true );
		$formats = array();
		foreach ( is_array( $fixture ) && is_array( $fixture['formats'] ?? null ) ? $fixture['formats'] : array() as $format ) {
			if ( is_array( $format ) && is_string( $format['name'] ?? null ) ) {
				$name = $format['name'];
				unset( $format['name'] );
				$formats[ $name ] = $format;
			}
		}

		$this->assertNotEmpty( $formats );
		$this->assertSame( $formats, Editor_Text::FORMATS );
	}

	/**
	 * Block delimiters are dropped, while a comment inside the text is an
	 * object, as in the editor.
	 */
	public function test_drops_block_delimiters_and_keeps_inline_comments(): void {
		$this->assertSame(
			"a\u{FFFC}b",
			Editor_Text::read( "<!-- wp:paragraph {\"className\":\"x\"} -->\n<p>a<!-- note -->b</p>\n<!-- /wp:paragraph -->" )
		);
	}

	/**
	 * When PCRE gives up on the HTML, the rest is read as plain text, not
	 * dropped.
	 */
	public function test_keeps_the_text_when_the_tokenizer_fails(): void {
		$jit       = ini_get( 'pcre.jit' );
		$backtrack = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.jit', '0' );
		ini_set( 'pcre.backtrack_limit', '1' );
		try {
			$text = Editor_Text::read( '<p>Revenue <strong>grew</strong> 40% last year.</p>' );
		} finally {
			ini_set( 'pcre.jit', (string) $jit );
			ini_set( 'pcre.backtrack_limit', (string) $backtrack );
		}

		$this->assertSame( 'Revenue grew 40% last year.', $text );
	}
}
