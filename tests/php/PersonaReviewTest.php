<?php
/**
 * Tests for the persona takeaway in review responses and history.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Notes_Manager;
use AI_Feedback\Personas;
use AI_Feedback\Response_Parser;
use AI_Feedback\Review_History;

require_once __DIR__ . '/fixtures/backslash-text.php';

/**
 * Test cases for the persona takeaway.
 */
class PersonaReviewTest extends TestCase {

	use Backslash_Text_Provider;

	/**
	 * Blocks reviewed.
	 *
	 * @var list<array<string, string>>
	 */
	private const BLOCKS = array(
		array(
			'clientId' => 'block-1',
			'name'     => 'core/paragraph',
			'content'  => 'Our plugin is 40% faster.',
		),
	);

	/**
	 * Clean up globals.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['test_current_user_id'], $GLOBALS['test_filter_results'], $GLOBALS['test_comment_meta'], $GLOBALS['test_post_meta'] );
		parent::tearDown();
	}

	/**
	 * The parser returns the persona's takeaway as plain text.
	 */
	public function test_parser_returns_takeaway(): void {
		$response = (string) wp_json_encode(
			array(
				'summary'          => 'Two claims need a source.',
				'persona_takeaway' => 'Not convinced until the benchmark is linked.',
				'feedback'         => array(),
			)
		);

		$parsed = ( new Response_Parser() )->parse_feedback( $response, self::BLOCKS );

		$this->assertIsArray( $parsed );
		$this->assertSame( 'Not convinced until the benchmark is linked.', $parsed['persona_takeaway'] );
	}

	/**
	 * A missing, malformed or long takeaway is handled.
	 */
	public function test_parser_handles_bad_takeaways(): void {
		$parser = new Response_Parser();

		$missing = $parser->parse_feedback( '{"summary":"Fine.","feedback":[]}', self::BLOCKS );
		$this->assertIsArray( $missing );
		$this->assertSame( '', $missing['persona_takeaway'] );

		$not_text = $parser->parse_feedback( '{"summary":"Fine.","persona_takeaway":{"a":1},"feedback":[]}', self::BLOCKS );
		$this->assertIsArray( $not_text );
		$this->assertSame( '', $not_text['persona_takeaway'] );

		$long = $parser->parse_feedback(
			(string) wp_json_encode(
				array(
					'feedback'         => array(),
					'persona_takeaway' => str_repeat( 'b', 400 ),
				)
			),
			self::BLOCKS
		);
		$this->assertIsArray( $long );
		$this->assertIsString( $long['persona_takeaway'] );
		$this->assertSame( Personas::MAX_TAKEAWAY_LENGTH, mb_strlen( $long['persona_takeaway'] ) );
	}

	/**
	 * History keeps the persona and its takeaway, so the summary survives a reload.
	 */
	public function test_history_keeps_persona_takeaway(): void {
		$GLOBALS['test_current_user_id'] = 7;

		$entry = Review_History::build_entry(
			array(
				'review_id'        => 'r-1',
				'persona'          => 'skeptical_expert',
				'persona_label'    => 'Skeptical expert',
				'persona_takeaway' => 'Two claims need a source.',
			)
		);

		$this->assertSame( 'skeptical_expert', $entry['persona'] ?? null );
		$this->assertSame( 'Skeptical expert', $entry['persona_label'] ?? null );
		$this->assertSame( 'Two claims need a source.', $entry['persona_takeaway'] ?? null );
	}

	/**
	 * Once a filter removes a persona, neither its notes nor its review
	 * summary name it.
	 */
	public function test_removed_persona_is_named_nowhere(): void {
		$entry                        = Review_History::build_entry(
			array(
				'review_id'        => 'r-1',
				'persona'          => 'busy_skimmer',
				'persona_label'    => 'Busy skimmer',
				'persona_takeaway' => 'Got it by the second heading.',
			)
		);
		$GLOBALS['test_comment_meta'] = array( 7 => array( Personas::META_KEY => 'busy_skimmer' ) );

		// While the persona exists, both name it.
		$this->assertSame( 'Busy skimmer', Review_History::build_persona_fields( $entry )['persona_label'] ?? null );
		$this->assertSame( 'Busy skimmer', Notes_Manager::get_persona_fields( 7 )['persona_label'] );

		$personas = Personas::get_all();
		unset( $personas['busy_skimmer'] );
		$GLOBALS['test_filter_results'] = array( 'ai_feedback_personas' => $personas );

		$this->assertSame( array(), Review_History::build_persona_fields( $entry ) );
		$this->assertSame( '', Notes_Manager::get_persona_fields( 7 )['persona_label'] );
	}

	/**
	 * A relabelled persona shows its current label in both places.
	 */
	public function test_relabelled_persona_matches_badge(): void {
		$entry                             = Review_History::build_entry(
			array(
				'review_id' => 'r-1',
				'persona'   => 'busy_skimmer',
			)
		);
		$personas                          = Personas::get_all();
		$personas['busy_skimmer']['label'] = 'Skimmer';
		$GLOBALS['test_filter_results']    = array( 'ai_feedback_personas' => $personas );
		$GLOBALS['test_comment_meta']      = array( 7 => array( Personas::META_KEY => 'busy_skimmer' ) );

		$this->assertSame( 'Skimmer', Review_History::build_persona_fields( $entry )['persona_label'] ?? null );
		$this->assertSame( 'Skimmer', Notes_Manager::get_persona_fields( 7 )['persona_label'] );
	}

	/**
	 * A takeaway with backslashes survives the round trip through review
	 * history, since update_post_meta() unslashes the value.
	 *
	 * @dataProvider backslash_text_provider
	 *
	 * @param string $text Takeaway.
	 */
	public function test_takeaway_keeps_backslashes_through_history( string $text ): void {
		$GLOBALS['test_post_meta']       = array();
		$GLOBALS['test_current_user_id'] = 7;

		Review_History::record(
			42,
			array(
				'review_id'        => 'r-1',
				'persona'          => 'skeptical_expert',
				'persona_takeaway' => $text,
			)
		);

		// What get_latest_review() adds back to a review rebuilt from its notes.
		$entry = Review_History::find( 42, 'r-1' );
		$this->assertIsArray( $entry );
		$this->assertSame( $text, Review_History::build_persona_fields( $entry )['persona_takeaway'] ?? null );
	}

	/**
	 * Default persona reviews keep the history entry as it was.
	 */
	public function test_history_leaves_out_default_persona(): void {
		$entry = Review_History::build_entry(
			array(
				'review_id' => 'r-1',
				'persona'   => 'copy_editor',
			)
		);

		$this->assertArrayNotHasKey( 'persona', $entry );
		$this->assertArrayNotHasKey( 'persona_takeaway', $entry );
		$this->assertSame( array(), Review_History::build_persona_fields( array( 'persona' => '<script>' ) ) );
	}
}
