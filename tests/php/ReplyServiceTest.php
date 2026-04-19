<?php
/**
 * Tests for Reply_Service.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Reply_Service;
use AI_Feedback\Prompt_Builder;
use AI_Feedback\Notes_Manager;

require_once dirname( __DIR__, 2 ) . '/includes/class-reply-cron-dispatcher.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-reply-service.php';
require_once __DIR__ . '/reply-service-mocks.php';
require_once __DIR__ . '/reply-cron-mocks.php';

/**
 * Subclass that captures the prompt and returns a canned AI reply.
 */
class Reply_Service_Stub extends Reply_Service {

	public string $captured_prompt             = '';
	public string $captured_system_instruction = '';
	public string $captured_model              = '';
	public string $canned_response             = 'OK — replace "40% growth" with "40% growth (Source: Stripe dashboard)".';

	protected function call_ai( string $prompt, string $system_instruction, string $model ): string|\WP_Error {
		$this->captured_prompt             = $prompt;
		$this->captured_system_instruction = $system_instruction;
		$this->captured_model              = $model;
		return $this->canned_response;
	}
}

/**
 * Fake Notes_Manager that records the add_reply_to_thread call.
 */
class Reply_Service_Fake_Notes_Manager extends Notes_Manager {

	public array $last_reply = array();

	public function add_reply_to_thread( array $feedback_item, int $post_id, int $parent_id, array $review_data = array() ): int|\WP_Error {
		$this->last_reply = array(
			'feedback_item' => $feedback_item,
			'post_id'       => $post_id,
			'parent_id'     => $parent_id,
			'review_data'   => $review_data,
		);
		return 999;
	}
}

/**
 * Reply_Service test cases.
 */
class ReplyServiceTest extends TestCase {

	/**
	 * Reset the fake-WP state before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['test_comments']       = array();
		$GLOBALS['test_comment_meta']   = array();
		$GLOBALS['test_options']        = array();
	}

	/**
	 * Tear down state.
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['test_comments'],
			$GLOBALS['test_comment_meta'],
			$GLOBALS['test_options']
		);
		parent::tearDown();
	}

	/**
	 * End-to-end: context built, prompt generated, AI reply persisted.
	 */
	public function test_handle_reply_persists_ai_reply_as_thread_child(): void {
		// Parent: AI note on a paragraph block with original feedback.
		$GLOBALS['test_comments'][100] = (object) array(
			'comment_ID'      => 100,
			'comment_content' => 'Add a source for the 40% growth claim.',
			'comment_author'  => 'AI Feedback',
		);
		$GLOBALS['test_comment_meta'][100] = array(
			'ai_feedback'        => '1',
			'block_name'         => 'core/paragraph',
			'feedback_category'  => 'content',
			'feedback_severity'  => 'important',
		);

		// Reply: user asking for clarification.
		$GLOBALS['test_comments'][200] = (object) array(
			'comment_ID'      => 200,
			'comment_content' => 'I already cite it in paragraph 3.',
			'comment_author'  => 'Jane',
		);

		// Prior thread entry (an earlier user comment, chronologically first).
		$GLOBALS['test_comments'][150] = (object) array(
			'comment_ID'      => 150,
			'comment_content' => 'Which stat?',
			'comment_author'  => 'Jane',
			'comment_parent'  => 100,
		);
		$GLOBALS['test_comments'][200]->comment_parent = 100;

		$GLOBALS['test_options']['ai_feedback_default_model'] = 'claude-sonnet-4';
		$GLOBALS['test_options']['ai_feedback_default_tone']  = 'professional';

		$fake_notes = new Reply_Service_Fake_Notes_Manager();
		$service    = new Reply_Service_Stub( new Prompt_Builder(), $fake_notes );

		$result = $service->handle_reply( 200, 100, 42, 'abc-123' );

		// Returns the fake Notes_Manager's stubbed insert ID.
		$this->assertSame( 999, $result );

		// The prompt carried the user reply, original feedback, and prior thread entry.
		$this->assertStringContainsString( 'I already cite it in paragraph 3.', $service->captured_prompt );
		$this->assertStringContainsString( 'Add a source for the 40% growth claim.', $service->captured_prompt );
		$this->assertStringContainsString( 'Which stat?', $service->captured_prompt );
		$this->assertStringContainsString( '[content/important]', $service->captured_prompt );

		// Model + system instruction routed through.
		$this->assertSame( 'claude-sonnet-4', $service->captured_model );
		$this->assertStringContainsString( 'Plain text only', $service->captured_system_instruction );

		// Persisted as a child of the parent note with the canned AI reply.
		$this->assertSame( 100, $fake_notes->last_reply['parent_id'] );
		$this->assertSame( 42, $fake_notes->last_reply['post_id'] );
		$this->assertSame( $service->canned_response, $fake_notes->last_reply['feedback_item']['feedback'] );
		$this->assertSame( 'abc-123', $fake_notes->last_reply['feedback_item']['block_id'] );
		$this->assertSame( 'claude-sonnet-4', $fake_notes->last_reply['review_data']['model'] );
	}

	/**
	 * The user's own reply is excluded from the thread history so the
	 * prompt doesn't echo them back to themselves.
	 */
	public function test_thread_history_excludes_the_current_reply(): void {
		$GLOBALS['test_comments'][100] = (object) array(
			'comment_ID'      => 100,
			'comment_content' => 'Vague claim.',
			'comment_author'  => 'AI Feedback',
		);
		$GLOBALS['test_comment_meta'][100] = array( 'ai_feedback' => '1' );

		// The reply itself is the only sibling of the parent.
		$GLOBALS['test_comments'][200] = (object) array(
			'comment_ID'      => 200,
			'comment_content' => 'SHOULD_NOT_APPEAR_IN_THREAD',
			'comment_author'  => 'Jane',
			'comment_parent'  => 100,
		);

		$service = new Reply_Service_Stub( new Prompt_Builder(), new Reply_Service_Fake_Notes_Manager() );

		$service->handle_reply( 200, 100, 42, 'abc-123' );

		// The reply appears in the NEW USER REPLY section but not the thread history.
		$this->assertStringContainsString( 'NEW USER REPLY:', $service->captured_prompt );
		$this->assertStringContainsString( 'SHOULD_NOT_APPEAR_IN_THREAD', $service->captured_prompt );

		$before_reply_section = substr(
			$service->captured_prompt,
			0,
			strpos( $service->captured_prompt, 'NEW USER REPLY:' )
		);

		// The sentinel text from the reply should not appear in any pre-NEW-USER-REPLY section.
		$this->assertStringNotContainsString(
			'SHOULD_NOT_APPEAR_IN_THREAD',
			$before_reply_section
		);
	}

	/**
	 * Missing parent surfaces a WP_Error instead of crashing.
	 */
	public function test_missing_parent_returns_wp_error(): void {
		$GLOBALS['test_comments'][200] = (object) array(
			'comment_ID'      => 200,
			'comment_content' => 'Reply text',
			'comment_author'  => 'Jane',
		);

		$service = new Reply_Service_Stub( new Prompt_Builder(), new Reply_Service_Fake_Notes_Manager() );

		$result = $service->handle_reply( 200, 999, 42, 'abc-123' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'parent_not_found', $result->get_error_code() );
	}

	/**
	 * Missing reply comment surfaces a WP_Error.
	 */
	public function test_missing_reply_returns_wp_error(): void {
		$GLOBALS['test_comments'][100] = (object) array(
			'comment_ID'      => 100,
			'comment_content' => 'Original feedback.',
			'comment_author'  => 'AI Feedback',
		);

		$service = new Reply_Service_Stub( new Prompt_Builder(), new Reply_Service_Fake_Notes_Manager() );

		$result = $service->handle_reply( 999, 100, 42, 'abc-123' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'reply_not_found', $result->get_error_code() );
	}

	/**
	 * AI failure propagates as a WP_Error and the reply is not persisted.
	 */
	public function test_ai_failure_returns_wp_error_and_skips_persistence(): void {
		$GLOBALS['test_comments'][100] = (object) array(
			'comment_ID'      => 100,
			'comment_content' => 'Original feedback.',
			'comment_author'  => 'AI Feedback',
		);
		$GLOBALS['test_comment_meta'][100] = array( 'ai_feedback' => '1' );
		$GLOBALS['test_comments'][200]     = (object) array(
			'comment_ID'      => 200,
			'comment_content' => 'User reply.',
			'comment_author'  => 'Jane',
			'comment_parent'  => 100,
		);

		$fake_notes = new Reply_Service_Fake_Notes_Manager();
		$service    = new class( new Prompt_Builder(), $fake_notes ) extends Reply_Service {
			protected function call_ai( string $prompt, string $system_instruction, string $model ): string|\WP_Error {
				return new \WP_Error( 'ai_request_failed', 'simulated provider failure' );
			}
		};

		$result = $service->handle_reply( 200, 100, 42, 'abc-123' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_request_failed', $result->get_error_code() );
		$this->assertSame( array(), $fake_notes->last_reply, 'Notes_Manager must not be called when the AI fails.' );
	}

	/**
	 * on_reply_received records 'complete' status meta and the resulting AI
	 * reply comment ID when handle_reply succeeds.
	 */
	public function test_on_reply_received_records_complete_status_on_success(): void {
		$GLOBALS['test_comments'][100] = (object) array(
			'comment_ID'      => 100,
			'comment_content' => 'Vague.',
			'comment_author'  => 'AI Feedback',
		);
		$GLOBALS['test_comment_meta'][100] = array( 'ai_feedback' => '1' );
		$GLOBALS['test_comments'][200]     = (object) array(
			'comment_ID'      => 200,
			'comment_content' => 'Intentional.',
			'comment_author'  => 'Jane',
			'comment_parent'  => 100,
		);

		$service = new Reply_Service_Stub( new Prompt_Builder(), new Reply_Service_Fake_Notes_Manager() );

		$service->on_reply_received( 200, 100, 42, 'abc-123' );

		$this->assertSame(
			'complete',
			$GLOBALS['test_comment_meta'][200][ \AI_Feedback\Reply_Cron_Dispatcher::STATUS_META_KEY ]
		);
		$this->assertSame(
			999,
			$GLOBALS['test_comment_meta'][200]['ai_feedback_reply_comment_id']
		);
	}

	/**
	 * on_reply_received records 'failed' status with the error message when
	 * handle_reply returns WP_Error (e.g. missing parent).
	 */
	public function test_on_reply_received_records_failed_status_on_error(): void {
		$GLOBALS['test_comments'][200] = (object) array(
			'comment_ID'      => 200,
			'comment_content' => 'Reply without parent.',
			'comment_author'  => 'Jane',
		);

		$service = new Reply_Service_Stub( new Prompt_Builder(), new Reply_Service_Fake_Notes_Manager() );

		$service->on_reply_received( 200, 999, 42, 'abc-123' );

		$this->assertSame(
			'failed',
			$GLOBALS['test_comment_meta'][200][ \AI_Feedback\Reply_Cron_Dispatcher::STATUS_META_KEY ]
		);
		$this->assertNotEmpty(
			$GLOBALS['test_comment_meta'][200]['ai_feedback_reply_error'] ?? null
		);
	}
}
