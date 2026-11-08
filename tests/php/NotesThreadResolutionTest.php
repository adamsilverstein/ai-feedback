<?php
/**
 * Tests for dismissing feedback and syncing the Notes panel thread.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Notes_Controller;
use AI_Feedback\Notes_Manager;
use WP_REST_Request;

/**
 * Test cases for note resolution, thread syncing and permissions.
 */
class NotesThreadResolutionTest extends TestCase {

	/**
	 * Notes manager.
	 *
	 * @var Notes_Manager
	 */
	private Notes_Manager $manager;

	/**
	 * Load the fake comment API and reset it.
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once __DIR__ . '/fixtures/fake-comments.php';
		require_once dirname( __DIR__, 2 ) . '/includes/class-notes-controller.php';

		\ai_feedback_test_reset_comments(); // @phpstan-ignore function.notFound (defined in the fixture above)
		unset( $GLOBALS['test_current_user_can'] );

		$this->manager = new Notes_Manager();
	}

	/**
	 * Clean up capability overrides.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['test_current_user_can'] );
		parent::tearDown();
	}

	/**
	 * Create a note in the fake store.
	 *
	 * @param  int  $parent Parent note ID.
	 * @param  bool $is_ai  Whether the note is AI feedback.
	 * @return int Note ID.
	 */
	private function add_note( int $parent = 0, bool $is_ai = true ): int {
		$id = wp_insert_comment(
			array(
				'comment_post_ID' => 10,
				'comment_type'    => 'note',
				'comment_parent'  => $parent,
				'comment_meta'    => $is_ai ? array( 'ai_feedback' => '1' ) : array(),
			)
		);

		$this->assertIsInt( $id );

		return $id;
	}

	/**
	 * Count the status replies core's Notes panel would show.
	 *
	 * @param  int    $thread_id Thread ID.
	 * @param  string $status    `resolved` or `reopen`.
	 * @return int Number of matching replies.
	 */
	private function count_status_replies( int $thread_id, string $status ): int {
		$count   = 0;
		$replies = get_comments( array( 'parent' => $thread_id ) );
		$this->assertIsArray( $replies );
		foreach ( $replies as $reply ) {
			if ( $reply instanceof \WP_Comment && get_comment_meta( (int) $reply->comment_ID, '_wp_note_status', true ) === $status ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Resolving twice still succeeds.
	 */
	public function test_resolve_note_is_idempotent(): void {
		$note = $this->add_note();

		$this->assertTrue( $this->manager->resolve_note( $note ) );
		$this->assertTrue( $this->manager->resolve_note( $note ) );
		$this->assertTrue( $this->manager->is_note_resolved( $note ) );
	}

	/**
	 * Unresolving a note that isn't resolved still succeeds.
	 */
	public function test_unresolve_note_is_idempotent(): void {
		$note = $this->add_note();

		$this->assertTrue( $this->manager->unresolve_note( $note ) );
		$this->assertFalse( $this->manager->is_note_resolved( $note ) );
	}

	/**
	 * The thread stays open while other AI feedback in it is open.
	 */
	public function test_dismissing_one_of_several_keeps_thread_open(): void {
		$thread = $this->add_note();
		$this->add_note( $thread );

		$this->manager->resolve_note( $thread );
		$result = $this->manager->sync_thread_status( $thread, true );

		$this->assertSame( $thread, $result['thread_id'] );
		$this->assertFalse( $result['thread_resolved'] );
		$this->assertFalse( $result['thread_changed'] );
	}

	/**
	 * Dismissing the last open AI item resolves the thread like core does.
	 */
	public function test_dismissing_last_item_resolves_thread(): void {
		$thread = $this->add_note();
		$reply  = $this->add_note( $thread );
		$this->add_note( $thread, false ); // A person's reply doesn't count.

		$this->manager->resolve_note( $thread );
		$this->manager->sync_thread_status( $thread, true );
		$this->manager->resolve_note( $reply );
		$result = $this->manager->sync_thread_status( $reply, true );

		$this->assertTrue( $result['thread_resolved'] );
		$this->assertTrue( $result['thread_changed'] );
		$this->assertTrue( $this->manager->is_thread_resolved( $thread ) );
		$this->assertSame( 1, $this->count_status_replies( $thread, 'resolved' ) );
	}

	/**
	 * Restoring an item reopens a resolved thread.
	 */
	public function test_restoring_reopens_thread(): void {
		$thread = $this->add_note();

		$this->manager->resolve_note( $thread );
		$this->manager->sync_thread_status( $thread, true );
		$this->manager->unresolve_note( $thread );
		$result = $this->manager->sync_thread_status( $thread, false );

		$this->assertFalse( $result['thread_resolved'] );
		$this->assertTrue( $result['thread_changed'] );
		$this->assertSame( 1, $this->count_status_replies( $thread, 'reopen' ) );
	}

	/**
	 * Dismissing never reopens a thread someone resolved by hand.
	 */
	public function test_dismissing_does_not_reopen_hand_resolved_thread(): void {
		$thread = $this->add_note();
		$this->add_note( $thread );
		wp_set_comment_status( $thread, 'approve' );

		$this->manager->resolve_note( $thread );
		$result = $this->manager->sync_thread_status( $thread, true );

		$this->assertTrue( $result['thread_resolved'] );
		$this->assertFalse( $result['thread_changed'] );
	}

	/**
	 * Build a request for a note.
	 *
	 * @param  int $note_id Note ID.
	 * @return WP_REST_Request Request.
	 */
	private function request_for( int $note_id ): WP_REST_Request {
		$request = new WP_REST_Request();
		$request->set_param( 'note_id', $note_id );
		return $request;
	}

	/**
	 * The resolve endpoint reports the thread state.
	 */
	public function test_resolve_endpoint_reports_thread_state(): void {
		$thread = $this->add_note();

		$response = ( new Notes_Controller() )->resolve_note( $this->request_for( $thread ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( $thread, $data['thread_id'] );
		$this->assertTrue( $data['thread_resolved'] );
		$this->assertTrue( $data['thread_changed'] );
	}

	/**
	 * Permission requires edit_post on the note's post.
	 */
	public function test_permission_check_uses_edit_post_for_note_post(): void {
		$note = $this->add_note();
		$seen = array();

		$GLOBALS['test_current_user_can'] = static function ( string $cap, ...$args ) use ( &$seen ): bool {
			$seen[] = array( $cap, $args );
			return false;
		};

		$result = ( new Notes_Controller() )->update_note_permissions_check( $this->request_for( $note ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( array( array( 'edit_post', array( 10 ) ) ), $seen );
	}

	/**
	 * Users who can edit the post pass the check.
	 */
	public function test_permission_check_allows_post_editors(): void {
		$note = $this->add_note();

		$GLOBALS['test_current_user_can'] = static fn( string $cap, ...$args ): bool => 'edit_post' === $cap && array( 10 ) === $args;

		$this->assertTrue( ( new Notes_Controller() )->update_note_permissions_check( $this->request_for( $note ) ) );
	}

	/**
	 * A missing note is refused.
	 */
	public function test_permission_check_refuses_missing_note(): void {
		$result = ( new Notes_Controller() )->update_note_permissions_check( $this->request_for( 999 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
