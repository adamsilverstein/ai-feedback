<?php
/**
 * Tests for formatting fixes (`block_change`) in review responses.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Response_Parser;

/**
 * Test cases for Response_Parser::parse_feedback() with `block_change`.
 */
class ResponseParserBlockChangeTest extends TestCase {

	/**
	 * Reviewed blocks, as the editor sends them.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function blocks(): array {
		return array(
			array(
				'clientId'   => 'h2',
				'name'       => 'core/heading',
				'content'    => 'Getting started',
				'attributes' => array( 'level' => 2 ),
			),
			array(
				'clientId'   => 'h4',
				'name'       => 'core/heading',
				'content'    => 'Setup',
				'attributes' => array( 'level' => 4 ),
			),
			array(
				'clientId' => 'needs',
				'name'     => 'core/paragraph',
				'content'  => 'You need Node, npm, Docker and Git.',
			),
			array(
				'clientId'   => 'chart',
				'name'       => 'core/image',
				'content'    => '',
				'attributes' => array(
					'alt' => '',
					'id'  => 12,
					'url' => 'http://example.com/wp-content/uploads/chart.png',
				),
			),
		);
	}

	/**
	 * Parse a response holding the given items.
	 *
	 * @param  list<array<string, mixed>> $items        Feedback items.
	 * @param  list<string>               $image_blocks Blocks whose image the AI saw.
	 * @return list<array<string, mixed>> Parsed feedback.
	 */
	private static function parse( array $items, array $image_blocks = array() ): array {
		$result = ( new Response_Parser() )->parse_feedback(
			(string) wp_json_encode(
				array(
					'summary'  => 'Summary.',
					'feedback' => $items,
				)
			),
			self::blocks(),
			false,
			$image_blocks
		);
		self::assertIsArray( $result );
		self::assertIsArray( $result['feedback'] );
		/** @var list<array<string, mixed>> $feedback */
		$feedback = $result['feedback'];
		return $feedback;
	}

	/**
	 * A feedback item.
	 *
	 * @param  string               $block_id Block.
	 * @param  string               $title    Title.
	 * @param  array<string, mixed> $extra    Extra fields.
	 * @return array<string, mixed>
	 */
	private static function item( string $block_id, string $title, array $extra = array() ): array {
		return array(
			'block_id' => $block_id,
			'category' => 'design',
			'severity' => 'suggestion',
			'title'    => $title,
			'feedback' => 'Formatting.',
		) + $extra;
	}

	/**
	 * Find a parsed item by title.
	 *
	 * @param  list<array<string, mixed>> $feedback Parsed feedback.
	 * @param  string                     $title    Title.
	 * @return array<string, mixed>
	 */
	private static function find( array $feedback, string $title ): array {
		foreach ( $feedback as $item ) {
			if ( $title === $item['title'] ) {
				return $item;
			}
		}
		self::fail( "No item titled $title" );
	}

	/**
	 * A skipped heading level comes back with a fix to the right level,
	 * checked against the level the editor sent.
	 */
	public function test_skipped_heading_level(): void {
		$feedback = self::parse(
			array(
				self::item(
					'h4',
					'Fix heading level',
					array(
						'block_change' => array(
							'type'       => 'set_attributes',
							'attributes' => array( 'level' => 3 ),
						),
					)
				),
			)
		);

		$this->assertSame(
			array(
				'type'       => 'set_attributes',
				'attributes' => array( 'level' => 3 ),
				'from'       => array( 'level' => 4 ),
			),
			self::find( $feedback, 'Fix heading level' )['block_change']
		);
	}

	/**
	 * Alt text survives only for an image the AI was shown; otherwise the
	 * note stays, without a fix.
	 */
	public function test_alt_text_only_for_images_the_ai_saw(): void {
		$item = self::item(
			'chart',
			'Add alt text',
			array(
				'block_change' => array(
					'type'       => 'set_attributes',
					'attributes' => array( 'alt' => 'Bar chart of test times' ),
				),
			)
		);

		$text_only = self::find( self::parse( array( $item ) ), 'Add alt text' );
		$this->assertArrayNotHasKey( 'block_change', $text_only );
		$this->assertSame( 'Formatting.', $text_only['feedback'] );

		$vision = self::find( self::parse( array( $item ), array( 'chart' ) ), 'Add alt text' );
		$this->assertSame( array( 'alt' => 'Bar chart of test times' ), $vision['block_change']['attributes'] );
	}

	/**
	 * A formatting fix replaces the item's quick fix, and a structural edit
	 * on the same item wins over it.
	 */
	public function test_one_change_per_item(): void {
		$list = array(
			'type'  => 'transform',
			'to'    => 'core/list',
			'items' => array( 'Node', 'npm', 'Docker', 'Git' ),
		);

		$feedback = self::parse(
			array(
				self::item(
					'needs',
					'Use a list',
					array(
						'quick_fix'    => array(
							'find'    => 'You need',
							'replace' => 'Install',
						),
						'block_change' => $list,
					)
				),
			)
		);
		$item     = self::find( $feedback, 'Use a list' );
		$this->assertSame( 'core/list', $item['block_change']['to'] );
		$this->assertArrayNotHasKey( 'quick_fix', $item );

		$feedback = self::parse(
			array(
				self::item(
					'needs',
					'Cut it',
					array(
						'block_action' => array( 'type' => 'remove' ),
						'block_change' => $list,
					)
				),
			)
		);
		$item     = self::find( $feedback, 'Cut it' );
		$this->assertSame( array( 'type' => 'remove_block' ), $item['block_action'] );
		$this->assertArrayNotHasKey( 'block_change', $item );
	}

	/**
	 * A block gets one change per review, whichever item proposes it first.
	 */
	public function test_one_change_per_block(): void {
		$feedback = self::parse(
			array(
				self::item(
					'h4',
					'Fix heading level',
					array(
						'block_change' => array(
							'type'       => 'set_attributes',
							'attributes' => array( 'level' => 3 ),
						),
					)
				),
				self::item(
					'h4',
					'Make it a paragraph',
					array(
						'category'     => 'flow',
						'block_change' => array(
							'type' => 'transform',
							'to'   => 'core/paragraph',
						),
					)
				),
				self::item(
					'h4',
					'Remove it',
					array(
						'category'     => 'content',
						'block_action' => array( 'type' => 'remove' ),
					)
				),
			)
		);

		$this->assertArrayHasKey( 'block_change', self::find( $feedback, 'Fix heading level' ) );
		$this->assertArrayNotHasKey( 'block_change', self::find( $feedback, 'Make it a paragraph' ) );
		$this->assertArrayNotHasKey( 'block_action', self::find( $feedback, 'Remove it' ) );
	}

	/**
	 * A change the parser can't check against the block is dropped, and the
	 * feedback kept.
	 */
	public function test_invalid_change_keeps_the_feedback(): void {
		$feedback = self::parse(
			array(
				self::item(
					'needs',
					'Use a list',
					array(
						'block_change' => array(
							'type'  => 'transform',
							'to'    => 'core/list',
							'items' => array( 'Node', 'Python' ),
						),
					)
				),
				self::item(
					'h2',
					'Style it',
					array(
						'block_change' => array(
							'type'       => 'set_attributes',
							'attributes' => array( 'style' => array( 'color' => 'red' ) ),
						),
					)
				),
			)
		);

		$this->assertArrayNotHasKey( 'block_change', self::find( $feedback, 'Use a list' ) );
		$this->assertArrayNotHasKey( 'block_change', self::find( $feedback, 'Style it' ) );
	}
}
