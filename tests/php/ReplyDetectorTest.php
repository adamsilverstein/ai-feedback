<?php
/**
 * Tests for Reply_Detector.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Reply_Detector;

require_once dirname( __DIR__, 2 ) . '/includes/class-reply-detector.php';

/**
 * Test cases for Reply_Detector.
 */
class ReplyDetectorTest extends TestCase {

	/**
	 * Detector instance.
	 */
	private Reply_Detector $detector;

	/**
	 * Set up global mocks for comment meta and action dispatch.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->detector = new Reply_Detector();

		$GLOBALS['test_comment_meta']     = array();
		$GLOBALS['test_dispatched_calls'] = array();

		if ( ! function_exists( 'AI_Feedback\\Tests\\__register_mocks' ) ) {
			require_once __DIR__ . '/reply-detector-mocks.php';
		}
	}

	/**
	 * Clean up globals after each test.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['test_comment_meta'], $GLOBALS['test_dispatched_calls'] );
		parent::tearDown();
	}

	/**
	 * A user reply to an AI note dispatches the action.
	 */
	public function test_user_reply_to_ai_note_dispatches_action(): void {
		// Parent (comment 100) is an AI Feedback note.
		$GLOBALS['test_comment_meta'][100] = array(
			'ai_feedback' => '1',
			'block_id'    => 'abc-123',
		);

		$reply = (object) array(
			'comment_type'    => 'note',
			'comment_parent'  => 100,
			'comment_post_ID' => 42,
		);

		$this->detector->maybe_dispatch_reply( 200, $reply );

		$dispatched = $GLOBALS['test_dispatched_calls'];
		$this->assertCount( 1, $dispatched );
		$this->assertSame( Reply_Detector::ACTION_REPLY_RECEIVED, $dispatched[0]['hook'] );
		$this->assertSame( array( 200, 100, 42, 'abc-123' ), $dispatched[0]['args'] );
	}

	/**
	 * A reply to a non-AI note is ignored.
	 */
	public function test_reply_to_non_ai_note_is_ignored(): void {
		// Parent has no ai_feedback meta.
		$GLOBALS['test_comment_meta'][100] = array();

		$reply = (object) array(
			'comment_type'    => 'note',
			'comment_parent'  => 100,
			'comment_post_ID' => 42,
		);

		$this->detector->maybe_dispatch_reply( 200, $reply );

		$this->assertCount( 0, $GLOBALS['test_dispatched_calls'] );
	}

	/**
	 * A top-level (non-reply) block comment is ignored.
	 */
	public function test_top_level_comment_is_ignored(): void {
		$comment = (object) array(
			'comment_type'    => 'note',
			'comment_parent'  => 0,
			'comment_post_ID' => 42,
		);

		$this->detector->maybe_dispatch_reply( 200, $comment );

		$this->assertCount( 0, $GLOBALS['test_dispatched_calls'] );
	}

	/**
	 * A reply authored by AI Feedback itself does not re-trigger detection.
	 */
	public function test_ai_authored_reply_is_ignored(): void {
		$GLOBALS['test_comment_meta'][100] = array( 'ai_feedback' => '1' );
		// The reply itself is flagged as ai_feedback — avoids loops.
		$GLOBALS['test_comment_meta'][200] = array( 'ai_feedback' => '1' );

		$reply = (object) array(
			'comment_type'    => 'note',
			'comment_parent'  => 100,
			'comment_post_ID' => 42,
		);

		$this->detector->maybe_dispatch_reply( 200, $reply );

		$this->assertCount( 0, $GLOBALS['test_dispatched_calls'] );
	}

	/**
	 * A non-block-comment reply (e.g. a regular post comment) is ignored.
	 */
	public function test_non_note_is_ignored(): void {
		$GLOBALS['test_comment_meta'][100] = array( 'ai_feedback' => '1' );

		$comment = (object) array(
			'comment_type'    => 'comment',
			'comment_parent'  => 100,
			'comment_post_ID' => 42,
		);

		$this->detector->maybe_dispatch_reply( 200, $comment );

		$this->assertCount( 0, $GLOBALS['test_dispatched_calls'] );
	}

	/**
	 * A reply whose parent has no block_id meta is ignored — consumers
	 * cannot resolve the target block, so dispatching with an empty
	 * block_id is worse than not dispatching at all.
	 */
	public function test_reply_without_parent_block_id_is_ignored(): void {
		$GLOBALS['test_comment_meta'][100] = array( 'ai_feedback' => '1' );

		$reply = (object) array(
			'comment_type'    => 'note',
			'comment_parent'  => 100,
			'comment_post_ID' => 42,
		);

		$this->detector->maybe_dispatch_reply( 200, $reply );

		$this->assertCount( 0, $GLOBALS['test_dispatched_calls'] );
	}

	/**
	 * A comment without a valid post association (post_id <= 0) is ignored.
	 */
	public function test_reply_with_invalid_post_id_is_ignored(): void {
		$GLOBALS['test_comment_meta'][100] = array(
			'ai_feedback' => '1',
			'block_id'    => 'abc-123',
		);

		$reply = (object) array(
			'comment_type'    => 'note',
			'comment_parent'  => 100,
			'comment_post_ID' => 0,
		);

		$this->detector->maybe_dispatch_reply( 200, $reply );

		$this->assertCount( 0, $GLOBALS['test_dispatched_calls'] );
	}

}
