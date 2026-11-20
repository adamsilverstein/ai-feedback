<?php
/**
 * Tests for quick fix parsing in Response_Parser.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Response_Parser;
use AI_Feedback\Notes_Manager;

/**
 * Test cases for quick fix validation.
 */
class ResponseParserQuickFixTest extends TestCase {

	/**
	 * Parser instance.
	 *
	 * @var Response_Parser
	 */
	private Response_Parser $parser;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->parser = new Response_Parser();
	}

	/**
	 * Data provider for quick fix validation.
	 *
	 * @return array<string, array{0: mixed, 1: array{find: string, replace: string}|null}>
	 */
	public static function quick_fix_provider(): array {
		return array(
			'valid replacement'        => array(
				array(
					'find'    => 'utilize',
					'replace' => 'use',
				),
				array(
					'find'    => 'utilize',
					'replace' => 'use',
				),
			),
			'deletion'                 => array(
				array(
					'find'    => 'very ',
					'replace' => '',
				),
				array(
					'find'    => 'very ',
					'replace' => '',
				),
			),
			'missing replace deletes'  => array(
				array( 'find' => 'very ' ),
				array(
					'find'    => 'very ',
					'replace' => '',
				),
			),
			'rejects markup'           => array(
				array(
					'find'    => '<b>utilize</b>',
					'replace' => '<i>use</i>',
				),
				null,
			),
			'keeps edge whitespace'    => array(
				array(
					'find'    => ' very',
					'replace' => '',
				),
				array(
					'find'    => ' very',
					'replace' => '',
				),
			),
			'whole words only'         => array(
				array(
					'find'    => 'hop',
					'replace' => 'store',
				),
				null,
			),
			'word next to punctuation' => array(
				array(
					'find'    => 'shop',
					'replace' => 'store',
				),
				array(
					'find'    => 'shop',
					'replace' => 'store',
				),
			),
			'not an array'             => array( 'utilize => use', null ),
			'empty find'               => array(
				array(
					'find'    => '  ',
					'replace' => 'x',
				),
				null,
			),
			'no change'                => array(
				array(
					'find'    => 'use',
					'replace' => 'use',
				),
				null,
			),
			'text not in block'        => array(
				array(
					'find'    => 'leverage',
					'replace' => 'use',
				),
				null,
			),
			'ambiguous match'          => array(
				array(
					'find'    => 'the',
					'replace' => 'a',
				),
				null,
			),
			'non-string find'          => array(
				array(
					'find'    => 42,
					'replace' => 'x',
				),
				null,
			),
			'too long'                 => array(
				array(
					'find'    => 'utilize',
					'replace' => str_repeat( 'x', 201 ),
				),
				null,
			),
		);
	}

	/**
	 * Quick fixes are validated against the block text.
	 *
	 * @param mixed                                     $quick_fix Quick fix input.
	 * @param array{find: string, replace: string}|null $expected  Expected result.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'quick_fix_provider' )]
	public function test_validate_quick_fix( $quick_fix, ?array $expected ): void {
		$content = 'We utilize the very best tools in the shop.';

		$this->assertSame( $expected, $this->parser->validate_quick_fix( $quick_fix, $content ) );
	}

	/**
	 * Data provider for block text with line breaks and non-breaking spaces,
	 * read as the editor reads it.
	 *
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public static function editor_text_provider(): array {
		return array(
			'find across a line break'      => array( "Revenue grew 40%\nlast year.", '40% last year', false ),
			'find holding the line break'   => array( "Revenue grew 40%\nlast year.", "40%\nlast year", true ),
			'find beside a line break'      => array( "Revenue grew 40%\nlast year.", 'last year', true ),
			'space over non-breaking space' => array( "Revenue grew\u{00A0}40%.", 'grew 40%', true ),
			'non-breaking space in find'    => array( 'Revenue grew 40%.', "grew\u{00A0}40%", true ),
			'space matching twice'          => array( "Revenue grew\u{00A0}40%, then grew 40%.", 'grew 40%', false ),
			'find across a footnote'        => array( "Revenue growth\u{FFFC} was strong.", "growth\u{FFFC} was", false ),
			'find beside a footnote'        => array( "Revenue growth\u{FFFC} was strong.", 'was strong', true ),
		);
	}

	/**
	 * A quick fix is kept only when it matches once in the editor too
	 * (findMatches() in src/utils/quick-fix.js): a space never matches a
	 * line break, and matches a non-breaking space.
	 *
	 * @param string $content Block text.
	 * @param string $find    Text to find.
	 * @param bool   $kept    Whether the fix is kept.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'editor_text_provider' )]
	public function test_quick_fix_matches_editor_text( string $content, string $find, bool $kept ): void {
		$fix = array(
			'find'    => $find,
			'replace' => 'changed',
		);

		$this->assertSame( $kept ? $fix : null, $this->parser->validate_quick_fix( $fix, $content ) );
	}

	/**
	 * A replacement holding U+FFFC would write an object character into the
	 * text, so the fix is dropped.
	 */
	public function test_quick_fix_replacement_with_an_object_is_dropped(): void {
		$fix = array(
			'find'    => 'strong',
			'replace' => "strong\u{FFFC}",
		);

		$this->assertNull( $this->parser->validate_quick_fix( $fix, "Revenue growth\u{FFFC} was strong." ) );
	}

	/**
	 * A valid quick fix survives parsing; a bad one is dropped without losing the item.
	 */
	public function test_parse_feedback_keeps_valid_quick_fix(): void {
		$blocks   = array(
			array(
				'clientId' => 'block-1',
				'name'     => 'core/paragraph',
				'content'  => 'We utilize good tools.',
			),
			array(
				'clientId' => 'block-2',
				'name'     => 'core/paragraph',
				'content'  => 'Another paragraph here.',
			),
		);
		$response = wp_json_encode(
			array(
				'summary'  => 'Fine.',
				'feedback' => array(
					array(
						'block_id'  => 'block-1',
						'category'  => 'tone',
						'severity'  => 'suggestion',
						'title'     => 'Plain word',
						'feedback'  => 'Prefer the simpler word.',
						'quick_fix' => array(
							'find'    => 'utilize',
							'replace' => 'use',
						),
					),
					array(
						'block_id'  => 'block-2',
						'category'  => 'flow',
						'severity'  => 'important',
						'title'     => 'Weak opener',
						'feedback'  => 'Start stronger.',
						'quick_fix' => array(
							'find'    => 'missing text',
							'replace' => 'x',
						),
					),
				),
			)
		);

		$this->assertIsString( $response );
		$result = $this->parser->parse_feedback( $response, $blocks );
		$this->assertIsArray( $result );

		/** @var array<int, array<string, mixed>> $feedback */
		$feedback = $result['feedback'];

		$this->assertCount( 2, $feedback );

		// Feedback is sorted by priority, so look items up by block.
		$by_block = array_column( $feedback, null, 'block_id' );
		$this->assertSame(
			array(
				'find'    => 'utilize',
				'replace' => 'use',
			),
			$by_block['block-1']['quick_fix']
		);
		$this->assertArrayNotHasKey( 'quick_fix', $by_block['block-2'] );
	}

	/**
	 * A quick fix that is not an object is dropped without rejecting the item.
	 */
	public function test_parse_feedback_drops_malformed_quick_fix(): void {
		$blocks   = array(
			array(
				'clientId' => 'block-1',
				'name'     => 'core/paragraph',
				'content'  => 'We utilize good tools.',
			),
		);
		$response = wp_json_encode(
			array(
				'summary'  => 'Fine.',
				'feedback' => array(
					array(
						'block_id'  => 'block-1',
						'category'  => 'tone',
						'severity'  => 'suggestion',
						'title'     => 'Plain word',
						'feedback'  => 'Prefer the simpler word.',
						'quick_fix' => 'utilize -> use',
					),
				),
			)
		);

		$this->assertIsString( $response );
		$result = $this->parser->parse_feedback( $response, $blocks );
		$this->assertIsArray( $result );

		/** @var array<int, array<string, mixed>> $feedback */
		$feedback = $result['feedback'];

		$this->assertCount( 1, $feedback );
		$this->assertArrayNotHasKey( 'quick_fix', $feedback[0] );
	}

	/**
	 * Grouped feedback drops quick fixes, since a fix targets one block.
	 */
	public function test_grouped_feedback_drops_quick_fix(): void {
		$item = array(
			'category'  => 'tone',
			'severity'  => 'suggestion',
			'title'     => 'Plain word',
			'feedback'  => 'Prefer the simpler word.',
			'quick_fix' => array(
				'find'    => 'utilize',
				'replace' => 'use',
			),
		);

		$grouped = $this->parser->group_similar_feedback(
			array(
				$item + array( 'block_id' => 'block-1' ),
				$item + array( 'block_id' => 'block-2' ),
			)
		);

		$this->assertCount( 1, $grouped );
		$this->assertIsArray( $grouped[0] );
		$this->assertArrayNotHasKey( 'quick_fix', $grouped[0] );
	}

	/**
	 * Stored quick fixes decode back to find/replace pairs.
	 */
	public function test_decode_quick_fix(): void {
		$manager = new Notes_Manager();

		$this->assertSame(
			array(
				'find'    => 'a',
				'replace' => 'b',
			),
			$manager->decode_quick_fix( '{"find":"a","replace":"b"}' )
		);
		$this->assertNull( $manager->decode_quick_fix( '' ) );
		$this->assertNull( $manager->decode_quick_fix( 'not json' ) );
		$this->assertNull( $manager->decode_quick_fix( '{"find":"a"}' ) );
	}
}
