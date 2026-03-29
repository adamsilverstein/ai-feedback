<?php
/**
 * Tests for Response_Parser feedback grouping functionality.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Response_Parser;

/**
 * Test cases for Response_Parser grouping logic.
 */
class ResponseParserGroupingTest extends TestCase
{

	/**
	 * Parser instance.
	 *
	 * @var Response_Parser
	 */
	private Response_Parser $parser;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void
	{
		parent::setUp();
		$this->parser = new Response_Parser();
	}

	/**
	 * Test that feedback items with different titles are not grouped.
	 */
	public function test_different_titles_not_grouped(): void
	{
		$feedback = array(
			array(
				'block_id' => 'abc-123',
				'category' => 'content',
				'severity' => 'suggestion',
				'title'    => 'Fix grammar',
				'feedback' => 'This has grammar issues.',
			),
			array(
				'block_id' => 'def-456',
				'category' => 'content',
				'severity' => 'suggestion',
				'title'    => 'Improve clarity',
				'feedback' => 'This could be clearer.',
			),
		);

		$grouped = $this->parser->group_similar_feedback( $feedback );

		$this->assertCount( 2, $grouped );
		$this->assertFalse( $grouped[0]['is_group'] );
		$this->assertFalse( $grouped[1]['is_group'] );
		$this->assertSame( 1, $grouped[0]['count'] );
		$this->assertSame( 1, $grouped[1]['count'] );
	}

	/**
	 * Test that feedback items with the same title and category are grouped.
	 */
	public function test_same_title_and_category_grouped(): void
	{
		$feedback = array(
			array(
				'block_id' => 'abc-123',
				'category' => 'content',
				'severity' => 'suggestion',
				'title'    => 'Fix passive voice',
				'feedback' => 'This paragraph uses passive voice.',
			),
			array(
				'block_id' => 'def-456',
				'category' => 'content',
				'severity' => 'suggestion',
				'title'    => 'Fix passive voice',
				'feedback' => 'This paragraph also uses passive voice.',
			),
			array(
				'block_id' => 'ghi-789',
				'category' => 'content',
				'severity' => 'suggestion',
				'title'    => 'Fix passive voice',
				'feedback' => 'Another instance of passive voice.',
			),
		);

		$grouped = $this->parser->group_similar_feedback( $feedback );

		$this->assertCount( 1, $grouped, 'Should group all three items into one' );
		$this->assertTrue( $grouped[0]['is_group'], 'Should be marked as a group' );
		$this->assertSame( 3, $grouped[0]['count'], 'Count should be 3' );
		$this->assertCount( 3, $grouped[0]['block_ids'], 'Should have 3 block IDs' );
		$this->assertStringContainsString( '3 occurrences', $grouped[0]['title'] );
		$this->assertArrayHasKey( 'original_title', $grouped[0] );
		$this->assertSame( 'Fix passive voice', $grouped[0]['original_title'] );
	}

	/**
	 * Test that similar titles are normalized and grouped correctly.
	 */
	public function test_similar_titles_normalized_and_grouped(): void
	{
		$feedback = array(
			array(
				'block_id' => 'abc-123',
				'category' => 'tone',
				'severity' => 'suggestion',
				'title'    => 'Fix passive voice!',
				'feedback' => 'First instance.',
			),
			array(
				'block_id' => 'def-456',
				'category' => 'tone',
				'severity' => 'suggestion',
				'title'    => 'Fix Passive Voice',
				'feedback' => 'Second instance.',
			),
			array(
				'block_id' => 'ghi-789',
				'category' => 'tone',
				'severity' => 'suggestion',
				'title'    => 'fix-passive-voice',
				'feedback' => 'Third instance.',
			),
		);

		$grouped = $this->parser->group_similar_feedback( $feedback );

		$this->assertCount( 1, $grouped, 'Normalized titles should group together' );
		$this->assertTrue( $grouped[0]['is_group'] );
		$this->assertSame( 3, $grouped[0]['count'] );
	}

	/**
	 * Test that different categories prevent grouping even with same title.
	 */
	public function test_different_categories_not_grouped(): void
	{
		$feedback = array(
			array(
				'block_id' => 'abc-123',
				'category' => 'content',
				'severity' => 'suggestion',
				'title'    => 'Improve clarity',
				'feedback' => 'Content clarity issue.',
			),
			array(
				'block_id' => 'def-456',
				'category' => 'tone',
				'severity' => 'suggestion',
				'title'    => 'Improve clarity',
				'feedback' => 'Tone clarity issue.',
			),
		);

		$grouped = $this->parser->group_similar_feedback( $feedback );

		$this->assertCount( 2, $grouped, 'Different categories should not group' );
		$this->assertFalse( $grouped[0]['is_group'] );
		$this->assertFalse( $grouped[1]['is_group'] );
	}

	/**
	 * Test that highest severity is kept in grouped items.
	 */
	public function test_highest_severity_kept_in_group(): void
	{
		$feedback = array(
			array(
				'block_id' => 'abc-123',
				'category' => 'content',
				'severity' => 'suggestion',
				'title'    => 'Fix spelling',
				'feedback' => 'Spelling issue.',
			),
			array(
				'block_id' => 'def-456',
				'category' => 'content',
				'severity' => 'critical',
				'title'    => 'Fix spelling',
				'feedback' => 'Critical spelling issue.',
			),
			array(
				'block_id' => 'ghi-789',
				'category' => 'content',
				'severity' => 'important',
				'title'    => 'Fix spelling',
				'feedback' => 'Important spelling issue.',
			),
		);

		$grouped = $this->parser->group_similar_feedback( $feedback );

		$this->assertCount( 1, $grouped );
		$this->assertSame( 'critical', $grouped[0]['severity'], 'Should keep highest severity' );
	}

	/**
	 * Test that block_ids array contains all grouped block IDs.
	 */
	public function test_block_ids_array_contains_all_blocks(): void
	{
		$feedback = array(
			array(
				'block_id' => 'block-1',
				'category' => 'flow',
				'severity' => 'suggestion',
				'title'    => 'Add transition',
				'feedback' => 'Missing transition.',
			),
			array(
				'block_id' => 'block-2',
				'category' => 'flow',
				'severity' => 'suggestion',
				'title'    => 'Add transition',
				'feedback' => 'Missing transition.',
			),
			array(
				'block_id' => 'block-3',
				'category' => 'flow',
				'severity' => 'suggestion',
				'title'    => 'Add transition',
				'feedback' => 'Missing transition.',
			),
		);

		$grouped = $this->parser->group_similar_feedback( $feedback );

		$this->assertCount( 1, $grouped );
		$this->assertSame(
			array( 'block-1', 'block-2', 'block-3' ),
			$grouped[0]['block_ids']
		);
	}

	/**
	 * Test that empty feedback array returns empty result.
	 */
	public function test_empty_feedback_returns_empty(): void
	{
		$grouped = $this->parser->group_similar_feedback( array() );
		$this->assertSame( array(), $grouped );
	}

	/**
	 * Test that single feedback item is not marked as group.
	 */
	public function test_single_item_not_marked_as_group(): void
	{
		$feedback = array(
			array(
				'block_id' => 'abc-123',
				'category' => 'content',
				'severity' => 'suggestion',
				'title'    => 'Fix typo',
				'feedback' => 'Found a typo.',
			),
		);

		$grouped = $this->parser->group_similar_feedback( $feedback );

		$this->assertCount( 1, $grouped );
		$this->assertFalse( $grouped[0]['is_group'] );
		$this->assertSame( 1, $grouped[0]['count'] );
		$this->assertSame( 'Fix typo', $grouped[0]['title'], 'Title should not be modified' );
	}

	/**
	 * Test that block_name and block_index are preserved in groups.
	 */
	public function test_block_metadata_preserved_in_groups(): void
	{
		$feedback = array(
			array(
				'block_id'    => 'block-1',
				'category'    => 'content',
				'severity'    => 'suggestion',
				'title'       => 'Fix issue',
				'feedback'    => 'Issue found.',
				'block_name'  => 'core/paragraph',
				'block_index' => 0,
			),
			array(
				'block_id'    => 'block-2',
				'category'    => 'content',
				'severity'    => 'suggestion',
				'title'       => 'Fix issue',
				'feedback'    => 'Issue found.',
				'block_name'  => 'core/paragraph',
				'block_index' => 1,
			),
		);

		$grouped = $this->parser->group_similar_feedback( $feedback );

		$this->assertCount( 1, $grouped );
		$this->assertArrayHasKey( 'block_names', $grouped[0] );
		$this->assertArrayHasKey( 'block_indexes', $grouped[0] );
		$this->assertCount( 2, $grouped[0]['block_names'] );
		$this->assertCount( 2, $grouped[0]['block_indexes'] );
	}

	/**
	 * Test that grouping is integrated into parse_feedback workflow.
	 */
	public function test_grouping_integrated_in_parse_feedback(): void
	{
		$blocks = array(
			array(
				'clientId' => 'block-1',
				'name'     => 'core/paragraph',
				'content'  => 'First paragraph.',
			),
			array(
				'clientId' => 'block-2',
				'name'     => 'core/paragraph',
				'content'  => 'Second paragraph.',
			),
		);

		$response = wp_json_encode(
			array(
				'summary'  => 'Good overall.',
				'feedback' => array(
					array(
						'block_id' => 'block-1',
						'category' => 'content',
						'severity' => 'suggestion',
						'title'    => 'Fix passive voice',
						'feedback' => 'Use active voice.',
					),
					array(
						'block_id' => 'block-2',
						'category' => 'content',
						'severity' => 'suggestion',
						'title'    => 'Fix passive voice',
						'feedback' => 'Use active voice.',
					),
				),
			)
		);

		$parsed = $this->parser->parse_feedback( $response, $blocks );

		$this->assertIsArray( $parsed );
		$this->assertArrayHasKey( 'feedback', $parsed );
		$this->assertCount( 1, $parsed['feedback'], 'Should group duplicate feedback' );
		$this->assertTrue( $parsed['feedback'][0]['is_group'] );
		$this->assertSame( 2, $parsed['feedback'][0]['count'] );
	}
}
