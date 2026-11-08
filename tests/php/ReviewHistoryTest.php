<?php
/**
 * Tests for Review_History and History_Controller.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Review_History;
use AI_Feedback\History_Controller;
use AI_Feedback\Notes_Manager;

/**
 * Notes manager stub that returns canned notes.
 */
class StubNotesManager extends Notes_Manager {

	/**
	 * Notes returned for any review ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $notes = array();

	/**
	 * Return the canned notes.
	 *
	 * @param  string $review_id Review ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_notes_by_review( string $review_id ): array {
		return $this->notes;
	}
}

/**
 * Review history tests.
 */
class ReviewHistoryTest extends TestCase {

	/**
	 * Reset global fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['test_post_meta']        = array();
		$GLOBALS['test_current_user_id']  = 7;
		$GLOBALS['test_current_user_can'] = true;
	}

	/**
	 * Build a fake review result.
	 *
	 * @param  string $review_id Review ID.
	 * @param  int    $count     Note count.
	 * @return array<string, mixed>
	 */
	private function review( string $review_id, int $count = 2 ): array {
		return array(
			'review_id'     => $review_id,
			'post_id'       => 42,
			'model'         => 'test-model',
			'notes'         => array( array( 'id' => 1 ) ),
			'note_ids'      => array( 1, 2 ),
			'block_mapping' => array( 'abc' => 1 ),
			'summary'       => array(
				'total_notes'  => $count,
				'by_severity'  => array(
					'critical'   => 1,
					'important'  => 1,
					'suggestion' => 0,
				),
				'by_category'  => array( 'content' => 2 ),
				'has_critical' => true,
			),
			'summary_text'  => 'Solid draft.',
			'note_count'    => $count,
			'timestamp'     => '2026-11-08 10:00:00',
		);
	}

	/**
	 * Entries keep only the summary fields.
	 */
	public function test_build_entry_keeps_summary_fields_only(): void {
		$entry = Review_History::build_entry( $this->review( 'aaa-1' ) );

		$this->assertSame(
			array(
				'review_id'    => 'aaa-1',
				'timestamp'    => '2026-11-08 10:00:00',
				'model'        => 'test-model',
				'note_count'   => 2,
				'notes_failed' => false,
				'user_id'      => 7,
				'summary_text' => 'Solid draft.',
				'summary'      => array(
					'by_severity' => array(
						'critical'   => 1,
						'important'  => 1,
						'suggestion' => 0,
					),
					'by_category' => array( 'content' => 2 ),
				),
			),
			$entry
		);
	}

	/**
	 * Reviews whose notes could not be created are flagged.
	 */
	public function test_build_entry_flags_notes_error(): void {
		$review                = $this->review( 'aaa-1' );
		$review['notes_error'] = 'Failed';

		$this->assertTrue( Review_History::build_entry( $review )['notes_failed'] );
	}

	/**
	 * Recording appends entries to post meta.
	 */
	public function test_record_appends_entry(): void {
		Review_History::record( 42, $this->review( 'aaa-1' ) );
		Review_History::record( 42, $this->review( 'aaa-2' ) );

		$history = Review_History::get( 42 );
		$this->assertCount( 2, $history );
		$this->assertSame( 'aaa-2', $history[1]['review_id'] );
	}

	/**
	 * History is capped at MAX_ENTRIES, dropping the oldest.
	 */
	public function test_record_caps_history(): void {
		for ( $i = 1; $i <= Review_History::MAX_ENTRIES + 3; $i++ ) {
			Review_History::record( 42, $this->review( 'aaa-' . $i ) );
		}

		$history = Review_History::get( 42 );
		$this->assertCount( Review_History::MAX_ENTRIES, $history );
		$this->assertSame( 'aaa-4', $history[0]['review_id'] );
	}

	/**
	 * Reviews without an ID are ignored.
	 */
	public function test_record_skips_missing_review_id(): void {
		Review_History::record( 42, array( 'note_count' => 3 ) );

		$this->assertSame( array(), Review_History::get( 42 ) );
	}

	/**
	 * Corrupt meta reads as empty history.
	 */
	public function test_get_handles_non_array_meta(): void {
		$GLOBALS['test_post_meta'][42][ Review_History::META_KEY ] = 'garbage';

		$this->assertSame( array(), Review_History::get( 42 ) );
	}

	/**
	 * Meta is registered read-only and edit-context only.
	 */
	public function test_register_meta_is_protected(): void {
		Review_History::register_meta();

		$args = $GLOBALS['test_registered_post_meta'][ Review_History::META_KEY ];
		$this->assertSame( '__return_false', $args['auth_callback'] );
		$this->assertSame( array( 'edit' ), $args['show_in_rest']['schema']['context'] );
	}

	/**
	 * The list endpoint returns newest first.
	 */
	public function test_controller_lists_newest_first(): void {
		Review_History::record( 42, $this->review( 'aaa-1' ) );
		Review_History::record( 42, $this->review( 'aaa-2' ) );

		$request = new \WP_REST_Request();
		$request->set_param( 'post_id', 42 );

		$data = ( new History_Controller( new StubNotesManager() ) )->get_history( $request )->get_data();

		$this->assertSame( 2, $data['total'] );
		$this->assertSame( 'aaa-2', $data['history'][0]['review_id'] );
	}

	/**
	 * The detail endpoint returns the entry plus this post's notes.
	 */
	public function test_controller_returns_review_with_post_notes(): void {
		Review_History::record( 42, $this->review( 'aaa-1' ) );

		$notes        = new StubNotesManager();
		$notes->notes = array(
			array(
				'id'   => 1,
				'post' => 42,
			),
			array(
				'id'   => 2,
				'post' => 99,
			),
		);

		$request = new \WP_REST_Request();
		$request->set_param( 'post_id', 42 );
		$request->set_param( 'review_id', 'aaa-1' );

		$response = ( new History_Controller( $notes ) )->get_history_review( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertSame( 'aaa-1', $data['review_id'] );
		$this->assertSame( array( 1 ), array_column( $data['notes'], 'id' ) );
	}

	/**
	 * Unknown review IDs return a 404 error.
	 */
	public function test_controller_unknown_review_is_404(): void {
		$request = new \WP_REST_Request();
		$request->set_param( 'post_id', 42 );
		$request->set_param( 'review_id', 'nope' );

		$response = ( new History_Controller( new StubNotesManager() ) )->get_history_review( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 404, $response->get_error_data()['status'] );
	}

	/**
	 * A review from another post's history is not served for this post.
	 */
	public function test_controller_review_from_other_post_is_404(): void {
		Review_History::record( 99, $this->review( 'aaa-1' ) );

		$request = new \WP_REST_Request();
		$request->set_param( 'post_id', 42 );
		$request->set_param( 'review_id', 'aaa-1' );

		$response = ( new History_Controller( new StubNotesManager() ) )->get_history_review( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
	}

	/**
	 * Users who cannot edit the post are refused.
	 */
	public function test_permissions_check_requires_edit_post(): void {
		$GLOBALS['test_current_user_can'] = false;

		$request = new \WP_REST_Request();
		$request->set_param( 'post_id', 42 );

		$result = ( new History_Controller( new StubNotesManager() ) )->get_history_permissions_check( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * Users who can edit the post are allowed.
	 */
	public function test_permissions_check_allows_editors(): void {
		$request = new \WP_REST_Request();
		$request->set_param( 'post_id', 42 );

		$this->assertTrue( ( new History_Controller( new StubNotesManager() ) )->get_history_permissions_check( $request ) );
	}
}
