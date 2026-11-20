<?php
/**
 * Tests for structural edits (block actions) in Response_Parser.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Response_Parser;

/**
 * Test cases for validating `block_action` on review feedback and
 * structural edits on AI replies.
 */
class ResponseParserBlockActionTest extends TestCase {

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
	 * Clear forced filter results.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['test_filter_results'] );
		parent::tearDown();
	}

	/**
	 * Reviewed blocks, in document order.
	 *
	 * @return list<array<string, string>>
	 */
	private static function blocks(): array {
		return array(
			array(
				'clientId' => 'heading',
				'name'     => 'core/heading',
				'content'  => 'Why this matters',
			),
			array(
				'clientId' => 'background',
				'name'     => 'core/paragraph',
				'content'  => 'Background on the project.',
			),
			array(
				'clientId' => 'opening',
				'name'     => 'core/paragraph',
				'content'  => 'The real opening of the piece.',
			),
			array(
				'clientId' => 'repeat',
				'name'     => 'core/paragraph',
				'content'  => 'This repeats section two.',
			),
		);
	}

	/**
	 * Build a feedback item for a block with a block action.
	 *
	 * @param string $block_id Block the feedback is on.
	 * @param mixed  $action   Block action.
	 * @param string $title    Feedback title.
	 * @return array<string, mixed>
	 */
	private static function item( string $block_id, mixed $action, string $title = 'Restructure' ): array {
		return array(
			'block_id'     => $block_id,
			'category'     => 'flow',
			'severity'     => 'important',
			'title'        => $title,
			'feedback'     => 'The structure could be clearer.',
			'block_action' => $action,
		);
	}

	/**
	 * Parse review feedback items against the reviewed blocks.
	 *
	 * @param list<array<string, mixed>> $items Feedback items.
	 * @return list<array<string, mixed>> Parsed feedback.
	 */
	private function parse( array $items ): array {
		$result = $this->parser->parse_feedback(
			(string) json_encode(
				array(
					'summary'  => 'Summary.',
					'feedback' => $items,
				)
			),
			self::blocks()
		);
		$this->assertIsArray( $result );

		/** @var list<array<string, mixed>> $feedback */
		$feedback = $result['feedback'];
		return $feedback;
	}

	/**
	 * Parse one item and return its block action, or null.
	 *
	 * @param string $block_id Block the feedback is on.
	 * @param mixed  $action   Block action.
	 * @return array<string, mixed>|null
	 */
	private function parse_action( string $block_id, mixed $action ): ?array {
		$feedback = $this->parse( array( self::item( $block_id, $action ) ) );
		$this->assertCount( 1, $feedback, 'The feedback itself is always kept.' );

		/** @var array<string, mixed>|null $parsed */
		$parsed = $feedback[0]['block_action'] ?? null;
		return $parsed;
	}

	/**
	 * Valid actions are normalized to the stored shape.
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: array<string, mixed>}>
	 */
	public static function valid_action_provider(): array {
		return array(
			'remove'                       => array(
				'repeat',
				array( 'type' => 'remove' ),
				array( 'type' => 'remove_block' ),
			),
			'remove with long type name'   => array(
				'repeat',
				array( 'type' => 'remove_block' ),
				array( 'type' => 'remove_block' ),
			),
			'move before an earlier block' => array(
				'opening',
				array(
					'type'            => 'move',
					'target_block_id' => 'background',
					'position'        => 'before',
				),
				array(
					'type'            => 'move_block',
					'position'        => 'before',
					'target_block_id' => 'background',
					'target_name'     => 'core/paragraph',
					'target_text'     => 'Background on the project.',
				),
			),
			'move after a later block'     => array(
				'heading',
				array(
					'type'            => 'move',
					'target_block_id' => 'opening',
					'position'        => 'after',
				),
				array(
					'type'            => 'move_block',
					'position'        => 'after',
					'target_block_id' => 'opening',
					'target_name'     => 'core/paragraph',
					'target_text'     => 'The real opening of the piece.',
				),
			),
			'insert paragraph'             => array(
				'repeat',
				array(
					'type'     => 'insert',
					'position' => 'after',
					'block'    => array(
						'name'    => 'core/paragraph',
						'content' => 'A <strong>new</strong> closing line.',
					),
				),
				array(
					'type'     => 'insert_block',
					'position' => 'after',
					'block'    => array(
						'name'    => 'core/paragraph',
						'content' => 'A <strong>new</strong> closing line.',
					),
				),
			),
			'insert heading with level'    => array(
				'repeat',
				array(
					'type'     => 'insert',
					'position' => 'before',
					'block'    => array(
						'name'    => 'core/heading',
						'level'   => 3,
						'content' => 'How to try it',
					),
				),
				array(
					'type'     => 'insert_block',
					'position' => 'before',
					'block'    => array(
						'name'    => 'core/heading',
						'content' => 'How to try it',
						'level'   => 3,
					),
				),
			),
			'heading level out of range'   => array(
				'repeat',
				array(
					'type'     => 'insert',
					'position' => 'before',
					'block'    => array(
						'name'    => 'core/heading',
						'level'   => 1,
						'content' => 'How to try it',
					),
				),
				array(
					'type'     => 'insert_block',
					'position' => 'before',
					'block'    => array(
						'name'    => 'core/heading',
						'content' => 'How to try it',
						'level'   => 2,
					),
				),
			),
			'insert list'                  => array(
				'repeat',
				array(
					'type'     => 'insert',
					'position' => 'after',
					'block'    => array(
						'name'    => 'core/list',
						'ordered' => true,
						'items'   => array( 'One', 'Two' ),
					),
				),
				array(
					'type'     => 'insert_block',
					'position' => 'after',
					'block'    => array(
						'name'    => 'core/list',
						'items'   => array( 'One', 'Two' ),
						'ordered' => true,
					),
				),
			),
		);
	}

	/**
	 * Valid actions are kept and normalized.
	 *
	 * @dataProvider valid_action_provider
	 *
	 * @param string               $block_id Block the feedback is on.
	 * @param mixed                $action   Block action from the AI.
	 * @param array<string, mixed> $expected Stored action.
	 */
	public function test_valid_actions_are_kept( string $block_id, mixed $action, array $expected ): void {
		$this->assertSame( $expected, $this->parse_action( $block_id, $action ) );
	}

	/**
	 * Invalid actions are dropped while the feedback stays.
	 *
	 * @return array<string, array{0: string, 1: mixed}>
	 */
	public static function invalid_action_provider(): array {
		$insert = static fn ( array $block ): array => array(
			'type'     => 'insert',
			'position' => 'after',
			'block'    => $block,
		);

		return array(
			'not an object'                 => array( 'repeat', 'remove' ),
			'unknown type'                  => array( 'repeat', array( 'type' => 'split' ) ),
			'move to an unknown block'      => array(
				'opening',
				array(
					'type'            => 'move',
					'target_block_id' => 'missing',
					'position'        => 'before',
				),
			),
			'move next to itself'           => array(
				'opening',
				array(
					'type'            => 'move',
					'target_block_id' => 'opening',
					'position'        => 'before',
				),
			),
			'move before the next block'    => array(
				'background',
				array(
					'type'            => 'move',
					'target_block_id' => 'opening',
					'position'        => 'before',
				),
			),
			'move after the previous block' => array(
				'opening',
				array(
					'type'            => 'move',
					'target_block_id' => 'background',
					'position'        => 'after',
				),
			),
			'move without a position'       => array(
				'opening',
				array(
					'type'            => 'move',
					'target_block_id' => 'heading',
				),
			),
			'move with a bad position'      => array(
				'opening',
				array(
					'type'            => 'move',
					'target_block_id' => 'heading',
					'position'        => 'inside',
				),
			),
			'insert a block not allowed'    => array(
				'repeat',
				$insert(
					array(
						'name'    => 'core/html',
						'content' => 'Text',
					)
				),
			),
			'insert without a block'        => array(
				'repeat',
				array(
					'type'     => 'insert',
					'position' => 'after',
				),
			),
			'insert block-level markup'     => array(
				'repeat',
				$insert(
					array(
						'name'    => 'core/paragraph',
						'content' => 'Text<div>more</div>',
					)
				),
			),
			'insert a script'               => array(
				'repeat',
				$insert(
					array(
						'name'    => 'core/paragraph',
						'content' => 'Text<script>alert(1)</script>',
					)
				),
			),
			'insert a comment'              => array(
				'repeat',
				$insert(
					array(
						'name'    => 'core/paragraph',
						'content' => 'Text<!-- wp:html -->',
					)
				),
			),
			'insert empty text'             => array(
				'repeat',
				$insert(
					array(
						'name'    => 'core/paragraph',
						'content' => ' <em> </em> ',
					)
				),
			),
			'insert too long'               => array(
				'repeat',
				$insert(
					array(
						'name'    => 'core/paragraph',
						'content' => str_repeat( 'a', Response_Parser::MAX_INSERT_LENGTH + 1 ),
					)
				),
			),
			'list without items'            => array(
				'repeat',
				$insert(
					array(
						'name'  => 'core/list',
						'items' => array(),
					)
				),
			),
			'list with too many items'      => array(
				'repeat',
				$insert(
					array(
						'name'  => 'core/list',
						'items' => array_fill( 0, Response_Parser::MAX_INSERT_LIST_ITEMS + 1, 'Item' ),
					)
				),
			),
			'list with a non-text item'     => array(
				'repeat',
				$insert(
					array(
						'name'  => 'core/list',
						'items' => array( 'One', array( 'Two' ) ),
					)
				),
			),
		);
	}

	/**
	 * Invalid actions are dropped.
	 *
	 * @dataProvider invalid_action_provider
	 *
	 * @param string $block_id Block the feedback is on.
	 * @param mixed  $action   Block action from the AI.
	 */
	public function test_invalid_actions_are_dropped( string $block_id, mixed $action ): void {
		$this->assertNull( $this->parse_action( $block_id, $action ) );
	}

	/**
	 * A move keeps the start of its target's text, whitespace collapsed.
	 */
	public function test_move_keeps_short_target_text(): void {
		$blocks               = self::blocks();
		$blocks[0]['content'] = "Why\n  this " . str_repeat( 'matters ', 30 );

		$result = $this->parser->parse_feedback(
			(string) json_encode(
				array(
					'feedback' => array(
						self::item(
							'opening',
							array(
								'type'            => 'move',
								'target_block_id' => 'heading',
								'position'        => 'before',
							)
						),
					),
				)
			),
			$blocks
		);

		$this->assertIsArray( $result );
		$action = $result['feedback'][0]['block_action'];
		$this->assertSame( Response_Parser::MAX_TARGET_TEXT_LENGTH, mb_strlen( $action['target_text'] ) );
		$this->assertStringStartsWith( 'Why this matters matters', $action['target_text'] );
	}

	/**
	 * Only the first structural edit for a block in a review is kept.
	 */
	public function test_one_action_per_block(): void {
		$feedback = $this->parse(
			array(
				self::item( 'repeat', array( 'type' => 'remove' ), 'Cut repetition' ),
				self::item(
					'repeat',
					array(
						'type'            => 'move',
						'target_block_id' => 'heading',
						'position'        => 'before',
					),
					'Move it up'
				),
			)
		);

		$this->assertCount( 2, $feedback );
		$actions = array_map( static fn ( array $item ): mixed => $item['block_action'] ?? null, $feedback );
		$this->assertSame( array( array( 'type' => 'remove_block' ) ), array_values( array_filter( $actions ) ) );
	}

	/**
	 * A structural edit replaces the item's quick fix.
	 */
	public function test_action_replaces_quick_fix(): void {
		$item              = self::item( 'repeat', array( 'type' => 'remove' ) );
		$item['quick_fix'] = array(
			'find'    => 'repeats',
			'replace' => 'restates',
		);

		$feedback = $this->parse( array( $item ) );

		$this->assertSame( array( 'type' => 'remove_block' ), $feedback[0]['block_action'] );
		$this->assertArrayNotHasKey( 'quick_fix', $feedback[0] );
	}

	/**
	 * An invalid structural edit leaves the quick fix in place.
	 */
	public function test_invalid_action_keeps_quick_fix(): void {
		$item              = self::item( 'repeat', array( 'type' => 'split' ) );
		$item['quick_fix'] = array(
			'find'    => 'repeats',
			'replace' => 'restates',
		);

		$feedback = $this->parse( array( $item ) );

		$this->assertArrayNotHasKey( 'block_action', $feedback[0] );
		$this->assertSame( 'restates', $feedback[0]['quick_fix']['replace'] );
	}

	/**
	 * A group of similar feedback targets several blocks, so it has no
	 * structural edit.
	 */
	public function test_groups_drop_actions(): void {
		$feedback = $this->parse(
			array(
				self::item( 'repeat', array( 'type' => 'remove' ), 'Same title' ),
				self::item( 'background', array( 'type' => 'remove' ), 'Same title' ),
			)
		);

		$this->assertCount( 1, $feedback );
		$this->assertTrue( $feedback[0]['is_group'] );
		$this->assertArrayNotHasKey( 'block_action', $feedback[0] );
	}

	/**
	 * The insertable blocks can be filtered.
	 */
	public function test_insertable_blocks_are_filterable(): void {
		$GLOBALS['test_filter_results'] = array( 'ai_feedback_insertable_blocks' => array( 'core/paragraph', 42 ) );

		$this->assertSame( array( 'core/paragraph' ), Response_Parser::get_insertable_blocks() );
		$this->assertNull(
			$this->parse_action(
				'repeat',
				array(
					'type'     => 'insert',
					'position' => 'after',
					'block'    => array(
						'name'    => 'core/heading',
						'content' => 'Heading',
					),
				)
			)
		);
	}

	/**
	 * Targets for a reply: nearby blocks keyed by the reference the AI saw.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function reply_targets(): array {
		return array(
			'b1'                            => array(
				'client_id' => '',
				'name'      => 'core/heading',
				'text'      => 'Why this matters',
				'index'     => 0,
			),
			'b2'                            => array(
				'client_id' => '',
				'name'      => 'core/paragraph',
				'text'      => 'Background on the project.',
				'index'     => 1,
			),
			Response_Parser::REPLY_SELF_REF => array(
				'client_id' => '',
				'name'      => 'core/paragraph',
				'text'      => 'The real opening of the piece.',
				'index'     => 2,
			),
		);
	}

	/**
	 * A reply can move its block next to a nearby block, named by reference.
	 */
	public function test_reply_can_move_block(): void {
		$parsed = $this->parser->parse_reply(
			'{"reply": "Moved it up.", "edit": {"type": "move_block", "target_block_id": "b1", "position": "before"}}',
			'The real opening of the piece.',
			true,
			self::reply_targets()
		);

		$this->assertIsArray( $parsed );
		$this->assertSame(
			array(
				'type'            => 'move_block',
				'position'        => 'before',
				'target_block_id' => '',
				'target_name'     => 'core/heading',
				'target_text'     => 'Why this matters',
			),
			$parsed['edit']
		);
	}

	/**
	 * A reply can remove its block, and the short type names work too.
	 */
	public function test_reply_can_remove_block(): void {
		$parsed = $this->parser->parse_reply( '{"reply": "Cut.", "edit": {"type": "remove"}}', 'Text.', true, self::reply_targets() );

		$this->assertIsArray( $parsed );
		$this->assertSame( array( 'type' => 'remove_block' ), $parsed['edit'] );
	}

	/**
	 * Structural reply edits need the nearby blocks, and a known target.
	 */
	public function test_reply_structural_edit_needs_targets(): void {
		$no_targets = $this->parser->parse_reply( '{"reply": "Cut.", "edit": {"type": "remove_block"}}', 'Text.', true );
		$unknown    = $this->parser->parse_reply(
			'{"reply": "Moved.", "edit": {"type": "move_block", "target_block_id": "b9", "position": "before"}}',
			'Text.',
			true,
			self::reply_targets()
		);

		$this->assertIsArray( $no_targets );
		$this->assertNull( $no_targets['edit'] );
		$this->assertSame( 'Cut.', $no_targets['reply'] );
		$this->assertIsArray( $unknown );
		$this->assertNull( $unknown['edit'] );
	}

	/**
	 * A reply edit that names another block is still dropped.
	 */
	public function test_reply_structural_edit_naming_a_block_is_dropped(): void {
		$parsed = $this->parser->parse_reply( '{"reply": "Cut.", "edit": {"type": "remove_block", "block_id": "b1"}}', 'Text.', true, self::reply_targets() );

		$this->assertIsArray( $parsed );
		$this->assertNull( $parsed['edit'] );
	}

	/**
	 * Structural edits are recognized by type.
	 */
	public function test_is_block_action(): void {
		$this->assertTrue( Response_Parser::is_block_action( array( 'type' => 'move_block' ) ) );
		$this->assertFalse( Response_Parser::is_block_action( array( 'type' => 'remove' ) ) );
		$this->assertFalse( Response_Parser::is_block_action( array( 'type' => 'replace_text' ) ) );
	}

	/**
	 * Short target text must match a block's whole text; text kept at the
	 * full excerpt length only its start.
	 */
	public function test_matches_target_text(): void {
		$long = str_repeat( 'Same start ', 8 );

		$this->assertFalse( Response_Parser::matches_target_text( 'Step 1 of setup.', 'Step 1' ) );
		$this->assertTrue( Response_Parser::matches_target_text( ' Step  1 ', 'Step 1' ) );
		$this->assertTrue( Response_Parser::matches_target_text( $long . 'here.', mb_substr( trim( $long ), 0, Response_Parser::MAX_TARGET_TEXT_LENGTH ) ) );
	}

	/**
	 * A block can't move next to one of its own inner blocks, at any depth,
	 * and a parent loop in crafted input ends.
	 */
	public function test_move_into_own_inner_block_is_dropped(): void {
		$blocks = array(
			array(
				'clientId' => 'group',
				'name'     => 'core/group',
				'content'  => 'First inside. Deep inside.',
			),
			array(
				'clientId' => 'columns',
				'name'     => 'core/columns',
				'content'  => 'Deep inside.',
				'parent'   => 'group',
			),
			array(
				'clientId' => 'deep',
				'name'     => 'core/paragraph',
				'content'  => 'Deep inside.',
				'parent'   => 'columns',
			),
			array(
				'clientId' => 'loop-a',
				'name'     => 'core/paragraph',
				'content'  => 'A.',
				'parent'   => 'loop-b',
			),
			array(
				'clientId' => 'loop-b',
				'name'     => 'core/paragraph',
				'content'  => 'B.',
				'parent'   => 'loop-a',
			),
		);
		$parse  = function ( string $block_id, string $target ) use ( $blocks ): mixed {
			$result = $this->parser->parse_feedback(
				(string) json_encode(
					array(
						'feedback' => array(
							self::item(
								$block_id,
								array(
									'type'            => 'move',
									'target_block_id' => $target,
									'position'        => 'after',
								)
							),
						),
					)
				),
				$blocks
			);
			$this->assertIsArray( $result );
			return $result['feedback'][0]['block_action'] ?? null;
		};

		$this->assertNull( $parse( 'group', 'deep' ) );
		$this->assertNull( $parse( 'group', 'columns' ) );
		// Out of its group, next to the group, is fine.
		$this->assertIsArray( $parse( 'deep', 'group' ) );
		$this->assertIsArray( $parse( 'loop-a', 'group' ) );
		$this->assertIsArray( $parse( 'group', 'loop-a' ) );
	}

	/**
	 * Moving an inner block out next to its own parent is a real move, even
	 * though the two sit next to each other in document order.
	 */
	public function test_move_out_of_parent_is_kept(): void {
		$blocks = array(
			array(
				'clientId' => 'group',
				'name'     => 'core/group',
				'content'  => 'Inside.',
			),
			array(
				'clientId' => 'inner',
				'name'     => 'core/paragraph',
				'content'  => 'Inside.',
				'parent'   => 'group',
			),
		);
		$result = $this->parser->parse_feedback(
			(string) json_encode(
				array(
					'feedback' => array(
						self::item(
							'inner',
							array(
								'type'            => 'move',
								'target_block_id' => 'group',
								'position'        => 'after',
							)
						),
					),
				)
			),
			$blocks
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'group', $result['feedback'][0]['block_action']['target_block_id'] );
	}

	/**
	 * Text that looks like a tag is kept in a move target, as the editor
	 * compares block text literally.
	 */
	public function test_move_target_keeps_literal_text(): void {
		$blocks               = self::blocks();
		$blocks[1]['content'] = 'Use <label> here.';

		$result = $this->parser->parse_feedback(
			(string) json_encode(
				array(
					'feedback' => array(
						self::item(
							'opening',
							array(
								'type'            => 'move',
								'target_block_id' => 'background',
								'position'        => 'before',
							)
						),
					),
				)
			),
			$blocks
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Use <label> here.', $result['feedback'][0]['block_action']['target_text'] );
	}
}
