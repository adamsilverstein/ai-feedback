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
use AI_Feedback\Review_Service;
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
 * Test cases for mock mode in review_document().
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class ReviewServiceMockModeTest extends TestCase {

	/**
	 * Mock mode defined as false, as ai-feedback.php does in production,
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
}
