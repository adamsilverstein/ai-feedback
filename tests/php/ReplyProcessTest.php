<?php
/**
 * Tests for answering a reply from the editor through POST /replies/{id}/process.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Notes_Manager;
use AI_Feedback\Prompt_Builder;
use AI_Feedback\Reply_Cron_Dispatcher;
use AI_Feedback\Reply_Service;
use AI_Feedback\Reply_Status_Controller;

require_once dirname( __DIR__, 2 ) . '/includes/class-reply-cron-dispatcher.php';
require_once __DIR__ . '/reply-cron-mocks.php';
require_once __DIR__ . '/reply-status-controller-bootstrap.php';
require_once __DIR__ . '/reply-service-mocks.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-reply-service.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-reply-status-controller.php';

/**
 * Reply service that counts AI calls instead of making them.
 */
class Counting_Reply_Service extends Reply_Service {

	/**
	 * Number of AI calls made.
	 *
	 * @var int
	 */
	public int $ai_calls = 0;

	/**
	 * Count the call and return a canned reply.
	 *
	 * @param string $prompt             Prompt.
	 * @param string $system_instruction System instruction.
	 * @param string $model              Model.
	 * @return string|\WP_Error
	 */
	protected function call_ai( string $prompt, string $system_instruction, string $model ): string|\WP_Error {
		++$this->ai_calls;
		return 'Link the citation here.';
	}
}

/**
 * Notes manager that pretends to save the AI reply as comment 999.
 */
class Process_Fake_Notes_Manager extends Notes_Manager {

	/**
	 * Pretend to save the reply.
	 *
	 * @param array<string, mixed> $feedback_item Feedback item.
	 * @param int                  $post_id       Post ID.
	 * @param int                  $parent_id     Parent note ID.
	 * @param array<string, mixed> $review_data   Review data.
	 * @return int|\WP_Error
	 */
	public function add_reply_to_thread( array $feedback_item, int $post_id, int $parent_id, array $review_data = array() ): int|\WP_Error {
		return 999;
	}
}

/**
 * Process endpoint and claim guard tests.
 */
class ReplyProcessTest extends TestCase {

	/**
	 * Reply service under test.
	 *
	 * @var Counting_Reply_Service
	 */
	private Counting_Reply_Service $service;

	/**
	 * Controller under test.
	 *
	 * @var Reply_Status_Controller
	 */
	private Reply_Status_Controller $controller;

	/**
	 * Seed an AI note (100) and a pending user reply (200) on post 42.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['test_current_user_can'] = true;
		$GLOBALS['test_options']          = array();
		$GLOBALS['test_comments']         = array(
			100 => new \WP_Comment(
				array(
					'comment_ID'      => '100',
					'comment_post_ID' => '42',
					'comment_content' => 'Cite the 40% figure.',
					'comment_author'  => 'Copydesk AI',
				)
			),
			200 => new \WP_Comment(
				array(
					'comment_ID'      => '200',
					'comment_post_ID' => '42',
					'comment_parent'  => '100',
					'comment_content' => 'It is cited in paragraph three.',
					'comment_author'  => 'Jane',
				)
			),
		);
		$GLOBALS['test_comment_meta']     = array(
			100 => array( 'ai_feedback' => '1' ),
			200 => array(
				Reply_Cron_Dispatcher::STATUS_META_KEY        => 'pending',
				Reply_Cron_Dispatcher::PENDING_SINCE_META_KEY => time(),
				Reply_Cron_Dispatcher::JOB_META_KEY           => array( 100, 42, 'abc-123' ),
			),
		);
		$GLOBALS['test_scheduled_events'] = array(
			array(
				'timestamp' => time(),
				'hook'      => Reply_Cron_Dispatcher::CRON_HOOK,
				'args'      => array( 200, 100, 42, 'abc-123' ),
			),
		);

		$this->service    = new Counting_Reply_Service( new Prompt_Builder(), new Process_Fake_Notes_Manager() );
		$this->controller = new Reply_Status_Controller( $this->service );
	}

	/**
	 * Clean up globals.
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['test_current_user_can'],
			$GLOBALS['test_options'],
			$GLOBALS['test_comments'],
			$GLOBALS['test_comment_meta'],
			$GLOBALS['test_scheduled_events']
		);
		parent::tearDown();
	}

	/**
	 * Build a process request.
	 *
	 * @param int  $id    Reply comment ID.
	 * @param bool $retry Whether the user asked to retry.
	 * @return \WP_REST_Request
	 */
	private function request( int $id = 200, bool $retry = false ): \WP_REST_Request {
		$request = new \WP_REST_Request();
		$request->set_param( 'id', $id );
		$request->set_param( 'retry', $retry );
		return $request;
	}

	/**
	 * Status meta of the seeded reply.
	 *
	 * @return mixed
	 */
	private function reply_status(): mixed {
		return $GLOBALS['test_comment_meta'][200][ Reply_Cron_Dispatcher::STATUS_META_KEY ] ?? null;
	}

	/**
	 * The endpoint answers a pending reply within the request.
	 */
	public function test_process_answers_pending_reply(): void {
		$data = $this->controller->process_reply( $this->request() )->get_data();

		$this->assertSame( 1, $this->service->ai_calls );
		$this->assertSame( 'complete', $data['status'] );
		$this->assertSame( 999, $data['ai_reply_comment_id'] );
		$this->assertSame( 'complete', $this->reply_status() );
	}

	/**
	 * The fallback cron event is dropped once the editor has answered.
	 */
	public function test_process_unschedules_the_cron_event(): void {
		$this->controller->process_reply( $this->request() );

		$this->assertSame( array(), array_values( $GLOBALS['test_scheduled_events'] ) );
	}

	/**
	 * A second request does not answer the same reply again.
	 */
	public function test_process_twice_answers_once(): void {
		$this->controller->process_reply( $this->request() );
		$data = $this->controller->process_reply( $this->request() )->get_data();

		$this->assertSame( 1, $this->service->ai_calls );
		$this->assertSame( 'complete', $data['status'] );
	}

	/**
	 * A late cron run does not answer a reply the endpoint already answered.
	 */
	public function test_cron_after_process_does_not_answer_again(): void {
		$this->controller->process_reply( $this->request() );
		$this->service->on_reply_received( 200, 100, 42, 'abc-123' );

		$this->assertSame( 1, $this->service->ai_calls );
	}

	/**
	 * The endpoint does not answer a reply cron already answered.
	 */
	public function test_process_after_cron_does_not_answer_again(): void {
		$this->service->on_reply_received( 200, 100, 42, 'abc-123' );
		$data = $this->controller->process_reply( $this->request() )->get_data();

		$this->assertSame( 1, $this->service->ai_calls );
		$this->assertSame( 'complete', $data['status'] );
	}

	/**
	 * A reply another request is answering is left alone, even on retry.
	 */
	public function test_process_skips_reply_claimed_elsewhere(): void {
		$GLOBALS['test_comment_meta'][200][ Reply_Cron_Dispatcher::STATUS_META_KEY ]     = 'processing';
		$GLOBALS['test_comment_meta'][200][ Reply_Cron_Dispatcher::CLAIMED_AT_META_KEY ] = time();

		$data = $this->controller->process_reply( $this->request( 200, true ) )->get_data();
		$this->service->on_reply_received( 200, 100, 42, 'abc-123' );

		$this->assertSame( 0, $this->service->ai_calls );
		$this->assertSame( 'pending', $data['status'], 'A claimed reply is still reported as pending.' );
	}

	/**
	 * A retry takes over a claim whose request has died.
	 */
	public function test_retry_reclaims_stale_claim(): void {
		$GLOBALS['test_comment_meta'][200][ Reply_Cron_Dispatcher::STATUS_META_KEY ]     = 'processing';
		$GLOBALS['test_comment_meta'][200][ Reply_Cron_Dispatcher::CLAIMED_AT_META_KEY ] = time() - Reply_Cron_Dispatcher::CLAIM_TIMEOUT - 1;

		$this->controller->process_reply( $this->request() );
		$this->assertSame( 0, $this->service->ai_calls, 'Only an explicit retry takes over a claim.' );

		$data = $this->controller->process_reply( $this->request( 200, true ) )->get_data();
		$this->assertSame( 1, $this->service->ai_calls );
		$this->assertSame( 'complete', $data['status'] );
	}

	/**
	 * A retry answers a failed reply; a plain request or cron does not.
	 */
	public function test_retry_answers_failed_reply(): void {
		$GLOBALS['test_comment_meta'][200][ Reply_Cron_Dispatcher::STATUS_META_KEY ]      = 'failed';
		$GLOBALS['test_comment_meta'][200][ Reply_Cron_Dispatcher::REPLY_ERROR_META_KEY ] = 'Timed out.';

		$this->controller->process_reply( $this->request() );
		$this->service->on_reply_received( 200, 100, 42, 'abc-123' );
		$this->assertSame( 0, $this->service->ai_calls );

		$data = $this->controller->process_reply( $this->request( 200, true ) )->get_data();
		$this->assertSame( 1, $this->service->ai_calls );
		$this->assertSame( 'complete', $data['status'] );
		$this->assertArrayNotHasKey( 'error', $data );
	}

	/**
	 * A reply that was never queued is not answered.
	 */
	public function test_process_ignores_reply_without_job(): void {
		$GLOBALS['test_comment_meta'][200] = array();

		$data = $this->controller->process_reply( $this->request( 200, true ) )->get_data();

		$this->assertSame( 0, $this->service->ai_calls );
		$this->assertSame( 'unknown', $data['status'] );
		$this->assertSame( array(), $GLOBALS['test_comment_meta'][200] );
	}

	/**
	 * A reply being answered is reported as pending by the status endpoint.
	 */
	public function test_status_reports_claimed_reply_as_pending(): void {
		$GLOBALS['test_comment_meta'][200][ Reply_Cron_Dispatcher::STATUS_META_KEY ] = 'processing';

		$request = new \WP_REST_Request();
		$request->set_param( 'id', 200 );
		$data = $this->controller->get_status( $request )->get_data();

		$this->assertSame( 'pending', $data['status'] );
	}

	/**
	 * The process endpoint uses the same permission check as status.
	 */
	public function test_process_permissions_reject_unauthorized_user(): void {
		$GLOBALS['test_current_user_can'] = false;

		$result = $this->controller->process_reply_permissions_check( $this->request() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 0, $this->service->ai_calls );
	}
}
