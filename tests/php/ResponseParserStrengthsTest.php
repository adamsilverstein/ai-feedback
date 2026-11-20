<?php
/**
 * Tests for the strengths a review returns.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Prompt_Builder;
use AI_Feedback\Response_Parser;

/**
 * Strengths parsing and prompt tests.
 */
class ResponseParserStrengthsTest extends TestCase {

	/**
	 * Parser.
	 *
	 * @var Response_Parser
	 */
	private Response_Parser $parser;

	/**
	 * Blocks in the review.
	 *
	 * @var list<array<string, string>>
	 */
	private array $blocks = array(
		array(
			'clientId' => 'block-1',
			'name'     => 'core/paragraph',
			'content'  => 'The opening example makes the problem concrete.',
			'anchor'   => 'abc123',
		),
		array(
			'clientId' => 'block-2',
			'name'     => 'core/list',
			'content'  => 'Step one. Step two.',
		),
		array(
			'clientId' => 'block-3',
			'name'     => 'core/paragraph',
			'content'  => 'A closing thought.',
		),
		array(
			'clientId' => 'block-4',
			'name'     => 'core/paragraph',
			'content'  => 'One more paragraph.',
		),
	);

	/**
	 * Set up the parser.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->parser = new Response_Parser();
		unset( $GLOBALS['test_filter_results'] );
	}

	/**
	 * Clear filter overrides.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['test_filter_results'] );
		parent::tearDown();
	}

	/**
	 * Parse a response holding the given strengths and one feedback item.
	 *
	 * @param  mixed $strengths Raw strengths.
	 * @return array{feedback: array<mixed>, strengths: list<array<mixed>>}
	 */
	private function parse( mixed $strengths ): array {
		$response = (string) wp_json_encode(
			array(
				'summary'   => 'Good draft.',
				'feedback'  => array(
					array(
						'block_id' => 'block-3',
						'category' => 'content',
						'severity' => 'suggestion',
						'title'    => 'Expand',
						'feedback' => 'Say more here.',
					),
				),
				'strengths' => $strengths,
			)
		);

		$result = $this->parser->parse_feedback( $response, $this->blocks );
		$this->assertIsArray( $result );
		$this->assertIsArray( $result['feedback'] );
		$this->assertIsArray( $result['strengths'] );

		return array(
			'feedback'  => $result['feedback'],
			'strengths' => array_values( array_filter( $result['strengths'], 'is_array' ) ),
		);
	}

	/**
	 * Valid strengths are kept with their block details.
	 */
	public function test_keeps_valid_strengths_with_block_details(): void {
		$result = $this->parse(
			array(
				array(
					'block_id' => 'block-1',
					'text'     => 'The opening example makes the problem concrete.',
				),
				array(
					'block_id' => 'block-2',
					'text'     => 'Clear steps.',
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'block_id'     => 'block-1',
					'block_name'   => 'core/paragraph',
					'block_index'  => 0,
					'block_anchor' => 'abc123',
					'text'         => 'The opening example makes the problem concrete.',
				),
				array(
					'block_id'     => 'block-2',
					'block_name'   => 'core/list',
					'block_index'  => 1,
					'block_anchor' => '',
					'text'         => 'Clear steps.',
				),
			),
			$result['strengths']
		);
	}

	/**
	 * Strengths are separate from feedback and never change its count.
	 */
	public function test_strengths_do_not_count_as_feedback(): void {
		$result = $this->parse(
			array(
				array(
					'block_id' => 'block-1',
					'text'     => 'Strong opening.',
				),
			)
		);

		$this->assertCount( 1, $result['feedback'] );
		$this->assertSame( 1, $this->parser->get_feedback_summary( $result['feedback'] )['total_notes'] );
	}

	/**
	 * A response without strengths, as from older prompts, gets an empty list.
	 */
	public function test_missing_strengths_are_an_empty_list(): void {
		$response = (string) wp_json_encode(
			array(
				'summary'  => 'Fine.',
				'feedback' => array(),
			)
		);

		$result = $this->parser->parse_feedback( $response, $this->blocks );

		$this->assertIsArray( $result );
		$this->assertSame( array(), $result['strengths'] );
	}

	/**
	 * Malformed strengths are dropped without failing the review.
	 *
	 * @dataProvider data_invalid_strengths
	 *
	 * @param mixed $strengths Raw strengths.
	 */
	public function test_drops_invalid_strengths( mixed $strengths ): void {
		$result = $this->parse( $strengths );

		$this->assertSame( array(), $result['strengths'] );
		$this->assertCount( 1, $result['feedback'] );
	}

	/**
	 * Invalid strengths values.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function data_invalid_strengths(): array {
		return array(
			'not a list'        => array( 'Great job!' ),
			'string items'      => array( array( 'Great job!' ) ),
			'unknown block'     => array(
				array(
					array(
						'block_id' => 'nope',
						'text'     => 'Nice.',
					),
				),
			),
			'missing block'     => array( array( array( 'text' => 'Nice.' ) ) ),
			'missing text'      => array( array( array( 'block_id' => 'block-1' ) ) ),
			'non-string text'   => array(
				array(
					array(
						'block_id' => 'block-1',
						'text'     => array( 'Nice.' ),
					),
				),
			),
			'whitespace text'   => array(
				array(
					array(
						'block_id' => 'block-1',
						'text'     => '   ',
					),
				),
			),
			'non-string block'  => array(
				array(
					array(
						'block_id' => 1,
						'text'     => 'Nice.',
					),
				),
			),
		);
	}

	/**
	 * At most three strengths are kept, one per block.
	 */
	public function test_caps_strengths_and_keeps_one_per_block(): void {
		$result = $this->parse(
			array(
				array(
					'block_id' => 'block-1',
					'text'     => 'First.',
				),
				array(
					'block_id' => 'block-1',
					'text'     => 'Same block again.',
				),
				array(
					'block_id' => 'block-2',
					'text'     => 'Second.',
				),
				array(
					'block_id' => 'block-3',
					'text'     => 'Third.',
				),
				array(
					'block_id' => 'block-4',
					'text'     => 'Fourth.',
				),
			)
		);

		$this->assertSame( array( 'First.', 'Second.', 'Third.' ), array_column( $result['strengths'], 'text' ) );
	}

	/**
	 * Long strengths are cut to the maximum length.
	 */
	public function test_truncates_long_strengths(): void {
		$result = $this->parse(
			array(
				array(
					'block_id' => 'block-1',
					'text'     => str_repeat( 'é', Response_Parser::MAX_STRENGTH_LENGTH + 40 ),
				),
			)
		);

		$text = $result['strengths'][0]['text'];
		$this->assertIsString( $text );
		$this->assertSame( Response_Parser::MAX_STRENGTH_LENGTH, mb_strlen( $text ) );
		$this->assertStringEndsWith( '…', $text );
	}

	/**
	 * The filter can lower the cap or turn strengths off, but not raise it.
	 */
	public function test_filter_limits_strengths(): void {
		$strengths = array(
			array(
				'block_id' => 'block-1',
				'text'     => 'First.',
			),
			array(
				'block_id' => 'block-2',
				'text'     => 'Second.',
			),
		);

		$GLOBALS['test_filter_results'] = array( 'ai_feedback_max_strengths' => 1 );
		$this->assertCount( 1, $this->parse( $strengths )['strengths'] );

		$GLOBALS['test_filter_results'] = array( 'ai_feedback_max_strengths' => 0 );
		$this->assertSame( array(), $this->parse( $strengths )['strengths'] );

		$GLOBALS['test_filter_results'] = array( 'ai_feedback_max_strengths' => 50 );
		$this->assertSame( 3, Response_Parser::get_max_strengths() );

		$GLOBALS['test_filter_results'] = array( 'ai_feedback_max_strengths' => -2 );
		$this->assertSame( 0, Response_Parser::get_max_strengths() );
	}

	/**
	 * Malformed strengths don't fail strict schema validation either.
	 */
	public function test_schema_mode_ignores_malformed_strengths(): void {
		$response = (string) wp_json_encode(
			array(
				'summary'   => 'Fine.',
				'feedback'  => array(),
				'strengths' => 'not a list',
			)
		);

		$result = $this->parser->parse_feedback( $response, $this->blocks, true );

		$this->assertIsArray( $result );
		$this->assertSame( array(), $result['strengths'] );
	}

	/**
	 * The review prompt asks for strengths only while they are turned on.
	 */
	public function test_review_prompt_asks_for_strengths(): void {
		$builder = new Prompt_Builder();

		$prompt = $builder->build_review_prompt( $this->blocks, array( 'post_title' => 'Draft' ) );
		$this->assertStringContainsString( "STRENGTHS:\n", $prompt );
		$this->assertStringContainsString( 'up to 3 things the document already does well', $prompt );
		$this->assertStringContainsString( '"strengths"', $prompt );

		$GLOBALS['test_filter_results'] = array( 'ai_feedback_max_strengths' => 0 );
		$prompt                         = $builder->build_review_prompt( $this->blocks, array( 'post_title' => 'Draft' ) );
		$this->assertStringNotContainsString( 'STRENGTHS', $prompt );
		$this->assertStringNotContainsString( '"strengths"', $prompt );
	}
}
