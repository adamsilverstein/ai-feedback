<?php
/**
 * Tests for Reply_Status_Controller.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Reply_Status_Controller;
use AI_Feedback\Reply_Cron_Dispatcher;

require_once dirname( __DIR__, 2 ) . '/includes/class-reply-cron-dispatcher.php';
require_once __DIR__ . '/reply-cron-mocks.php';
require_once __DIR__ . '/reply-status-controller-bootstrap.php';
require_once __DIR__ . '/reply-service-mocks.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-reply-status-controller.php';

/**
 * Reply status controller tests.
 */
class ReplyStatusControllerTest extends TestCase {

	/**
	 * Controller under test.
	 *
	 * @var Reply_Status_Controller
	 */
	private Reply_Status_Controller $controller;

	/**
	 * Per-test fixture reset.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->controller                 = new Reply_Status_Controller();
		$GLOBALS['test_comment_meta']     = array();
		$GLOBALS['test_current_user_can'] = true;
		$GLOBALS['test_comments']         = array(
			200 => new \WP_Comment(
				array(
					'comment_ID'      => '200',
					'comment_post_ID' => '42',
				)
			),
		);
	}

	/**
	 * Build a status request for a reply ID.
	 *
	 * @param int $id Reply comment ID.
	 * @return \WP_REST_Request
	 */
	private function request( int $id ): \WP_REST_Request {
		$request = new \WP_REST_Request();
		$request->set_param( 'id', $id );
		return $request;
	}

	/**
	 * Clean up globals.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['test_comment_meta'], $GLOBALS['test_current_user_can'], $GLOBALS['test_comments'] );
		parent::tearDown();
	}

	/**
	 * Unknown status (no meta) returns 'unknown'.
	 */
	public function test_status_returns_unknown_when_no_meta(): void {
		$response = $this->controller->get_status( $this->request( 200 ) );

		$data = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'unknown', $data['status'] );
		$this->assertSame( 200, $data['reply_id'] );
	}

	/**
	 * Pending status returns as-is without extra fields.
	 */
	public function test_status_returns_pending(): void {
		$GLOBALS['test_comment_meta'][200] = array(
			Reply_Cron_Dispatcher::STATUS_META_KEY => 'pending',
		);

		$data = $this->controller
			->get_status( $this->request( 200 ) )
			->get_data();

		$this->assertSame( 'pending', $data['status'] );
		$this->assertArrayNotHasKey( 'ai_reply_comment_id', $data );
		$this->assertArrayNotHasKey( 'error', $data );
	}

	/**
	 * A reply pending for longer than the timeout is reported as failed.
	 */
	public function test_stale_pending_reports_failed(): void {
		$GLOBALS['test_comment_meta'][200] = array(
			Reply_Cron_Dispatcher::STATUS_META_KEY        => 'pending',
			Reply_Cron_Dispatcher::PENDING_SINCE_META_KEY => time() - Reply_Cron_Dispatcher::PENDING_TIMEOUT - 1,
		);

		$data = $this->controller
			->get_status( $this->request( 200 ) )
			->get_data();

		$this->assertSame( 'failed', $data['status'] );
		$this->assertArrayHasKey( 'error', $data );
	}

	/**
	 * Complete status exposes the AI reply comment ID so the frontend can fetch it.
	 */
	public function test_status_complete_includes_ai_reply_comment_id(): void {
		$GLOBALS['test_comment_meta'][200] = array(
			Reply_Cron_Dispatcher::STATUS_META_KEY => 'complete',
			Reply_Cron_Dispatcher::REPLY_COMMENT_ID_META_KEY => 555,
		);

		$data = $this->controller
			->get_status( $this->request( 200 ) )
			->get_data();

		$this->assertSame( 'complete', $data['status'] );
		$this->assertSame( 555, $data['ai_reply_comment_id'] );
	}

	/**
	 * Failed status exposes the error message.
	 */
	public function test_status_failed_includes_error(): void {
		$GLOBALS['test_comment_meta'][200] = array(
			Reply_Cron_Dispatcher::STATUS_META_KEY => 'failed',
			Reply_Cron_Dispatcher::REPLY_ERROR_META_KEY => 'AI request timed out.',
		);

		$data = $this->controller
			->get_status( $this->request( 200 ) )
			->get_data();

		$this->assertSame( 'failed', $data['status'] );
		$this->assertSame( 'AI request timed out.', $data['error'] );
	}

	/**
	 * A failed reply inside the retry cooldown reports the seconds left.
	 */
	public function test_status_failed_in_cooldown_includes_retry_after(): void {
		$GLOBALS['test_comment_meta'] = array(
			200 => array(
				Reply_Cron_Dispatcher::STATUS_META_KEY     => 'failed',
				Reply_Cron_Dispatcher::CLAIMED_AT_META_KEY => time() - 4,
			),
		);

		$data = $this->controller
			->get_status( $this->request( 200 ) )
			->get_data();

		$this->assertIsArray( $data );
		$this->assertSame( 'failed', $data['status'] );
		$this->assertGreaterThan( 0, $data['retry_after'] );
		$this->assertLessThanOrEqual( Reply_Cron_Dispatcher::RETRY_COOLDOWN - 4, $data['retry_after'] );
	}

	/**
	 * A failed reply past the retry cooldown has no retry_after.
	 */
	public function test_status_failed_after_cooldown_omits_retry_after(): void {
		$GLOBALS['test_comment_meta'] = array(
			200 => array(
				Reply_Cron_Dispatcher::STATUS_META_KEY     => 'failed',
				Reply_Cron_Dispatcher::CLAIMED_AT_META_KEY => time() - Reply_Cron_Dispatcher::RETRY_COOLDOWN,
			),
		);

		$data = $this->controller
			->get_status( $this->request( 200 ) )
			->get_data();

		$this->assertIsArray( $data );
		$this->assertSame( 'failed', $data['status'] );
		$this->assertArrayNotHasKey( 'retry_after', $data );
	}

	/**
	 * A reply that is not failed never reports retry_after.
	 */
	public function test_status_pending_omits_retry_after(): void {
		$GLOBALS['test_comment_meta'] = array(
			200 => array(
				Reply_Cron_Dispatcher::STATUS_META_KEY        => 'processing',
				Reply_Cron_Dispatcher::PENDING_SINCE_META_KEY => time(),
				Reply_Cron_Dispatcher::CLAIMED_AT_META_KEY    => time(),
			),
		);

		$data = $this->controller
			->get_status( $this->request( 200 ) )
			->get_data();

		$this->assertIsArray( $data );
		$this->assertSame( 'pending', $data['status'] );
		$this->assertArrayNotHasKey( 'retry_after', $data );
	}

	/**
	 * Complete status without a stored reply ID is reported as failed.
	 */
	public function test_status_complete_without_reply_id_reports_failed(): void {
		$GLOBALS['test_comment_meta'][200] = array(
			Reply_Cron_Dispatcher::STATUS_META_KEY => 'complete',
		);

		$data = $this->controller->get_status( $this->request( 200 ) )->get_data();

		$this->assertSame( 'failed', $data['status'] );
		$this->assertArrayNotHasKey( 'ai_reply_comment_id', $data );
		$this->assertNotEmpty( $data['error'] );
	}

	/**
	 * Unexpected stored status values are reported as unknown.
	 */
	public function test_unexpected_status_value_reports_unknown(): void {
		$GLOBALS['test_comment_meta'][200] = array(
			Reply_Cron_Dispatcher::STATUS_META_KEY => 'bogus',
		);

		$data = $this->controller->get_status( $this->request( 200 ) )->get_data();

		$this->assertSame( 'unknown', $data['status'] );
	}

	/**
	 * Permission check rejects users who cannot edit the reply's post.
	 */
	public function test_permissions_check_denies_unauthorized_user(): void {
		$GLOBALS['test_current_user_can'] = false;

		$result = $this->controller->get_status_permissions_check( $this->request( 200 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * A nonexistent reply ID returns a 404 instead of 'unknown'.
	 */
	public function test_permissions_check_returns_404_for_missing_comment(): void {
		$result = $this->controller->get_status_permissions_check( $this->request( 12345 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_comment_invalid_id', $result->get_error_code() );
		$this->assertSame( array( 'status' => 404 ), $result->get_error_data() );
	}

	/**
	 * Authorized users pass the permission check.
	 */
	public function test_permissions_check_allows_post_editor(): void {
		$this->assertTrue( $this->controller->get_status_permissions_check( $this->request( 200 ) ) );
	}

	/**
	 * The schema lists every response field.
	 */
	public function test_item_schema_lists_response_fields(): void {
		$schema = $this->controller->get_item_schema();

		$this->assertSame(
			array( 'reply_id', 'status', 'ai_reply_comment_id', 'error', 'retry_after' ),
			array_keys( $schema['properties'] )
		);
	}

	/**
	 * Seed an AI note (300) with a user reply (301), an AI reply (302) and a
	 * second user reply (303).
	 *
	 * @param string $latest_status Status meta of the newest user reply.
	 */
	private function seed_thread( string $latest_status ): void {
		$base = array(
			'comment_post_ID' => '42',
			'comment_type'    => 'note',
		);

		$GLOBALS['test_comments'][300]     = new \WP_Comment( $base + array( 'comment_ID' => '300' ) );
		$GLOBALS['test_comment_meta'][300] = array( 'ai_feedback' => '1' );

		$GLOBALS['test_comments'][301]     = new \WP_Comment(
			$base + array(
				'comment_ID'      => '301',
				'comment_parent'  => '300',
				'comment_author'  => 'Jane',
				'comment_content' => '<p>Which stat?</p>',
			)
		);
		$GLOBALS['test_comment_meta'][301] = array( Reply_Cron_Dispatcher::STATUS_META_KEY => 'complete' );

		$GLOBALS['test_comments'][302]     = new \WP_Comment(
			$base + array(
				'comment_ID'      => '302',
				'comment_parent'  => '300',
				'comment_author'  => 'AI Feedback',
				'comment_content' => "The 40% growth claim.\n\n",
			)
		);
		$GLOBALS['test_comment_meta'][302] = array( 'ai_feedback' => '1' );

		$GLOBALS['test_comments'][303]     = new \WP_Comment(
			$base + array(
				'comment_ID'      => '303',
				'comment_parent'  => '300',
				'comment_author'  => 'Jane',
				'comment_content' => 'Thanks, fixed.',
			)
		);
		$GLOBALS['test_comment_meta'][303] = array( Reply_Cron_Dispatcher::STATUS_META_KEY => $latest_status );
	}

	/**
	 * Build a threads request for a post.
	 *
	 * @param int $post Post ID.
	 * @return \WP_REST_Request
	 */
	private function threads_request( int $post = 42 ): \WP_REST_Request {
		$request = new \WP_REST_Request();
		$request->set_param( 'post', $post );
		return $request;
	}

	/**
	 * Threads list replies oldest first as plain text and flag AI replies.
	 */
	public function test_threads_list_replies_in_order_as_plain_text(): void {
		$this->seed_thread( 'complete' );

		$data   = $this->controller->get_threads( $this->threads_request() )->get_data();
		$thread = ( (array) $data['threads'] )[300];

		$this->assertSame( array( 300 ), array_keys( (array) $data['threads'] ) );
		$this->assertSame( array( 301, 302, 303 ), array_column( $thread['replies'], 'id' ) );
		$this->assertSame( 'Which stat?', $thread['replies'][0]['content'] );
		$this->assertSame( 'The 40% growth claim.', $thread['replies'][1]['content'] );
		$this->assertSame( array( false, true, false ), array_column( $thread['replies'], 'is_ai' ) );
		$this->assertNull( $thread['pending_reply_id'] );
	}

	/**
	 * An AI reply's edit is returned with the thread; other replies have none.
	 */
	public function test_threads_include_ai_reply_edit(): void {
		$this->seed_thread( 'complete' );
		$edit = array(
			'type'    => 'replace_text',
			'find'    => '40%',
			'replace' => '42%',
		);

		$GLOBALS['test_comment_meta'][302][ \AI_Feedback\Notes_Manager::REPLY_EDIT_META_KEY ] = (string) json_encode( $edit );
		// A user reply can't carry an edit, even with the meta set.
		$GLOBALS['test_comment_meta'][301][ \AI_Feedback\Notes_Manager::REPLY_EDIT_META_KEY ] = (string) json_encode( $edit );

		$data    = $this->controller->get_threads( $this->threads_request() )->get_data();
		$replies = ( (array) $data['threads'] )[300]['replies'];

		$this->assertSame( array( null, $edit, null ), array_column( $replies, 'edit' ) );
	}

	/**
	 * AI replies with an edit carry their stored revision, and edits saved
	 * without one are numbered in order after the review's quick fix.
	 */
	public function test_threads_number_edit_revisions(): void {
		$this->seed_thread( 'complete' );
		$edit = (string) json_encode(
			array(
				'type'    => 'replace_text',
				'find'    => '40%',
				'replace' => '42%',
			)
		);

		$GLOBALS['test_comment_meta'][300]['feedback_quick_fix'] = '{"find":"40%","replace":"41%"}';
		$GLOBALS['test_comment_meta'][302][ \AI_Feedback\Notes_Manager::REPLY_EDIT_META_KEY ] = $edit;

		$GLOBALS['test_comments'][304]     = new \WP_Comment(
			array(
				'comment_ID'      => '304',
				'comment_post_ID' => '42',
				'comment_type'    => 'note',
				'comment_parent'  => '300',
				'comment_author'  => 'AI Feedback',
				'comment_content' => 'Shorter.',
			)
		);
		$GLOBALS['test_comment_meta'][304] = array(
			'ai_feedback'                                  => '1',
			\AI_Feedback\Notes_Manager::REPLY_EDIT_META_KEY => $edit,
			\AI_Feedback\Notes_Manager::REVISION_META_KEY   => '5',
		);

		$data    = $this->controller->get_threads( $this->threads_request() )->get_data();
		$replies = ( (array) $data['threads'] )[300]['replies'];

		$this->assertSame( array( null, 2, null, 5 ), array_column( $replies, 'revision' ) );
	}

	/**
	 * A review note's structural edit counts as the first revision, like a
	 * quick fix, and a structural reply edit is returned as its edit.
	 */
	public function test_threads_count_block_action_as_first_revision(): void {
		$this->seed_thread( 'complete' );
		$move = array(
			'type'            => 'move_block',
			'position'        => 'before',
			'target_block_id' => '',
			'target_name'     => 'core/heading',
			'target_text'     => 'Intro',
		);

		$GLOBALS['test_comment_meta'][300][ \AI_Feedback\Notes_Manager::BLOCK_ACTION_META_KEY ] = '{"type":"remove_block"}';
		$GLOBALS['test_comment_meta'][302][ \AI_Feedback\Notes_Manager::REPLY_EDIT_META_KEY ]   = (string) json_encode( $move );

		$data    = $this->controller->get_threads( $this->threads_request() )->get_data();
		$replies = ( (array) $data['threads'] )[300]['replies'];

		$this->assertSame( array( null, 2, null ), array_column( $replies, 'revision' ) );
		$this->assertSame( array( null, $move, null ), array_column( $replies, 'edit' ) );
	}

	/**
	 * A review note's formatting fix counts as the first revision too.
	 */
	public function test_threads_count_block_change_as_first_revision(): void {
		$this->seed_thread( 'complete' );
		$GLOBALS['test_comment_meta'][300][ \AI_Feedback\Notes_Manager::BLOCK_CHANGE_META_KEY ] = '{"type":"split","after":"One.","next":"Two.","next_length":4}';
		$GLOBALS['test_comment_meta'][302][ \AI_Feedback\Notes_Manager::REPLY_EDIT_META_KEY ]   = '{"type":"remove_block"}';

		$data    = $this->controller->get_threads( $this->threads_request() )->get_data();
		$replies = ( (array) $data['threads'] )[300]['replies'];

		$this->assertSame( array( null, 2, null ), array_column( $replies, 'revision' ) );
	}

	/**
	 * The newest user reply awaiting an AI response is exposed for polling.
	 */
	public function test_threads_expose_pending_reply_id(): void {
		$this->seed_thread( 'pending' );

		$data = $this->controller->get_threads( $this->threads_request() )->get_data();

		$this->assertSame( 303, ( (array) $data['threads'] )[300]['pending_reply_id'] );
	}

	/**
	 * Replies under notes that AI Feedback did not create are left out.
	 */
	public function test_threads_skip_non_ai_notes(): void {
		$this->seed_thread( 'complete' );
		$GLOBALS['test_comment_meta'][300] = array();

		$data = $this->controller->get_threads( $this->threads_request() )->get_data();

		$this->assertSame( array(), (array) $data['threads'] );
	}

	/**
	 * Users who cannot edit the post cannot read its threads.
	 */
	public function test_threads_permissions_reject_unauthorized_user(): void {
		$GLOBALS['test_current_user_can'] = false;

		$result = $this->controller->get_threads_permissions_check( $this->threads_request() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * Post editors can read the post's threads.
	 */
	public function test_threads_permissions_allow_post_editor(): void {
		$this->assertTrue( $this->controller->get_threads_permissions_check( $this->threads_request() ) );
	}

	/**
	 * Comments that are not notes are treated as missing.
	 */
	public function test_permissions_check_returns_404_for_non_note_comment(): void {
		$GLOBALS['test_comments'][200]->comment_type = 'comment';

		$result = $this->controller->get_status_permissions_check( $this->request( 200 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_comment_invalid_id', $result->get_error_code() );
	}

	/**
	 * An AI reply's proposed house style rule is returned with its status.
	 */
	public function test_threads_include_house_rule_proposal(): void {
		$this->seed_thread( 'complete' );
		$GLOBALS['test_comment_meta'][302][ \AI_Feedback\House_Style::PROPOSAL_META_KEY ]        = 'Headings use sentence case';
		$GLOBALS['test_comment_meta'][302][ \AI_Feedback\House_Style::PROPOSAL_STATUS_META_KEY ] = array( get_current_user_id() => 'saved' );
		// A user reply can't carry a proposal, even with the meta set.
		$GLOBALS['test_comment_meta'][301][ \AI_Feedback\House_Style::PROPOSAL_META_KEY ] = 'Anything';

		$data    = $this->controller->get_threads( $this->threads_request() )->get_data();
		$replies = ( (array) $data['threads'] )[300]['replies'];

		$this->assertSame(
			array(
				null,
				array(
					'text'   => 'Headings use sentence case',
					'status' => 'saved',
				),
				null,
			),
			array_column( $replies, 'proposal' )
		);
	}
}
