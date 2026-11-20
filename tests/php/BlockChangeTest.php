<?php
/**
 * Tests for formatting fixes (Block_Change).
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use AI_Feedback\Block_Change;

/**
 * Test cases for validating and decoding `block_change`.
 */
class BlockChangeTest extends TestCase {

	/**
	 * A heading block as the parser sees it.
	 *
	 * @param  int $level Heading level.
	 * @return array<string, mixed>
	 */
	private static function heading( int $level = 4 ): array {
		return array(
			'name'       => 'core/heading',
			'content'    => 'Setup',
			'attributes' => array( 'level' => $level ),
		);
	}

	/**
	 * A paragraph block as the parser sees it.
	 *
	 * @param  string $text Paragraph text.
	 * @return array<string, mixed>
	 */
	private static function paragraph( string $text ): array {
		return array(
			'name'       => 'core/paragraph',
			'content'    => $text,
			'attributes' => array(),
		);
	}

	/**
	 * An image block as the parser sees it.
	 *
	 * @param  string $alt     Current alt text.
	 * @param  string $caption Caption text.
	 * @return array<string, mixed>
	 */
	private static function image( string $alt = '', string $caption = '' ): array {
		return array(
			'name'       => 'core/image',
			'content'    => $caption,
			'attributes' => array(
				'alt' => $alt,
				'url' => 'http://example.com/wp-content/uploads/chart.png',
			),
		);
	}

	/**
	 * A skipped heading level gets a fix to the right level, with the level
	 * the AI saw kept as `from`.
	 */
	public function test_heading_level_fix(): void {
		$change = Block_Change::validate(
			array(
				'type'       => 'set_attributes',
				'attributes' => array( 'level' => 3 ),
			),
			self::heading( 4 )
		);

		$this->assertSame(
			array(
				'type'       => 'set_attributes',
				'attributes' => array( 'level' => 3 ),
				'from'       => array( 'level' => 4 ),
			),
			$change
		);
	}

	/**
	 * Data provider for `set_attributes` changes the parser drops.
	 *
	 * @return array<string, array{0: mixed, 1: array<string, mixed>}>
	 */
	public static function dropped_attribute_provider(): array {
		return array(
			'attribute off the allow-list'        => array( array( 'className' => 'big' ), self::heading() ),
			'allowed with one off the allow-list' => array(
				array(
					'level'     => 3,
					'className' => 'big',
				),
				self::heading(),
			),
			'another block type\'s attribute'     => array( array( 'ordered' => true ), self::heading() ),
			'level 1'                             => array( array( 'level' => 1 ), self::heading() ),
			'level 7'                             => array( array( 'level' => 7 ), self::heading() ),
			'level as a string'                   => array( array( 'level' => '3' ), self::heading() ),
			'level it already has'                => array( array( 'level' => 4 ), self::heading( 4 ) ),
			'empty attributes'                    => array( array(), self::heading() ),
			'attributes not an object'            => array( 'level', self::heading() ),
			'attribute on a paragraph'            => array( array( 'level' => 3 ), self::paragraph( 'Text.' ) ),
			'list type it already has'            => array(
				array( 'ordered' => false ),
				array(
					'name'       => 'core/list',
					'content'    => 'One Two',
					'attributes' => array( 'ordered' => false ),
				),
			),
		);
	}

	/**
	 * Attributes outside the allow-list, or that change nothing, drop the
	 * whole change.
	 *
	 * @param mixed                $attributes Proposed attributes.
	 * @param array<string, mixed> $block      Block.
	 */
	#[DataProvider( 'dropped_attribute_provider' )]
	public function test_drops_attributes_off_the_allow_list( mixed $attributes, array $block ): void {
		$this->assertNull(
			Block_Change::validate(
				array(
					'type'       => 'set_attributes',
					'attributes' => $attributes,
				),
				$block,
				true
			)
		);
	}

	/**
	 * A heading sent without its level is read as the default level 2, so
	 * proposing level 2 changes nothing and is dropped.
	 */
	public function test_missing_level_reads_as_two(): void {
		$heading = array(
			'name'       => 'core/heading',
			'content'    => 'Setup',
			'attributes' => array(),
		);
		$set     = static fn ( int $level ): array => array(
			'type'       => 'set_attributes',
			'attributes' => array( 'level' => $level ),
		);

		$this->assertNull( Block_Change::validate( $set( 2 ), $heading ) );
		$this->assertSame( array( 'level' => 2 ), Block_Change::validate( $set( 3 ), $heading )['from'] ?? null );
	}

	/**
	 * A list can switch between bulleted and numbered.
	 */
	public function test_list_type_fix(): void {
		$change = Block_Change::validate(
			array(
				'type'       => 'set_attributes',
				'attributes' => array( 'ordered' => true ),
			),
			array(
				'name'       => 'core/list',
				'content'    => 'First Then',
				'attributes' => array( 'ordered' => false ),
			)
		);

		$this->assertSame( array( 'ordered' => true ), $change['attributes'] ?? null );
		$this->assertSame( array( 'ordered' => false ), $change['from'] ?? null );
	}

	/**
	 * Alt text is only proposed when the AI saw the image, and the image has
	 * none.
	 */
	public function test_alt_text_needs_the_image(): void {
		$change = array(
			'type'       => 'set_attributes',
			'attributes' => array( 'alt' => '  Bar chart of   test times ' ),
		);

		$this->assertNull( Block_Change::validate( $change, self::image(), false ) );
		$this->assertNull( Block_Change::validate( $change, self::image( 'A chart' ), true ) );
		$this->assertSame(
			array(
				'type'       => 'set_attributes',
				'attributes' => array( 'alt' => 'Bar chart of test times' ),
				'from'       => array( 'alt' => '' ),
				'image'      => 'http://example.com/wp-content/uploads/chart.png',
			),
			Block_Change::validate( $change, self::image(), true )
		);
	}

	/**
	 * Alt text and captions are untrusted plain text: markup and overlong
	 * text drop the change.
	 */
	public function test_alt_text_and_caption_are_plain_text(): void {
		$set = static fn ( array $attributes ): array => array(
			'type'       => 'set_attributes',
			'attributes' => $attributes,
		);

		$this->assertNull( Block_Change::validate( $set( array( 'alt' => 'A <script>chart</script>' ) ), self::image(), true ) );
		$this->assertNull( Block_Change::validate( $set( array( 'alt' => str_repeat( 'é', Block_Change::MAX_ALT_LENGTH + 1 ) ) ), self::image(), true ) );
		$this->assertNotNull( Block_Change::validate( $set( array( 'alt' => str_repeat( 'é', Block_Change::MAX_ALT_LENGTH ) ) ), self::image(), true ) );
		$this->assertNull( Block_Change::validate( $set( array( 'alt' => '   ' ) ), self::image(), true ) );
		$this->assertNull( Block_Change::validate( $set( array( 'caption' => 'A <b>chart</b>' ) ), self::image(), true ) );
	}

	/**
	 * A caption is only added to an image without one.
	 */
	public function test_caption_only_where_there_is_none(): void {
		$change = array(
			'type'       => 'set_attributes',
			'attributes' => array( 'caption' => 'Test times, before and after.' ),
		);

		$this->assertNull( Block_Change::validate( $change, self::image( '', 'Existing caption' ), true ) );
		$this->assertNull( Block_Change::validate( $change, self::image(), false ) );
		$this->assertSame(
			array( 'caption' => 'Test times, before and after.' ),
			Block_Change::validate( $change, self::image(), true )['attributes'] ?? null
		);
	}

	/**
	 * Data provider for paragraphs whose items match.
	 *
	 * @return array<string, array{0: string, 1: list<string>, 2: string}>
	 */
	public static function list_match_provider(): array {
		return array(
			'commas and "and"'     => array( 'You need Node, npm, Docker and Git.', array( 'Node', 'npm', 'Docker', 'Git' ), 'You need' ),
			'Oxford comma'         => array( 'Node, npm, and Docker', array( 'Node', 'npm', 'Docker' ), '' ),
			'semicolons'           => array( 'Bring: a tent; a stove; two mugs.', array( 'a tent', 'a stove', 'two mugs' ), 'Bring:' ),
			'"or"'                 => array( 'Pick tea or coffee', array( 'tea', 'coffee' ), 'Pick' ),
			'accented items'       => array( 'Café, crème, brûlée', array( 'Café', 'crème', 'brûlée' ), '' ),
			'whitespace collapsed' => array( "Node,\n   npm", array( 'Node', 'npm' ), '' ),
		);
	}

	/**
	 * A paragraph of separated items becomes a list, with any text before
	 * the first item kept.
	 *
	 * @param string       $text  Paragraph text.
	 * @param list<string> $items Items.
	 * @param string       $lead  Expected introduction.
	 */
	#[DataProvider( 'list_match_provider' )]
	public function test_paragraph_to_list( string $text, array $items, string $lead ): void {
		$change = Block_Change::validate(
			array(
				'type'  => 'transform',
				'to'    => 'core/list',
				'items' => $items,
			),
			self::paragraph( $text )
		);

		$this->assertSame(
			array(
				'type'    => 'transform',
				'to'      => 'core/list',
				'items'   => $items,
				'ordered' => false,
			),
			$change
		);
		$this->assertSame( array( 'lead' => $lead ), Block_Change::match_list_items( Block_Change::normalize( $text ), $items ) );
	}

	/**
	 * Data provider for items that would add, drop or reorder text.
	 *
	 * @return array<string, array{0: string, 1: mixed}>
	 */
	public static function list_mismatch_provider(): array {
		return array(
			'item not in the text'      => array( 'Node, npm and Docker', array( 'Node', 'npm', 'Docker Compose' ) ),
			'items out of order'        => array( 'Node, npm and Docker', array( 'npm', 'Node', 'Docker' ) ),
			'words dropped between'     => array( 'Node, then npm', array( 'Node', 'npm' ) ),
			'text dropped after'        => array( 'Node and npm are needed.', array( 'Node', 'npm' ) ),
			'item starts inside a word' => array( 'Nodes, npm', array( 'odes', 'npm' ) ),
			'item ends inside a word'   => array( 'Node, npmx, Git', array( 'Node', 'npm', 'Git' ) ),
			'one item'                  => array( 'Node, npm', array( 'Node' ) ),
			'markup in an item'         => array( 'Node, npm', array( '<b>Node</b>', 'npm' ) ),
			'items not strings'         => array( 'Node, npm', array( 'Node', 2 ) ),
			'empty item'                => array( 'Node, npm', array( 'Node', ' ', 'npm' ) ),
			'items not a list'          => array( 'Node, npm', 'Node, npm' ),
		);
	}

	/**
	 * Items that don't split the paragraph's own text drop the change.
	 *
	 * @param string $text  Paragraph text.
	 * @param mixed  $items Items.
	 */
	#[DataProvider( 'list_mismatch_provider' )]
	public function test_paragraph_to_list_mismatch( string $text, mixed $items ): void {
		$this->assertNull(
			Block_Change::validate(
				array(
					'type'  => 'transform',
					'to'    => 'core/list',
					'items' => $items,
				),
				self::paragraph( $text )
			)
		);
	}

	/**
	 * Too many items drop the change.
	 */
	public function test_paragraph_to_list_item_limit(): void {
		$items = array_map( static fn ( int $n ): string => 'item' . $n, range( 1, 21 ) );

		$this->assertNull(
			Block_Change::validate(
				array(
					'type'  => 'transform',
					'to'    => 'core/list',
					'items' => $items,
				),
				self::paragraph( implode( ', ', $items ) )
			)
		);
	}

	/**
	 * Only transforms on the allow-list are kept.
	 */
	public function test_transform_allow_list(): void {
		$transform = static fn ( string $to, array $extra = array() ): array => array(
			'type' => 'transform',
			'to'   => $to,
		) + $extra;

		$this->assertSame(
			array(
				'type'  => 'transform',
				'to'    => 'core/heading',
				'level' => 3,
				'text'  => 'Getting started',
			),
			Block_Change::validate( $transform( 'core/heading', array( 'level' => 3 ) ), self::paragraph( 'Getting started' ) )
		);
		$this->assertSame(
			array(
				'type' => 'transform',
				'to'   => 'core/paragraph',
				'text' => 'Setup',
			),
			Block_Change::validate( $transform( 'core/paragraph' ), self::heading() )
		);
		$this->assertNull( Block_Change::validate( $transform( 'core/quote' ), self::paragraph( 'Text.' ) ) );
		$this->assertNull( Block_Change::validate( $transform( 'core/list' ), self::heading() ) );
		$this->assertNull( Block_Change::validate( $transform( 'core/heading', array( 'level' => 1 ) ), self::paragraph( 'Title' ) ) );
		$this->assertNull( Block_Change::validate( $transform( 'core/heading' ), self::paragraph( str_repeat( 'a', Block_Change::MAX_HEADING_LENGTH + 1 ) ) ) );
	}

	/**
	 * A long paragraph splits after a sentence that appears once.
	 */
	public function test_split(): void {
		$text = 'The first idea ends here. The second idea starts here and goes on.';

		$this->assertSame(
			array(
				'type'  => 'split',
				'after' => 'idea ends here.',
				'next'  => 'The second idea starts here and goes on.',
			),
			Block_Change::validate(
				array(
					'type'  => 'split',
					'after' => 'idea ends here.',
				),
				self::paragraph( $text )
			)
		);
	}

	/**
	 * Data provider for splits the parser drops.
	 *
	 * @return array<string, array{0: string, 1: mixed}>
	 */
	public static function bad_split_provider(): array {
		return array(
			'not in the text'         => array( 'One. Two.', 'Three.' ),
			'more than once'          => array( 'Yes. No. Yes. No.', 'Yes.' ),
			'not a sentence end'      => array( 'One idea here. Two.', 'One idea' ),
			'at the end of the block' => array( 'One. Two.', 'Two.' ),
			'inside a word'           => array( 'Version 1.2 ships. Then more.', 'Version 1.' ),
			'markup'                  => array( 'One. Two.', '<b>One.</b>' ),
			'not a string'            => array( 'One. Two.', 1 ),
		);
	}

	/**
	 * A split at no clear sentence boundary is dropped.
	 *
	 * @param string $text  Paragraph text.
	 * @param mixed  $after Proposed split point.
	 */
	#[DataProvider( 'bad_split_provider' )]
	public function test_bad_split( string $text, mixed $after ): void {
		$this->assertNull(
			Block_Change::validate(
				array(
					'type'  => 'split',
					'after' => $after,
				),
				self::paragraph( $text )
			)
		);
	}

	/**
	 * Only paragraphs split.
	 */
	public function test_split_only_paragraphs(): void {
		$this->assertNull(
			Block_Change::validate(
				array(
					'type'  => 'split',
					'after' => 'One.',
				),
				array(
					'name'       => 'core/quote',
					'content'    => 'One. Two.',
					'attributes' => array(),
				)
			)
		);
	}

	/**
	 * Unknown change types are dropped.
	 */
	public function test_unknown_type(): void {
		$this->assertNull( Block_Change::validate( array( 'type' => 'set_style' ), self::paragraph( 'Text.' ) ) );
		$this->assertNull( Block_Change::validate( 'split', self::paragraph( 'Text.' ) ) );
	}

	/**
	 * Only the attributes formatting fixes use are read from a request block.
	 */
	public function test_get_block_attributes(): void {
		$this->assertSame(
			array( 'level' => 3 ),
			Block_Change::get_block_attributes(
				array(
					'name'       => 'core/heading',
					'attributes' => array(
						'level'     => 3,
						'className' => 'x',
					),
				)
			)
		);
		$this->assertSame(
			array(
				'alt' => '',
				'id'  => 7,
				'url' => 'https://example.com/a.png',
			),
			Block_Change::get_block_attributes(
				array(
					'name'       => 'core/image',
					'attributes' => array(
						'alt' => '',
						'id'  => 7,
						'url' => 'https://example.com/a.png',
					),
				)
			)
		);
		$this->assertSame(
			array(),
			Block_Change::get_block_attributes(
				array(
					'name'       => 'core/heading',
					'attributes' => array( 'level' => '9' ),
				)
			)
		);
		$this->assertSame( array(), Block_Change::get_block_attributes( 'block' ) );
	}

	/**
	 * Data provider for stored changes.
	 *
	 * @return array<string, array{0: mixed, 1: string, 2: array<string, mixed>|null}>
	 */
	public static function stored_provider(): array {
		$level = array(
			'type'       => 'set_attributes',
			'attributes' => array( 'level' => 3 ),
			'from'       => array( 'level' => 4 ),
		);
		$list  = array(
			'type'    => 'transform',
			'to'      => 'core/list',
			'items'   => array( 'Node', 'npm' ),
			'ordered' => false,
		);
		return array(
			'heading level'                     => array( wp_json_encode( $level ), 'core/heading', $level ),
			'heading level on another block'    => array( wp_json_encode( $level ), 'core/paragraph', null ),
			'list transform'                    => array( wp_json_encode( $list ), 'core/paragraph', $list ),
			'list transform from a heading'     => array( wp_json_encode( $list ), 'core/heading', null ),
			'split'                             => array(
				'{"type":"split","after":"One.","next":"Two."}',
				'',
				array(
					'type'  => 'split',
					'after' => 'One.',
					'next'  => 'Two.',
				),
			),
			'split without next'                => array( '{"type":"split","after":"One."}', '', null ),
			'alt without its image'             => array( '{"type":"set_attributes","attributes":{"alt":"A chart"},"from":{"alt":""}}', 'core/image', null ),
			'heading without its text'          => array( '{"type":"transform","to":"core/paragraph"}', 'core/heading', null ),
			'attribute off the allow-list'      => array( '{"type":"set_attributes","attributes":{"className":"x"},"from":{"className":""}}', '', null ),
			'missing from'                      => array( '{"type":"set_attributes","attributes":{"level":3}}', 'core/heading', null ),
			'alt with markup'                   => array( '{"type":"set_attributes","attributes":{"alt":"<b>x</b>"},"from":{"alt":""}}', 'core/image', null ),
			'transform off the allow-list'      => array( '{"type":"transform","to":"core/quote"}', '', null ),
			'extra fields are dropped'          => array(
				'{"type":"transform","to":"core/paragraph","text":"Setup","html":"<script>"}',
				'core/heading',
				array(
					'type' => 'transform',
					'to'   => 'core/paragraph',
					'text' => 'Setup',
				),
			),
			'unknown type'                      => array( '{"type":"set_style"}', '', null ),
			'not JSON'                          => array( 'nope', '', null ),
			'empty'                             => array( '', '', null ),
		);
	}

	/**
	 * Stored changes are checked against the allow-lists again when read.
	 *
	 * @param mixed                     $stored     Stored meta.
	 * @param string                    $block_name Note's block name.
	 * @param array<string, mixed>|null $expected   Expected change.
	 */
	#[DataProvider( 'stored_provider' )]
	public function test_decode( mixed $stored, string $block_name, ?array $expected ): void {
		$this->assertSame( $expected, Block_Change::decode( $stored, $block_name ) );
	}
}
