<?php
/**
 * Tests for Grammar & Spelling (proofreading) items in Response_Parser.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Response_Parser;
use AI_Feedback\Notes_Manager;

/**
 * Test cases for the grammar category and quick fixes that share a block.
 */
class ResponseParserGrammarTest extends TestCase {

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
	 * Build a grammar item.
	 *
	 * @param  string     $block_id Block ID.
	 * @param  string     $find     Text to find.
	 * @param  string     $replace  Replacement.
	 * @param  string     $severity Severity.
	 * @return array<string, mixed>
	 */
	private static function grammar( string $block_id, string $find, string $replace, string $severity = 'suggestion' ): array {
		return array(
			'block_id'  => $block_id,
			'category'  => 'grammar',
			'severity'  => $severity,
			'title'     => 'Spelling',
			'feedback'  => 'Typo.',
			'quick_fix' => array(
				'find'    => $find,
				'replace' => $replace,
			),
		);
	}

	/**
	 * Parse feedback items against blocks.
	 *
	 * @param  array<int, array<string, mixed>> $items  Feedback items.
	 * @param  array<string, string>            $blocks Block content keyed by client ID.
	 * @return array<mixed> Parsed feedback.
	 */
	private function parse( array $items, array $blocks ): array {
		$editor_blocks = array();
		foreach ( $blocks as $client_id => $content ) {
			$editor_blocks[] = array(
				'clientId' => $client_id,
				'name'     => 'core/paragraph',
				'content'  => $content,
			);
		}

		$result = $this->parser->parse_feedback(
			(string) wp_json_encode(
				array(
					'summary'  => 'Summary.',
					'feedback' => $items,
				)
			),
			$editor_blocks
		);
		$this->assertIsArray( $result );
		$this->assertIsArray( $result['feedback'] );

		return $result['feedback'];
	}

	/**
	 * Read one parsed item.
	 *
	 * @param  array<mixed> $feedback Parsed feedback.
	 * @param  int|string   $key      Index, or a category to find the first item of.
	 * @return array<mixed> Item.
	 */
	private function item( array $feedback, int|string $key ): array {
		$item = null;
		if ( is_int( $key ) ) {
			$item = $feedback[ $key ] ?? null;
		} else {
			foreach ( $feedback as $candidate ) {
				if ( is_array( $candidate ) && ( $candidate['category'] ?? null ) === $key ) {
					$item = $candidate;
					break;
				}
			}
		}
		$this->assertIsArray( $item );
		return $item;
	}

	/**
	 * The fixes kept, in document order of their find text.
	 *
	 * @param  array<mixed> $feedback Parsed feedback.
	 * @return list<string> Each kept fix as "find>replace".
	 */
	private static function fixes( array $feedback ): array {
		$fixes = array();
		foreach ( $feedback as $item ) {
			$fix = is_array( $item ) ? ( $item['quick_fix'] ?? null ) : null;
			if ( is_array( $fix ) && is_string( $fix['find'] ?? null ) && is_string( $fix['replace'] ?? null ) ) {
				$fixes[] = $fix['find'] . '>' . $fix['replace'];
			}
		}
		sort( $fixes );
		return $fixes;
	}

	/**
	 * Grammar is a valid category, in the parser and in the schema.
	 */
	public function test_grammar_is_a_valid_category(): void {
		$this->assertContains( 'grammar', Response_Parser::VALID_CATEGORIES );
		$this->assertTrue(
			$this->parser->validate_schema(
				array( 'feedback' => array( self::grammar( 'b1', 'teh', 'the' ) ) )
			)
		);
	}

	/**
	 * A grammar item without a usable quick fix is dropped, while other
	 * categories keep the note.
	 */
	public function test_grammar_item_without_a_fix_is_dropped(): void {
		$no_fix = self::grammar( 'b1', 'teh', 'the' );
		unset( $no_fix['quick_fix'] );

		$feedback = $this->parse(
			array(
				$no_fix,
				// Not in the block.
				self::grammar( 'b1', 'recieve', 'receive' ),
				// A missing replacement is not a deletion.
				array(
					'block_id'  => 'b1',
					'category'  => 'grammar',
					'severity'  => 'suggestion',
					'title'     => 'Spelling',
					'feedback'  => 'Typo.',
					'quick_fix' => array( 'find' => 'sat' ),
				),
				// Markup is never a valid fix.
				self::grammar( 'b1', '<b>cat</b>', 'cat' ),
				array(
					'block_id' => 'b1',
					'category' => 'content',
					'severity' => 'important',
					'title'    => 'Add evidence',
					'feedback' => 'Cite a source.',
				),
			),
			array( 'b1' => 'teh cat sat.' )
		);

		$this->assertCount( 1, $feedback );
		$this->assertSame( 'content', $this->item( $feedback, 0 )['category'] );
	}

	/**
	 * Grammar items are always suggestions.
	 */
	public function test_grammar_severity_is_always_suggestion(): void {
		$feedback = $this->parse(
			array( self::grammar( 'b1', 'teh', 'the', 'critical' ) ),
			array( 'b1' => 'teh cat' )
		);

		$this->assertCount( 1, $feedback );
		$this->assertSame( 'suggestion', $this->item( $feedback, 0 )['severity'] );
	}

	/**
	 * Two typos in one block are two items, each keeping its fix, and
	 * matching titles never group grammar items across blocks.
	 */
	public function test_grammar_items_never_group(): void {
		$feedback = $this->parse(
			array(
				self::grammar( 'b1', 'teh', 'the' ),
				self::grammar( 'b1', 'alot', 'a lot' ),
				self::grammar( 'b2', 'recieve', 'receive' ),
			),
			array(
				'b1' => 'teh cat ate alot.',
				'b2' => 'You recieve it.',
			)
		);

		$this->assertCount( 3, $feedback );
		foreach ( $feedback as $item ) {
			$this->assertIsArray( $item );
			$this->assertFalse( $item['is_group'] );
			$this->assertSame( 'Spelling', $item['title'] );
		}
		$this->assertSame( array( 'alot>a lot', 'recieve>receive', 'teh>the' ), self::fixes( $feedback ) );
	}

	/**
	 * A typo repeated in one block needs a wider find for each copy; a bare
	 * word that matches twice is dropped rather than applied to the wrong one.
	 */
	public function test_repeated_typo_needs_a_unique_find(): void {
		$feedback = $this->parse(
			array(
				self::grammar( 'b1', 'teh', 'the' ),
				self::grammar( 'b1', 'teh cat', 'the cat' ),
				self::grammar( 'b1', 'teh mat', 'the mat' ),
			),
			array( 'b1' => 'teh cat sat on teh mat.' )
		);

		$this->assertSame( array( 'teh cat>the cat', 'teh mat>the mat' ), self::fixes( $feedback ) );
	}

	/**
	 * A fix whose text overlaps a kept fix on the same block is dropped.
	 */
	public function test_overlapping_fixes_keep_the_first(): void {
		$feedback = $this->parse(
			array(
				self::grammar( 'b1', 'teh cat', 'the cat' ),
				self::grammar( 'b1', 'cat sat', 'cat sits' ),
			),
			array( 'b1' => 'teh cat sat.' )
		);

		$this->assertSame( array( 'teh cat>the cat' ), self::fixes( $feedback ) );
	}

	/**
	 * A fix that would make another fix's text ambiguous is dropped, in
	 * whichever order they came.
	 *
	 * @dataProvider ambiguous_order_provider
	 *
	 * @param list<array{0: string, 1: string}> $fixes Fixes in review order.
	 * @param list<string>                      $kept  Fixes expected to survive.
	 */
	public function test_fix_that_makes_another_ambiguous_is_dropped( array $fixes, array $kept ): void {
		$items = array();
		foreach ( $fixes as $fix ) {
			$items[] = self::grammar( 'b1', $fix[0], $fix[1] );
		}

		$feedback = $this->parse( $items, array( 'b1' => 'A dgo and a cat.' ) );

		$this->assertSame( $kept, self::fixes( $feedback ) );
	}

	/**
	 * Fixes where applying "dgo" → "cat" makes "cat" appear twice.
	 *
	 * @return array<string, array{0: list<array{0: string, 1: string}>, 1: list<string>}>
	 */
	public static function ambiguous_order_provider(): array {
		return array(
			'creating fix first' => array(
				array( array( 'dgo', 'cat' ), array( 'cat', 'kitten' ) ),
				array( 'dgo>cat' ),
			),
			'affected fix first' => array(
				array( array( 'cat', 'kitten' ), array( 'dgo', 'cat' ) ),
				array( 'cat>kitten' ),
			),
		);
	}

	/**
	 * A fix that would stop another fix's text matching, here by joining it
	 * to the next word, is dropped.
	 */
	public function test_fix_that_breaks_another_fix_match_is_dropped(): void {
		$feedback = $this->parse(
			array(
				self::grammar( 'b1', ',', 's' ),
				self::grammar( 'b1', 'cat', 'kitten' ),
			),
			array( 'b1' => 'cat, dog.' )
		);

		$this->assertSame( array( ',>s' ), self::fixes( $feedback ) );
	}

	/**
	 * Fixes that each work with every other one alone, but not all together,
	 * keep only those that do: applying "a" and "-b" together writes a
	 * second "!?".
	 */
	public function test_fix_checked_against_all_kept_fixes(): void {
		$feedback = $this->parse(
			array(
				self::grammar( 'b1', '!?', '?!' ),
				self::grammar( 'b1', 'a', '!' ),
				self::grammar( 'b1', '-b', '?' ),
			),
			array( 'b1' => '!? a-b' )
		);

		$this->assertSame( array( '!?>?!', 'a>!' ), self::fixes( $feedback ) );
	}

	/**
	 * Fixes are located by Unicode characters, so multibyte text before or
	 * between them doesn't throw the checks off.
	 */
	public function test_unicode_text_around_fixes(): void {
		$feedback = $this->parse(
			array(
				self::grammar( 'b1', 'teh', 'the' ),
				self::grammar( 'b1', 'crème brulée', 'crème brûlée' ),
				self::grammar( 'b1', 'naïve', 'naive' ),
			),
			array( 'b1' => 'Café: teh crème brulée is naïve.' )
		);

		$this->assertSame(
			array( 'crème brulée>crème brûlée', 'naïve>naive', 'teh>the' ),
			self::fixes( $feedback )
		);
	}

	/**
	 * Each block keeps at most MAX_FIXES_PER_BLOCK fixes.
	 */
	public function test_fixes_per_block_are_capped(): void {
		$words = array();
		$items = array();
		for ( $i = 1; $i <= Response_Parser::MAX_FIXES_PER_BLOCK + 3; $i++ ) {
			$words[] = 'w' . $i;
			$items[] = self::grammar( 'b1', 'w' . $i, 'v' . $i );
		}

		$feedback = $this->parse( $items, array( 'b1' => implode( ' ', $words ) ) );

		$this->assertCount( Response_Parser::MAX_FIXES_PER_BLOCK, $feedback );
	}

	/**
	 * A block with a structural edit or formatting fix gets no other change,
	 * so its grammar items are dropped, and a grammar item's own block_action
	 * is ignored.
	 */
	public function test_block_edits_win_over_grammar_fixes(): void {
		$feedback = $this->parse(
			array(
				self::grammar( 'b1', 'teh', 'the' ),
				array(
					'block_id'     => 'b1',
					'category'     => 'flow',
					'severity'     => 'important',
					'title'        => 'Cut this',
					'feedback'     => 'It repeats.',
					'block_action' => array( 'type' => 'remove' ),
				),
				self::grammar( 'b2', 'recieve', 'receive' ) + array( 'block_action' => array( 'type' => 'remove' ) ),
			),
			array(
				'b1' => 'teh cat',
				'b2' => 'You recieve it.',
			)
		);

		$this->assertCount( 2, $feedback );
		$grammar = $this->item( $feedback, 'grammar' );
		$this->assertSame( array( 'type' => 'remove_block' ), $this->item( $feedback, 'flow' )['block_action'] );
		$this->assertSame( 'b2', $grammar['block_id'] );
		$this->assertArrayNotHasKey( 'block_action', $grammar );
		$this->assertSame( array( 'recieve>receive' ), self::fixes( array( $grammar ) ) );
	}

	/**
	 * Parse items against one heading.
	 *
	 * @param  array<int, array<string, mixed>> $items Feedback items.
	 * @return array<mixed> Parsed feedback.
	 */
	private function parse_heading( array $items ): array {
		$result = $this->parser->parse_feedback(
			(string) wp_json_encode(
				array(
					'summary'  => 'Summary.',
					'feedback' => $items,
				)
			),
			array(
				array(
					'clientId'   => 'h1',
					'name'       => 'core/heading',
					'content'    => 'Instalation steps',
					'attributes' => array( 'level' => 4 ),
				),
			)
		);
		$this->assertIsArray( $result );
		$this->assertIsArray( $result['feedback'] );
		return $result['feedback'];
	}

	/**
	 * A heading level fix leaves the text alone, so a typo fix stays beside
	 * it, while other quick fixes on the block still give way as before.
	 */
	public function test_grammar_fix_stays_beside_a_set_attributes_change(): void {
		$level = array(
			'block_id'     => 'h1',
			'category'     => 'design',
			'severity'     => 'suggestion',
			'title'        => 'Fix heading level',
			'feedback'     => 'Skips a level.',
			'block_change' => array(
				'type'       => 'set_attributes',
				'attributes' => array( 'level' => 3 ),
			),
		);
		$content = array(
			'block_id'  => 'h1',
			'category'  => 'content',
			'severity'  => 'suggestion',
			'title'     => 'Shorter',
			'feedback'  => 'Shorter.',
			'quick_fix' => array(
				'find'    => 'steps',
				'replace' => 'guide',
			),
		);

		$feedback = $this->parse_heading( array( $level, self::grammar( 'h1', 'Instalation', 'Installation' ), $content ) );

		$this->assertCount( 3, $feedback );
		$this->assertSame( array( 'Instalation>Installation' ), self::fixes( $feedback ) );
		$change = $this->item( $feedback, 'design' )['block_change'] ?? null;
		$this->assertIsArray( $change );
		$this->assertSame( 'set_attributes', $change['type'] ?? null );
	}

	/**
	 * A change that rewrites or removes the block drops its typo items.
	 */
	public function test_grammar_item_dropped_beside_a_rewriting_change(): void {
		$feedback = $this->parse_heading(
			array(
				array(
					'block_id'     => 'h1',
					'category'     => 'design',
					'severity'     => 'suggestion',
					'title'        => 'Make it a paragraph',
					'feedback'     => 'Body text.',
					'block_change' => array(
						'type' => 'transform',
						'to'   => 'core/paragraph',
					),
				),
				self::grammar( 'h1', 'Instalation', 'Installation' ),
			)
		);

		$this->assertCount( 1, $feedback );
		$this->assertSame( 'design', $this->item( $feedback, 0 )['category'] );
	}

	/**
	 * A content quick fix and a grammar fix that overlap on a block keep the
	 * earlier one; the content note stays without its fix.
	 */
	public function test_other_quick_fixes_share_the_conflict_rules(): void {
		$feedback = $this->parse(
			array(
				array(
					'block_id'  => 'b1',
					'category'  => 'content',
					'severity'  => 'suggestion',
					'title'     => 'Cut filler',
					'feedback'  => 'Shorter.',
					'quick_fix' => array(
						'find'    => 'in order teh',
						'replace' => 'to',
					),
				),
				self::grammar( 'b1', 'teh', 'to' ),
			),
			array( 'b1' => 'We met in order teh plan.' )
		);

		$this->assertCount( 1, $feedback );
		$this->assertSame( 'content', $this->item( $feedback, 0 )['category'] );
		$this->assertSame( array( 'in order teh>to' ), self::fixes( $feedback ) );
	}

	/**
	 * Build a non-grammar item with a quick fix.
	 *
	 * @param  string $category Category.
	 * @param  string $severity Severity.
	 * @param  string $find     Text to find.
	 * @param  string $replace  Replacement.
	 * @return array<string, mixed>
	 */
	private static function other( string $category, string $severity, string $find, string $replace ): array {
		return array(
			'block_id'  => 'b1',
			'category'  => $category,
			'severity'  => $severity,
			'title'     => ucfirst( $category ) . ' note',
			'feedback'  => 'Because.',
			'quick_fix' => array(
				'find'    => $find,
				'replace' => $replace,
			),
		);
	}

	/**
	 * Overlapping fixes from other categories both keep their fix, as they
	 * did before proofreading: the author picks one, and the editor shows
	 * the other as outdated.
	 */
	public function test_other_categories_keep_overlapping_fixes(): void {
		$feedback = $this->parse(
			array(
				self::other( 'content', 'important', 'very unique', 'unique' ),
				self::other( 'tone', 'suggestion', 'very unique idea', 'novel idea' ),
			),
			array( 'b1' => 'This is a very unique idea for teh team.' )
		);

		$this->assertSame( array( 'very unique idea>novel idea', 'very unique>unique' ), self::fixes( $feedback ) );
	}

	/**
	 * A typo listed first never takes the fix from another category's
	 * item: the grammar item yields and is dropped.
	 */
	public function test_grammar_fix_yields_to_a_later_content_fix(): void {
		$feedback = $this->parse(
			array(
				self::grammar( 'b1', 'idea for teh', 'idea for the' ),
				self::other( 'content', 'critical', 'very unique idea', 'new idea' ),
			),
			array( 'b1' => 'This is a very unique idea for teh team.' )
		);

		$this->assertCount( 1, $feedback );
		$this->assertSame( 'content', $this->item( $feedback, 0 )['category'] );
		$this->assertSame( array( 'very unique idea>new idea' ), self::fixes( $feedback ) );
	}

	/**
	 * Proofreading items count by category but not by severity.
	 */
	public function test_summary_leaves_grammar_out_of_severity_counts(): void {
		$summary = $this->parser->get_feedback_summary(
			array(
				array(
					'category' => 'grammar',
					'severity' => 'critical',
				),
				array(
					'category' => 'grammar',
					'severity' => 'suggestion',
				),
				array(
					'category' => 'content',
					'severity' => 'important',
				),
			)
		);

		$this->assertSame( 3, $summary['total_notes'] );
		$this->assertSame( 2, $summary['by_category']['grammar'] );
		$this->assertSame( 1, $summary['by_category']['content'] );
		$this->assertSame(
			array(
				'suggestion' => 0,
				'important'  => 1,
				'critical'   => 0,
			),
			$summary['by_severity']
		);
		$this->assertFalse( $summary['has_critical'] );
	}

	/**
	 * A reloaded review counts the same way.
	 */
	public function test_reloaded_review_summary_leaves_grammar_out_of_severity_counts(): void {
		$method = new \ReflectionMethod( Notes_Manager::class, 'calculate_summary' );

		$summary = $method->invoke(
			new Notes_Manager(),
			array(
				array(
					'parent'   => 0,
					'category' => 'grammar',
					'severity' => 'suggestion',
				),
				array(
					'parent'   => 0,
					'category' => 'tone',
					'severity' => 'suggestion',
				),
			)
		);

		$this->assertSame(
			array(
				'by_severity' => array(
					'critical'   => 0,
					'important'  => 0,
					'suggestion' => 1,
				),
				'by_category' => array(
					'content' => 0,
					'tone'    => 1,
					'flow'    => 0,
					'design'  => 0,
					'grammar' => 1,
				),
			),
			$summary
		);
	}
}
