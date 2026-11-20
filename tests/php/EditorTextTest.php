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
	 * When PCRE gives up on the HTML, the block reads as '', so no edit is
	 * matched against text that might differ from the editor's.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_fails_closed_when_the_tokenizer_fails(): void {
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

		$this->assertSame( '', $text );
	}

	/**
	 * When PCRE gives up partway through the tokens, the block reads as '',
	 * not as the text read so far. With no comment in the HTML, the block
	 * delimiter strip doesn't run, so this reaches the tokenizer: at this
	 * limit it reads "Revenue grew " and then fails on the link. Runs in its
	 * own process, since PCRE caches a pattern compiled with JIT.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_fails_closed_when_the_tokenizer_fails_partway(): void {
		$jit       = ini_get( 'pcre.jit' );
		$backtrack = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.jit', '0' );
		ini_set( 'pcre.backtrack_limit', '10' );
		try {
			$text  = Editor_Text::read( 'Revenue grew <a href="https://example.com/annual-report-for-the-year-2025-in-full" title="The annual report for 2025">40%</a> last year.' );
			$token = preg_match( '~\G[^<]+~', 'Revenue grew ' );
		} finally {
			ini_set( 'pcre.jit', (string) $jit );
			ini_set( 'pcre.backtrack_limit', (string) $backtrack );
		}

		// The limit still lets a simple token through.
		$this->assertSame( 1, $token );
		$this->assertSame( '', $text );
	}

	/**
	 * Data provider for blocks too large or too deep to read.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function unreadable_block_provider(): array {
		return array(
			'over the size limit'           => array( '<p>' . str_repeat( 'a', Editor_Text::MAX_BYTES ) . '</p>' ),
			'nested past the depth limit'   => array( str_repeat( '<span>', 20000 ) . 'a' . str_repeat( '</span>', 20000 ) ),
			'unclosed past the depth limit' => array( str_repeat( '<em>a', Editor_Text::MAX_DEPTH + 1 ) ),
		);
	}

	/**
	 * A block too large or nested too deeply reads as '' and returns
	 * quickly.
	 *
	 * @dataProvider unreadable_block_provider
	 *
	 * @param string $html Block HTML.
	 */
	public function test_fails_closed_on_an_unreadable_block( string $html ): void {
		$start = microtime( true );
		$text  = Editor_Text::read( $html );

		$this->assertSame( '', $text );
		$this->assertLessThan( 2.0, microtime( true ) - $start );
	}

	/**
	 * A large block within the limits reads in linear time.
	 */
	public function test_reads_a_large_block_quickly(): void {
		$html  = str_repeat( '<strong>a</strong></x>', 4000 ) . str_repeat( '<em>', Editor_Text::MAX_DEPTH - 1 ) . 'b';
		$start = microtime( true );
		$text  = Editor_Text::read( $html );

		$this->assertSame( str_repeat( 'a', 4000 ) . 'b', $text );
		$this->assertLessThan( 2.0, microtime( true ) - $start );
	}
}
