<?php
/**
 * Tests for Response_Parser priority scoring.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Response_Parser;

/**
 * Test cases for Response_Parser priority scoring and sorting.
 */
class ResponseParserPriorityTest extends TestCase {

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
	 * Critical severity should dominate the score.
	 */
	public function test_critical_severity_outweighs_other_factors(): void {
		$critical_paragraph = $this->parser->calculate_priority(
			array(
				'severity'    => 'critical',
				'category'    => 'design',
				'block_name'  => 'core/paragraph',
				'block_index' => 10,
			)
		);

		$suggestion_heading = $this->parser->calculate_priority(
			array(
				'severity'    => 'suggestion',
				'category'    => 'content',
				'block_name'  => 'core/heading',
				'block_index' => 0,
			)
		);

		$this->assertGreaterThan(
			$suggestion_heading,
			$critical_paragraph,
			'Critical items should outrank suggestion items even when other factors favour the suggestion.'
		);
	}

	/**
	 * Position bonus should taper after the first five blocks.
	 */
	public function test_position_bonus_decays_to_zero(): void {
		$base_item = array(
			'severity'   => 'important',
			'category'   => 'content',
			'block_name' => 'core/paragraph',
		);

		$first  = $this->parser->calculate_priority( $base_item + array( 'block_index' => 0 ) );
		$fifth  = $this->parser->calculate_priority( $base_item + array( 'block_index' => 5 ) );
		$tenth  = $this->parser->calculate_priority( $base_item + array( 'block_index' => 10 ) );
		$twenty = $this->parser->calculate_priority( $base_item + array( 'block_index' => 20 ) );

		$this->assertSame( $first - 25, $fifth, 'First block should get a 25-point bonus.' );
		$this->assertSame( $fifth, $tenth, 'Position bonus should clamp at zero past block index 5.' );
		$this->assertSame( $tenth, $twenty, 'Position bonus should not go negative for far-back blocks.' );
	}

	/**
	 * Unknown enum values should default cleanly to zero / default weight.
	 */
	public function test_unknown_values_use_safe_defaults(): void {
		$score = $this->parser->calculate_priority(
			array(
				'severity'   => 'unknown_severity',
				'category'   => 'unknown_category',
				'block_name' => 'core/spacer',
			)
		);

		// Only the default block weight applies: no severity, category, or position bonus.
		$this->assertSame( Response_Parser::PRIORITY_BLOCK_DEFAULT, $score );
	}

	/**
	 * sort_by_priority returns items in descending priority order.
	 */
	public function test_sort_by_priority_orders_descending(): void {
		$items = array(
			array(
				'priority' => 30,
				'block_id' => 'low',
			),
			array(
				'priority' => 130,
				'block_id' => 'high',
			),
			array(
				'priority' => 70,
				'block_id' => 'mid',
			),
		);

		$sorted = $this->parser->sort_by_priority( $items );

		$this->assertSame( array( 'high', 'mid', 'low' ), array_column( $sorted, 'block_id' ) );
	}

	/**
	 * Ties on priority should fall back to block_index ascending so the
	 * earliest block wins, keeping document order within a priority bucket.
	 */
	public function test_sort_by_priority_breaks_ties_by_block_index(): void {
		$items = array(
			array(
				'priority'    => 50,
				'block_index' => 3,
				'block_id'    => 'b3',
			),
			array(
				'priority'    => 50,
				'block_index' => 1,
				'block_id'    => 'b1',
			),
			array(
				'priority'    => 50,
				'block_index' => 2,
				'block_id'    => 'b2',
			),
		);

		$sorted = $this->parser->sort_by_priority( $items );

		$this->assertSame( array( 'b1', 'b2', 'b3' ), array_column( $sorted, 'block_id' ) );
	}

	/**
	 * Items missing priority entirely should be treated as zero and sink to the bottom.
	 */
	public function test_sort_by_priority_treats_missing_as_zero(): void {
		$items = array(
			array( 'block_id' => 'no_priority' ),
			array(
				'priority' => 1,
				'block_id' => 'low_priority',
			),
		);

		$sorted = $this->parser->sort_by_priority( $items );

		$this->assertSame( array( 'low_priority', 'no_priority' ), array_column( $sorted, 'block_id' ) );
	}

	/**
	 * Grouping should keep the highest priority across grouped items.
	 */
	public function test_grouping_preserves_highest_priority(): void {
		$feedback = array(
			array(
				'block_id'   => 'a',
				'category'   => 'content',
				'severity'   => 'suggestion',
				'title'      => 'Fix passive voice',
				'feedback'   => 'Use active voice.',
				'block_name' => 'core/paragraph',
				'priority'   => 45,
			),
			array(
				'block_id'   => 'b',
				'category'   => 'content',
				'severity'   => 'critical',
				'title'      => 'Fix passive voice',
				'feedback'   => 'Use active voice.',
				'block_name' => 'core/heading',
				'priority'   => 145,
			),
		);

		$grouped = $this->parser->group_similar_feedback( $feedback );

		$this->assertCount( 1, $grouped );
		$this->assertTrue( $grouped[0]['is_group'] );
		$this->assertSame( 145, $grouped[0]['priority'], 'Group should retain the highest member priority.' );
	}

	/**
	 * End-to-end: parse_feedback should surface critical items above suggestions
	 * regardless of the order in which the AI returned them.
	 */
	public function test_parse_feedback_sorts_critical_first(): void {
		$blocks = array(
			array(
				'clientId' => 'block-1',
				'name'     => 'core/paragraph',
			),
			array(
				'clientId' => 'block-2',
				'name'     => 'core/heading',
			),
		);

		$response = (string) wp_json_encode(
			array(
				'summary'  => 'Looks fine.',
				'feedback' => array(
					array(
						'block_id' => 'block-1',
						'category' => 'design',
						'severity' => 'suggestion',
						'title'    => 'Tweak spacing',
						'feedback' => 'Consider adjusting spacing.',
					),
					array(
						'block_id' => 'block-2',
						'category' => 'content',
						'severity' => 'critical',
						'title'    => 'Fix factual error',
						'feedback' => 'This statement is incorrect.',
					),
				),
			)
		);

		$parsed = $this->parser->parse_feedback( $response, $blocks );

		$this->assertIsArray( $parsed );
		$feedback = $parsed['feedback'];
		$this->assertIsArray( $feedback );
		$this->assertSame( array( 'critical', 'suggestion' ), array_column( $feedback, 'severity' ) );
		$this->assertSame( array( 'block-2', 'block-1' ), array_column( $feedback, 'block_id' ) );

		$priorities = array_column( $feedback, 'priority' );
		$this->assertCount( 2, $priorities );
		$this->assertGreaterThan( $priorities[1], $priorities[0] );
	}

	/**
	 * Groups break priority ties on their earliest member block, even when
	 * the first member (whose block_index the group keeps) is later in the post.
	 */
	public function test_sort_by_priority_uses_earliest_group_member(): void {
		$items = array(
			array(
				'priority'    => 50,
				'block_index' => 3,
				'block_id'    => 'single',
			),
			array(
				'priority'      => 50,
				'block_index'   => 6,
				'block_indexes' => array( 6, 1 ),
				'block_id'      => 'group',
			),
		);

		$sorted = $this->parser->sort_by_priority( $items );

		$this->assertSame( array( 'group', 'single' ), array_column( $sorted, 'block_id' ) );
	}

	/**
	 * Pin how severities land in the client buckets so a weight change that
	 * moves them shows up here.
	 */
	public function test_severity_floors_map_to_expected_buckets(): void {
		$lowest = array(
			'category'    => 'unknown',
			'block_name'  => 'core/unknown',
			'block_index' => 100,
		);

		$critical  = $this->parser->calculate_priority( $lowest + array( 'severity' => 'critical' ) );
		$important = $this->parser->calculate_priority( $lowest + array( 'severity' => 'important' ) );

		$this->assertGreaterThanOrEqual( Response_Parser::PRIORITY_HIGH_THRESHOLD, $critical, 'Critical items are always high priority.' );
		$this->assertGreaterThanOrEqual( Response_Parser::PRIORITY_MEDIUM_THRESHOLD, $important, 'Important items are at least medium priority.' );
	}
}
