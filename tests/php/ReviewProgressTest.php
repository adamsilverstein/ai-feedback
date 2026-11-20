<?php
/**
 * Tests for comparing a review with the one before it.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Notes_Manager;
use AI_Feedback\Review_History;
use AI_Feedback\Review_Progress;

/**
 * Review progress tests.
 */
class ReviewProgressTest extends TestCase {

	/**
	 * Post the notes belong to.
	 *
	 * @var int
	 */
	private const POST_ID = 10;

	/**
	 * Load the fake comment API and reset it.
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once __DIR__ . '/fixtures/fake-comments.php';

		\ai_feedback_test_reset_comments(); // @phpstan-ignore function.notFound (defined in the fixture above)
		$GLOBALS['test_post_meta']        = array();
		$GLOBALS['test_current_user_id']  = 7;
		$GLOBALS['test_current_user_can'] = true;
	}

	/**
	 * Reset globals.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['test_current_user_can'], $GLOBALS['test_filter_results'] );
		parent::tearDown();
	}

	/**
	 * Create an AI feedback note.
	 *
	 * @param  string               $review_id Review ID.
	 * @param  int                  $parent    Parent note ID.
	 * @param  array<string, mixed> $meta      Extra meta.
	 * @param  int                  $post_id   Post ID.
	 * @return int Note ID.
	 */
	private function add_note( string $review_id, int $parent = 0, array $meta = array(), int $post_id = self::POST_ID ): int {
		$id = wp_insert_comment(
			array(
				'comment_post_ID' => $post_id,
				'comment_type'    => 'note',
				'comment_parent'  => $parent,
				'comment_meta'    => array(
					'ai_feedback' => '1',
					'review_id'   => $review_id,
				) + $meta,
			)
		);

		$this->assertIsInt( $id );

		return $id;
	}

	/**
	 * Get a stored note.
	 *
	 * @param  int $id Note ID.
	 * @return \WP_Comment
	 */
	private function note( int $id ): \WP_Comment {
		$note = get_comment( $id );
		$this->assertInstanceOf( \WP_Comment::class, $note );

		return $note;
	}

	/**
	 * A plain open note is not addressed.
	 */
	public function test_open_note_is_not_addressed(): void {
		$this->assertFalse( Review_Progress::is_addressed( $this->note( $this->add_note( 'r1' ) ) ) );
	}

	/**
	 * Dismissing a note in the sidebar addresses it.
	 */
	public function test_dismissed_note_is_addressed(): void {
		$id = $this->add_note( 'r1', 0, array( 'ai_feedback_resolved' => '1' ) );

		$this->assertTrue( Review_Progress::is_addressed( $this->note( $id ) ) );
	}

	/**
	 * Accepting or rejecting a suggested edit addresses the note; a pending
	 * or outdated one does not.
	 *
	 * @dataProvider data_suggestion_statuses
	 *
	 * @param string $status    Suggestion status.
	 * @param bool   $addressed Whether the note counts as addressed.
	 */
	public function test_suggestion_status( string $status, bool $addressed ): void {
		$id = $this->add_note(
			'r1',
			0,
			array(
				'_wp_suggestion'        => '{"type":"replace"}',
				'_wp_suggestion_status' => $status,
			)
		);

		$this->assertSame( $addressed, Review_Progress::is_addressed( $this->note( $id ) ) );
	}

	/**
	 * Suggestion statuses.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function data_suggestion_statuses(): array {
		return array(
			'applied'          => array( 'applied', true ),
			'applied unsaved'  => array( 'applied-unsaved', true ),
			'rejected'         => array( 'rejected', true ),
			'rejected unsaved' => array( 'rejected-unsaved', true ),
			'pending'          => array( 'pending', false ),
			'outdated'         => array( 'outdated', false ),
		);
	}

	/**
	 * Resolving a thread in the Notes panel addresses every note in it.
	 */
	public function test_resolved_thread_addresses_its_notes(): void {
		$thread = $this->add_note( 'r1' );
		$reply  = $this->add_note( 'r1', $thread );

		wp_set_comment_status( $thread, 'approve' );

		$this->assertTrue( Review_Progress::is_addressed( $this->note( $thread ) ) );
		$this->assertTrue( Review_Progress::is_addressed( $this->note( $reply ) ) );
	}

	/**
	 * The comparison counts the previous entry's open notes by their state now,
	 * and leaves out deleted notes and notes on other posts.
	 */
	public function test_compare_uses_open_note_ids(): void {
		$addressed = $this->add_note( 'r1', 0, array( 'ai_feedback_resolved' => '1' ) );
		$open      = $this->add_note( 'r1' );
		$deleted   = $this->add_note( 'r1' );
		$elsewhere = $this->add_note( 'r1', 0, array(), 99 );
		wp_delete_comment( $deleted, true );

		$result = Review_Progress::compare(
			self::POST_ID,
			array(
				'review_id'     => 'r1',
				'open_note_ids' => array( $addressed, $open, $deleted, $elsewhere, $open ),
			)
		);

		$this->assertSame(
			array(
				'addressed'  => 1,
				'still_open' => 1,
				'open_ids'   => array( $open ),
			),
			$result
		);
	}

	/**
	 * Entries from before open notes were kept compare the notes their review created.
	 */
	public function test_compare_falls_back_to_the_previous_reviews_notes(): void {
		$this->add_note( 'r1', 0, array( 'ai_feedback_resolved' => '1' ) );
		$open = $this->add_note( 'r1' );
		$this->add_note( 'r0' );

		$result = Review_Progress::compare( self::POST_ID, array( 'review_id' => 'r1' ) );

		$this->assertSame( 1, $result['addressed'] );
		$this->assertSame( array( $open ), $result['open_ids'] );
	}

	/**
	 * A post's first review has nothing to compare; a second one reports how
	 * the first one's notes stand and carries the still open ones forward.
	 */
	public function test_history_records_progress_between_reviews(): void {
		$first_a = $this->add_note( 'r1' );
		$first_b = $this->add_note( 'r1' );
		$first_c = $this->add_note( 'r1' );
		Review_History::record( self::POST_ID, $this->review( 'r1', array( $first_a, $first_b, $first_c ) ) );

		$entry = Review_History::find( self::POST_ID, 'r1' );
		$this->assertIsArray( $entry );
		$this->assertArrayNotHasKey( 'progress', $entry );
		$this->assertSame( array( $first_a, $first_b, $first_c ), $entry['open_note_ids'] );
		$this->assertNull( Review_History::get_review_extras( self::POST_ID, 'r1' )['progress'] );

		// Dismiss one, accept another's suggestion, delete nothing.
		update_comment_meta( $first_a, 'ai_feedback_resolved', true );
		update_comment_meta( $first_b, '_wp_suggestion', '{}' );
		update_comment_meta( $first_b, '_wp_suggestion_status', 'applied' );

		$second = $this->add_note( 'r2' );
		Review_History::record( self::POST_ID, $this->review( 'r2', array( $second ) ) );

		$entry = Review_History::find( self::POST_ID, 'r2' );
		$this->assertIsArray( $entry );
		$this->assertSame(
			array(
				'addressed'  => 2,
				'still_open' => 1,
			),
			$entry['progress']
		);
		$this->assertSame( array( $first_c, $second ), $entry['open_note_ids'] );
		$this->assertSame(
			array(
				'addressed'  => 2,
				'still_open' => 1,
			),
			Review_History::get_review_extras( self::POST_ID, 'r2' )['progress']
		);

		// The next review compares against what r2 left open, not r1's notes.
		update_comment_meta( $first_c, 'ai_feedback_resolved', true );
		Review_History::record( self::POST_ID, $this->review( 'r3', array() ) );

		$entry = Review_History::find( self::POST_ID, 'r3' );
		$this->assertIsArray( $entry );
		$this->assertSame(
			array(
				'addressed'  => 1,
				'still_open' => 1,
			),
			$entry['progress']
		);
	}

	/**
	 * The parts of a chunked review fold into one entry: counts add up, the
	 * comparison made by the first part is kept, and strengths stay capped.
	 */
	public function test_history_merges_chunked_review_parts(): void {
		$old = $this->add_note( 'r1' );
		Review_History::record( self::POST_ID, $this->review( 'r1', array( $old ) ) );
		update_comment_meta( $old, 'ai_feedback_resolved', true );

		$part_one          = $this->review( 'r2', array( $this->add_note( 'r2' ), $this->add_note( 'r2' ) ) );
		$part_one['summary_text'] = 'First part.';
		$part_one['strengths']    = array(
			$this->strength( 'b1', 'One.' ),
			$this->strength( 'b2', 'Two.' ),
		);
		Review_History::record( self::POST_ID, $part_one );

		$part_two_note            = $this->add_note( 'r2' );
		$part_two                 = $this->review( 'r2', array( $part_two_note ) );
		$part_two['summary_text'] = 'Whole document.';
		$part_two['strengths']    = array(
			$this->strength( 'b2', 'Two again.' ),
			$this->strength( 'b3', 'Three.' ),
			$this->strength( 'b4', 'Four.' ),
		);
		Review_History::record( self::POST_ID, $part_two );

		$history = Review_History::get( self::POST_ID );
		$this->assertCount( 2, $history );

		$entry = $history[1];
		$this->assertIsArray( $entry['summary'] );
		$this->assertIsArray( $entry['summary']['by_severity'] );
		$this->assertIsArray( $entry['strengths'] );
		$this->assertIsArray( $entry['open_note_ids'] );
		$this->assertSame( 'r2', $entry['review_id'] );
		$this->assertSame( 3, $entry['note_count'] );
		$this->assertSame( 2, $entry['summary']['by_severity']['important'] );
		$this->assertSame( 'Whole document.', $entry['summary_text'] );
		$this->assertSame(
			array(
				'addressed'  => 1,
				'still_open' => 0,
			),
			$entry['progress']
		);
		$this->assertSame( array( 'One.', 'Two.', 'Three.' ), array_column( $entry['strengths'], 'text' ) );
		$this->assertCount( 3, $entry['open_note_ids'] );
		$this->assertContains( $part_two_note, $entry['open_note_ids'] );
	}

	/**
	 * Strengths are stored as plain text and malformed ones are dropped.
	 */
	public function test_history_sanitizes_strengths(): void {
		$review              = $this->review( 'r1', array() );
		$review['strengths'] = array(
			$this->strength( 'b1', 'Kept.' ),
			array( 'text' => 'No block.' ),
			array(
				'block_id' => 'b2',
				'text'     => 42,
			),
			array(
				'block_id'     => 'b3',
				'text'         => 'Bad anchor dropped.',
				'block_anchor' => '<script>',
			),
			'nope',
		);

		$strengths = Review_History::build_entry( $review )['strengths'];

		$this->assertSame( array( 'Kept.', 'Bad anchor dropped.' ), array_column( $strengths, 'text' ) );
		$this->assertSame( '', $strengths[1]['block_anchor'] );
	}

	/**
	 * The strengths filter also caps what history keeps, including the
	 * strengths merged from a chunked review's parts.
	 */
	public function test_history_caps_strengths_by_filter(): void {
		$review              = $this->review( 'r1', array() );
		$review['strengths'] = array(
			$this->strength( 'b1', 'One.' ),
			$this->strength( 'b2', 'Two.' ),
		);

		$GLOBALS['test_filter_results'] = array( 'ai_feedback_max_strengths' => 1 );
		$this->assertSame( array( 'One.' ), array_column( Review_History::build_entry( $review )['strengths'], 'text' ) );

		$GLOBALS['test_filter_results'] = array( 'ai_feedback_max_strengths' => 0 );
		$this->assertSame( array(), Review_History::build_entry( $review )['strengths'] );

		unset( $GLOBALS['test_filter_results'] );
	}

	/**
	 * A reloaded review lists every note of the latest review in the history,
	 * including feedback a follow-up review added to an earlier thread.
	 */
	public function test_latest_review_includes_feedback_added_to_earlier_threads(): void {
		$thread = $this->add_note( 'r1', 0, array( 'block_id' => 'b1' ) );
		$this->add_note( 'r1', 0, array( 'block_id' => 'b2' ) );
		Review_History::record( self::POST_ID, $this->review( 'r1', array() ) );

		$followup = $this->add_note( 'r2', $thread, array( 'block_id' => 'b1' ) );
		Review_History::record( self::POST_ID, $this->review( 'r2', array( $followup ) ) );

		$review = ( new Notes_Manager() )->get_latest_review_for_post( self::POST_ID );

		$this->assertIsArray( $review );
		$this->assertSame( 'r2', $review['review_id'] );
		$this->assertSame( array( $followup ), $review['note_ids'] );
		$this->assertSame( 1, $review['note_count'] );
		$this->assertSame( array( 'b1' => $thread ), $review['block_mapping'] );
	}

	/**
	 * Without history the newest top-level note picks the review, and all of
	 * its notes are listed.
	 */
	public function test_latest_review_without_history_lists_replies_too(): void {
		$thread = $this->add_note( 'r1', 0, array( 'block_id' => 'b1' ) );
		$reply  = $this->add_note( 'r1', $thread, array( 'block_id' => 'b1' ) );

		$review = ( new Notes_Manager() )->get_latest_review_for_post( self::POST_ID );

		$this->assertIsArray( $review );
		$this->assertSame( 'r1', $review['review_id'] );
		$this->assertSame( array( $thread, $reply ), $review['note_ids'] );
		$this->assertSame( 2, $review['note_count'] );
	}

	/**
	 * Build a review result.
	 *
	 * @param  string    $review_id Review ID.
	 * @param  list<int> $note_ids  Note IDs it created.
	 * @return array<string, mixed>
	 */
	private function review( string $review_id, array $note_ids ): array {
		return array(
			'review_id'    => $review_id,
			'post_id'      => self::POST_ID,
			'model'        => 'test-model',
			'note_ids'     => $note_ids,
			'note_count'   => count( $note_ids ),
			'summary'      => array(
				'by_severity' => array( 'important' => 1 ),
				'by_category' => array( 'content' => 1 ),
			),
			'summary_text' => '',
			'timestamp'    => '2026-11-20T10:00:00+00:00',
		);
	}

	/**
	 * Build a strength.
	 *
	 * @param  string $block_id Block ID.
	 * @param  string $text     Text.
	 * @return array<string, mixed>
	 */
	private function strength( string $block_id, string $text ): array {
		return array(
			'block_id'     => $block_id,
			'block_name'   => 'core/paragraph',
			'block_index'  => 0,
			'block_anchor' => 'abc123',
			'text'         => $text,
		);
	}
}
