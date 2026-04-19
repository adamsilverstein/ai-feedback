<?php
/**
 * Tests for Reply_Cron_Dispatcher.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Reply_Cron_Dispatcher;

require_once dirname( __DIR__, 2 ) . '/includes/class-reply-cron-dispatcher.php';
require_once __DIR__ . '/reply-cron-mocks.php';

/**
 * Cron dispatcher tests.
 */
class ReplyCronDispatcherTest extends TestCase {

	/**
	 * Dispatcher instance.
	 *
	 * @var Reply_Cron_Dispatcher
	 */
	private Reply_Cron_Dispatcher $dispatcher;

	/**
	 * Set up global state per test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->dispatcher                   = new Reply_Cron_Dispatcher();
		$GLOBALS['test_comment_meta']       = array();
		$GLOBALS['test_scheduled_events']   = array();
	}

	/**
	 * Reset state.
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['test_comment_meta'],
			$GLOBALS['test_scheduled_events']
		);
		parent::tearDown();
	}

	/**
	 * Scheduling marks pending and enqueues a cron event with the expected args.
	 */
	public function test_schedule_reply_marks_pending_and_schedules_event(): void {
		$this->dispatcher->schedule_reply( 200, 100, 42, 'abc-123' );

		$this->assertSame(
			'pending',
			$GLOBALS['test_comment_meta'][200][ Reply_Cron_Dispatcher::STATUS_META_KEY ] ?? null
		);

		$this->assertCount( 1, $GLOBALS['test_scheduled_events'] );
		$event = $GLOBALS['test_scheduled_events'][0];

		$this->assertSame( Reply_Cron_Dispatcher::CRON_HOOK, $event['hook'] );
		$this->assertSame( array( 200, 100, 42, 'abc-123' ), $event['args'] );
	}

	/**
	 * Scheduling twice for the same reply is a no-op — status is not overwritten
	 * and only one cron event is queued.
	 */
	public function test_schedule_reply_is_idempotent_on_existing_status(): void {
		// Simulate the first dispatch already having run and completed.
		$GLOBALS['test_comment_meta'][200] = array(
			Reply_Cron_Dispatcher::STATUS_META_KEY => 'complete',
		);

		$this->dispatcher->schedule_reply( 200, 100, 42, 'abc-123' );

		// Status must not be downgraded back to pending.
		$this->assertSame(
			'complete',
			$GLOBALS['test_comment_meta'][200][ Reply_Cron_Dispatcher::STATUS_META_KEY ]
		);
		$this->assertCount( 0, $GLOBALS['test_scheduled_events'] );
	}

	/**
	 * If an event is already queued, we don't queue a second one.
	 */
	public function test_schedule_reply_skips_when_event_already_queued(): void {
		$GLOBALS['test_scheduled_events'][] = array(
			'hook'      => Reply_Cron_Dispatcher::CRON_HOOK,
			'args'      => array( 200, 100, 42, 'abc-123' ),
			'timestamp' => 123,
		);

		$this->dispatcher->schedule_reply( 200, 100, 42, 'abc-123' );

		$this->assertCount( 1, $GLOBALS['test_scheduled_events'] );
		// And status stays empty because we short-circuited before update_comment_meta.
		$this->assertArrayNotHasKey( 200, $GLOBALS['test_comment_meta'] );
	}
}
