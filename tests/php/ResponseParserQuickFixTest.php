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
		$this->assertSame(
			array(
				'find'    => 'utilize',
				'replace' => 'use',
			),
			$feedback[0]['quick_fix']
		);
		$this->assertArrayNotHasKey( 'quick_fix', $feedback[1] );
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
