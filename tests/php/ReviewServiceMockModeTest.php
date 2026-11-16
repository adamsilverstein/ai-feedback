<?php
/**
 * Tests for the AI_FEEDBACK_MOCK_MODE check in Review_Service::review_document().
 *
 * Runs in separate processes because each test defines AI_FEEDBACK_MOCK_MODE,
 * which can't be undefined afterwards.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use AI_Feedback\Notes_Manager;
use AI_Feedback\Review_Service;
use ReflectionProperty;
use WP_Error;

/**
 * Records AI calls and fails them so the review stops before creating notes.
 */
class MockModeReviewService extends Review_Service {

	/**
	 * Number of call_ai_with_retry() calls.
	 *
	 * @var int
	 */
	public int $ai_calls = 0;

	/**
	 * Record the call and fail it.
	 *
	 * @param  string $prompt             User prompt.
	 * @param  string $system_instruction System instruction.
	 * @param  string $model              Model to use.
	 * @param  int    $max_retries        Maximum number of retry attempts.
	 * @return string|WP_Error Always an error.
	 */
	protected function call_ai_with_retry(
		string $prompt,
		string $system_instruction,
		string $model,
		int $max_retries = 3
	): string|WP_Error {
		++$this->ai_calls;
		return new WP_Error( 'invalid_request', 'Bad request.' );
	}
}

/**
 * Notes manager that skips note creation.
 */
class NoNotesManager extends Notes_Manager {

	/**
	 * Fail note creation so the review returns without touching comments.
	 *
	 * @param  array<int, array<string, mixed>> $feedback_items Feedback items.
	 * @param  int                              $post_id        Post ID.
	 * @param  array<string, mixed>             $review_data    Review data.
	 * @return WP_Error Always an error.
	 */
	public function create_notes_from_feedback( array $feedback_items, int $post_id, array $review_data = array() ): array|WP_Error {
		return new WP_Error( 'skipped', 'Notes skipped.' );
	}
}

/**
 * Test cases for mock mode in review_document().
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class ReviewServiceMockModeTest extends TestCase {

	/**
	 * Mock mode defined as false, as copydesk-ai.php does in production,
	 * still calls the AI.
	 */
	public function test_mock_mode_false_calls_ai(): void {
		define( 'AI_FEEDBACK_MOCK_MODE', false );
		$service = new MockModeReviewService();

		$result = $service->review_document(
			1,
			array(
				'post_title' => 'Mock mode',
				'blocks'     => array(
					array(
						'clientId' => 'block-1',
						'name'     => 'core/paragraph',
						'content'  => 'Revenue grew 40% last year.',
					),
				),
			)
		);

		$this->assertSame( 1, $service->ai_calls );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_request', $result->get_error_code() );
	}

	/**
	 * The review timestamp is saved in UTC with an explicit offset, so the
	 * editor can show it in the site timezone whatever the browser's is.
	 */
	public function test_review_timestamp_is_utc_with_offset(): void {
		define( 'AI_FEEDBACK_MOCK_MODE', true );
		$GLOBALS['test_now'] = gmmktime( 4, 46, 0, 11, 14, 2026 );
		$service             = new MockModeReviewService();
		( new ReflectionProperty( Review_Service::class, 'notes_manager' ) )->setValue( $service, new NoNotesManager() );

		$result = $service->review_document(
			1,
			array(
				'post_title' => 'Timestamp',
				'blocks'     => array(
					array(
						'clientId' => 'block-1',
						'name'     => 'core/paragraph',
						'content'  => 'Revenue grew 40% last year.',
					),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( '2026-11-14T04:46:00+00:00', $result['timestamp'] );
	}
}
