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
			array( 'reply_id', 'status', 'ai_reply_comment_id', 'error' ),
			array_keys( $schema['properties'] )
		);
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
}
