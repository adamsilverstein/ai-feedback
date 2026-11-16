<?php
/**
 * Tests for block anchors in Response_Parser.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Response_Parser;

/**
 * Test cases for carrying block anchors onto feedback items.
 */
class ResponseParserAnchorTest extends TestCase {

	/**
	 * Parse one feedback item for each block.
	 *
	 * @param  array<int, array<string, string>> $blocks Blocks sent for review.
	 * @return array<string, array<string, mixed>> Feedback items keyed by block ID.
	 */
	private function parse( array $blocks ): array {
		$feedback = array();
		foreach ( $blocks as $block ) {
			$feedback[] = array(
				'block_id' => $block['clientId'],
				'category' => 'tone',
				'severity' => 'suggestion',
				'title'    => 'Title for ' . $block['clientId'],
				'feedback' => 'Feedback.',
			);
		}

		$response = wp_json_encode(
			array(
				'summary'  => 'Summary.',
				'feedback' => $feedback,
			)
		);
		$this->assertIsString( $response );

		$result = ( new Response_Parser() )->parse_feedback( $response, $blocks );
		$this->assertIsArray( $result );

		/**
		 * Parsed feedback items.
		 *
		 * @var array<int, array<string, mixed>> $items
		 */
		$items = $result['feedback'];

		/**
		 * Feedback items keyed by block ID.
		 *
		 * @var array<string, array<string, mixed>> $by_block
		 */
		$by_block = array_column( $items, null, 'block_id' );

		return $by_block;
	}

	/**
	 * A well-formed anchor is kept and a malformed one is dropped.
	 */
	public function test_keeps_valid_anchors_only(): void {
		$items = $this->parse(
			array(
				array(
					'clientId' => 'block-1',
					'name'     => 'core/paragraph',
					'content'  => 'First.',
					'anchor'   => '1x2y3z',
				),
				array(
					'clientId' => 'block-2',
					'name'     => 'core/paragraph',
					'content'  => 'Second.',
					'anchor'   => '<script>',
				),
				array(
					'clientId' => 'block-3',
					'name'     => 'core/paragraph',
					'content'  => 'Third.',
				),
			)
		);

		$this->assertSame( '1x2y3z', $items['block-1']['block_anchor'] );
		$this->assertArrayNotHasKey( 'block_anchor', $items['block-2'] );
		$this->assertArrayNotHasKey( 'block_anchor', $items['block-3'] );
	}
}
