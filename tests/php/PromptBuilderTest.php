<?php
/**
 * Tests for Prompt_Builder block-type specific instructions.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Prompt_Builder;
use ReflectionMethod;

/**
 * Test cases for Prompt_Builder block-type methods.
 */
class PromptBuilderTest extends TestCase {

	/**
	 * Prompt builder instance.
	 *
	 * @var Prompt_Builder
	 */
	private Prompt_Builder $builder;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->builder = new Prompt_Builder();
	}

	/**
	 * Helper to invoke a private method via reflection.
	 *
	 * @param  string $method Method name.
	 * @param  array  $args   Method arguments.
	 * @return mixed Method return value.
	 */
	private function invoke_private( string $method, array $args = array() ) {
		$ref = new ReflectionMethod( Prompt_Builder::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->builder, $args );
	}

	/**
	 * Test hint for core/heading block type.
	 */
	public function test_hint_heading(): void {
		$hint = $this->invoke_private( 'get_block_type_hint', array( 'core/heading' ) );
		$this->assertSame( 'review for hierarchy and SEO', $hint );
	}

	/**
	 * Test hint for core/paragraph block type.
	 */
	public function test_hint_paragraph(): void {
		$hint = $this->invoke_private( 'get_block_type_hint', array( 'core/paragraph' ) );
		$this->assertSame( 'review for readability and clarity', $hint );
	}

	/**
	 * Test hint for core/list block type.
	 */
	public function test_hint_list(): void {
		$hint = $this->invoke_private( 'get_block_type_hint', array( 'core/list' ) );
		$this->assertSame( 'review for parallel structure', $hint );
	}

	/**
	 * Test hint for core/quote block type.
	 */
	public function test_hint_quote(): void {
		$hint = $this->invoke_private( 'get_block_type_hint', array( 'core/quote' ) );
		$this->assertSame( 'verify attribution', $hint );
	}

	/**
	 * Test hint for core/image block type.
	 */
	public function test_hint_image(): void {
		$hint = $this->invoke_private( 'get_block_type_hint', array( 'core/image' ) );
		$this->assertSame( 'evaluate alt text quality', $hint );
	}

	/**
	 * Test hint for core/table block type.
	 */
	public function test_hint_table(): void {
		$hint = $this->invoke_private( 'get_block_type_hint', array( 'core/table' ) );
		$this->assertSame( 'review organization and headers', $hint );
	}

	/**
	 * Test hint for core/code block type.
	 */
	public function test_hint_code(): void {
		$hint = $this->invoke_private( 'get_block_type_hint', array( 'core/code' ) );
		$this->assertSame( 'check for context and explanation', $hint );
	}

	/**
	 * Test hint returns empty string for unknown block type.
	 */
	public function test_hint_unknown_block_type(): void {
		$hint = $this->invoke_private( 'get_block_type_hint', array( 'core/buttons' ) );
		$this->assertSame( '', $hint );
	}

	/**
	 * Test block-type instructions contain all section headers.
	 */
	public function test_instructions_contain_section_headers(): void {
		$instructions = $this->invoke_private( 'get_block_type_instructions' );

		$this->assertStringContainsString( 'Headings (core/heading):', $instructions );
		$this->assertStringContainsString( 'Paragraphs (core/paragraph):', $instructions );
		$this->assertStringContainsString( 'Lists (core/list):', $instructions );
		$this->assertStringContainsString( 'Quotes (core/quote):', $instructions );
		$this->assertStringContainsString( 'Images (core/image):', $instructions );
		$this->assertStringContainsString( 'Tables (core/table):', $instructions );
		$this->assertStringContainsString( 'Code (core/code):', $instructions );
	}

	/**
	 * Test block-type instructions start with expected header.
	 */
	public function test_instructions_header(): void {
		$instructions = $this->invoke_private( 'get_block_type_instructions' );

		$this->assertStringStartsWith( 'BLOCK-TYPE SPECIFIC GUIDANCE:', $instructions );
	}

	/**
	 * Test format_blocks_for_prompt includes hint for known block type.
	 */
	public function test_format_blocks_includes_hint_for_known_type(): void {
		$blocks = array(
			array(
				'clientId' => 'abc-123',
				'name'     => 'core/heading',
				'content'  => 'My Heading',
			),
		);

		$result = $this->invoke_private( 'format_blocks_for_prompt', array( $blocks ) );

		$this->assertStringContainsString( '[core/heading - review for hierarchy and SEO]', $result );
		$this->assertStringContainsString( 'Block ID: abc-123', $result );
		$this->assertStringContainsString( 'My Heading', $result );
	}

	/**
	 * Test format_blocks_for_prompt has no hint for unknown block type.
	 */
	public function test_format_blocks_no_hint_for_unknown_type(): void {
		$blocks = array(
			array(
				'clientId' => 'xyz-789',
				'name'     => 'core/buttons',
				'content'  => 'Click me',
			),
		);

		$result = $this->invoke_private( 'format_blocks_for_prompt', array( $blocks ) );

		$this->assertStringContainsString( '[core/buttons]', $result );
		$this->assertStringNotContainsString( '[core/buttons -', $result );
	}

	/**
	 * Test format_blocks_for_prompt skips empty content blocks.
	 */
	public function test_format_blocks_skips_empty_content(): void {
		$blocks = array(
			array(
				'clientId' => 'empty-1',
				'name'     => 'core/paragraph',
				'content'  => '',
			),
			array(
				'clientId' => 'full-1',
				'name'     => 'core/paragraph',
				'content'  => 'Has content',
			),
		);

		$result = $this->invoke_private( 'format_blocks_for_prompt', array( $blocks ) );

		$this->assertStringNotContainsString( 'empty-1', $result );
		$this->assertStringContainsString( 'full-1', $result );
	}

	/**
	 * Test format_blocks_for_prompt truncates long content.
	 */
	public function test_format_blocks_truncates_long_content(): void {
		$blocks = array(
			array(
				'clientId' => 'long-1',
				'name'     => 'core/paragraph',
				'content'  => str_repeat( 'A', 2500 ),
			),
		);

		$result = $this->invoke_private( 'format_blocks_for_prompt', array( $blocks ) );

		$this->assertStringContainsString( '... [truncated]', $result );
	}

	/**
	 * Test full prompt contains block-type instructions section.
	 */
	public function test_full_prompt_contains_block_type_instructions(): void {
		$blocks = array(
			array(
				'clientId' => 'h-1',
				'name'     => 'core/heading',
				'content'  => 'Test Heading',
			),
		);

		$prompt = $this->builder->build_review_prompt( $blocks );

		$this->assertStringContainsString( 'BLOCK-TYPE SPECIFIC GUIDANCE:', $prompt );
	}

	/**
	 * Test full prompt includes type hints in document blocks section.
	 */
	public function test_full_prompt_includes_hints_in_blocks(): void {
		$blocks = array(
			array(
				'clientId' => 'p-1',
				'name'     => 'core/paragraph',
				'content'  => 'Some text here.',
			),
			array(
				'clientId' => 'l-1',
				'name'     => 'core/list',
				'content'  => '<li>Item one</li>',
			),
		);

		$prompt = $this->builder->build_review_prompt( $blocks );

		$this->assertStringContainsString( '[core/paragraph - review for readability and clarity]', $prompt );
		$this->assertStringContainsString( '[core/list - review for parallel structure]', $prompt );
	}

	/**
	 * Test full prompt preserves existing sections in expected order.
	 */
	public function test_full_prompt_section_order(): void {
		$blocks = array(
			array(
				'clientId' => 'h-1',
				'name'     => 'core/heading',
				'content'  => 'Title',
			),
		);

		$prompt = $this->builder->build_review_prompt( $blocks );

		$doc_pos          = strpos( $prompt, 'DOCUMENT BLOCKS:' );
		$focus_pos        = strpos( $prompt, 'FOCUS AREAS:' );
		$tone_pos         = strpos( $prompt, 'TARGET TONE:' );
		$block_type_pos   = strpos( $prompt, 'BLOCK-TYPE SPECIFIC GUIDANCE:' );
		$instructions_pos = strpos( $prompt, 'INSTRUCTIONS:' );
		$output_pos       = strpos( $prompt, 'OUTPUT FORMAT:' );

		$this->assertGreaterThan( $doc_pos, $focus_pos );
		$this->assertGreaterThan( $focus_pos, $tone_pos );
		$this->assertGreaterThan( $tone_pos, $block_type_pos );
		$this->assertGreaterThan( $block_type_pos, $instructions_pos );
		$this->assertGreaterThan( $instructions_pos, $output_pos );
	}

	/**
	 * Test few-shot examples contain all four categories.
	 */
	public function test_few_shot_examples_cover_all_categories(): void {
		$examples = $this->invoke_private( 'get_few_shot_examples' );

		$this->assertStringContainsString( '"category": "content"', $examples );
		$this->assertStringContainsString( '"category": "tone"', $examples );
		$this->assertStringContainsString( '"category": "flow"', $examples );
		$this->assertStringContainsString( '"category": "design"', $examples );
	}

	/**
	 * Test few-shot examples include both severity levels.
	 */
	public function test_few_shot_examples_cover_severities(): void {
		$examples = $this->invoke_private( 'get_few_shot_examples' );

		$this->assertStringContainsString( '"severity": "important"', $examples );
		$this->assertStringContainsString( '"severity": "suggestion"', $examples );
	}

	/**
	 * Test few-shot examples include good content case.
	 */
	public function test_few_shot_examples_include_good_content_case(): void {
		$examples = $this->invoke_private( 'get_few_shot_examples' );

		$this->assertStringContainsString( '"feedback": []', $examples );
	}

	/**
	 * Test few-shot examples are included in the full prompt.
	 */
	public function test_few_shot_examples_in_prompt(): void {
		$blocks = array(
			array(
				'clientId' => 'test-1',
				'name'     => 'core/paragraph',
				'content'  => 'Test content.',
			),
		);

		$prompt = $this->builder->build_review_prompt( $blocks );

		$this->assertStringContainsString( 'REFERENCE EXAMPLES:', $prompt );
	}

	/**
	 * Test few-shot examples appear between OUTPUT FORMAT and IMPORTANT.
	 */
	public function test_few_shot_examples_position(): void {
		$blocks = array(
			array(
				'clientId' => 'test-1',
				'name'     => 'core/paragraph',
				'content'  => 'Test content.',
			),
		);

		$prompt = $this->builder->build_review_prompt( $blocks );

		$output_pos   = strpos( $prompt, 'OUTPUT FORMAT:' );
		$examples_pos = strpos( $prompt, 'REFERENCE EXAMPLES:' );
		$important_pos = strpos( $prompt, 'IMPORTANT:' );

		$this->assertGreaterThan( $output_pos, $examples_pos );
		$this->assertGreaterThan( $examples_pos, $important_pos );
	}
}
