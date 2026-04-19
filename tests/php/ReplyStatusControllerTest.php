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
	}

	/**
	 * Clean up globals.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['test_comment_meta'], $GLOBALS['test_current_user_can'] );
		parent::tearDown();
	}

	/**
	 * Unknown status (no meta) returns 'unknown'.
	 */
	public function test_status_returns_unknown_when_no_meta(): void {
		$response = $this->controller->get_status( new \WP_REST_Request( array( 'id' => 200 ) ) );

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
			->get_status( new \WP_REST_Request( array( 'id' => 200 ) ) )
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
			'ai_feedback_reply_comment_id'         => 555,
		);

		$data = $this->controller
			->get_status( new \WP_REST_Request( array( 'id' => 200 ) ) )
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
			'ai_feedback_reply_error'              => 'AI request timed out.',
		);

		$data = $this->controller
			->get_status( new \WP_REST_Request( array( 'id' => 200 ) ) )
			->get_data();

		$this->assertSame( 'failed', $data['status'] );
		$this->assertSame( 'AI request timed out.', $data['error'] );
	}

	/**
	 * Permission check rejects users lacking edit_posts.
	 */
	public function test_permissions_check_denies_unauthorized_user(): void {
		$GLOBALS['test_current_user_can'] = false;

		$result = $this->controller->get_status_permissions_check();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}
}
