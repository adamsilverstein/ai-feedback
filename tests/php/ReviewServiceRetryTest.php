<?php
/**
 * Tests for Review_Service retry logic.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Review_Service;
use WP_Error;
use ReflectionClass;
use ReflectionMethod;

/**
 * Testable subclass of Review_Service that allows controlling call_ai behavior.
 */
class TestableReviewService extends Review_Service {

	/**
	 * Queue of responses to return from call_ai.
	 *
	 * @var array
	 */
	public array $call_ai_responses = array();

	/**
	 * Counter for call_ai invocations.
	 *
	 * @var int
	 */
	public int $call_ai_count = 0;

	/**
	 * Constructor that skips parent dependencies.
	 */
	public function __construct() {
		// Skip parent constructor to avoid loading dependencies.
	}

	/**
	 * Override call_ai to return queued responses.
	 *
	 * @param string $prompt             User prompt.
	 * @param string $system_instruction System instruction.
	 * @param string $model              Model to use.
	 * @return string|WP_Error Response from queue.
	 */
	protected function call_ai( string $prompt, string $system_instruction, string $model ): string|WP_Error {
		$this->call_ai_count++;
		return array_shift( $this->call_ai_responses ) ?? new WP_Error( 'no_response', 'No response queued' );
	}

	/**
	 * Expose call_ai_with_retry for testing.
	 *
	 * @param string $prompt             User prompt.
	 * @param string $system_instruction System instruction.
	 * @param string $model              Model to use.
	 * @param int    $max_retries        Maximum retry attempts.
	 * @return string|WP_Error Response.
	 */
	public function test_call_ai_with_retry(
		string $prompt,
		string $system_instruction,
		string $model,
		int $max_retries = 3
	): string|WP_Error {
		return $this->call_ai_with_retry( $prompt, $system_instruction, $model, $max_retries );
	}

	/**
	 * Expose extract_error_code_from_exception for testing.
	 *
	 * @param \Exception $e Exception to analyze.
	 * @return string Error code.
	 */
	public function test_extract_error_code( \Exception $e ): string {
		return $this->extract_error_code_from_exception( $e );
	}
}

/**
 * Test cases for call_ai_with_retry() method.
 */
class ReviewServiceRetryTest extends TestCase {

	/**
	 * Testable review service instance.
	 *
	 * @var TestableReviewService
	 */
	private TestableReviewService $service;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new TestableReviewService();
	}

	/**
	 * Test that NON_RETRYABLE_ERRORS constant contains expected error codes.
	 */
	public function test_non_retryable_errors_constant(): void {
		$expected = array(
			'rate_limit_exceeded',
			'invalid_api_key',
			'billing_error',
		);

		$this->assertSame( $expected, Review_Service::NON_RETRYABLE_ERRORS );
	}

	/**
	 * Test extract_error_code_from_exception with rate limit error.
	 */
	public function test_extract_error_code_rate_limit(): void {
		$exception = new \Exception( 'Rate limit exceeded. Please try again later.' );
		$code      = $this->service->test_extract_error_code( $exception );

		$this->assertSame( 'rate_limit_exceeded', $code );
	}

	/**
	 * Test extract_error_code_from_exception with rate_limit in message.
	 */
	public function test_extract_error_code_rate_limit_underscore(): void {
		$exception = new \Exception( 'Error: rate_limit reached' );
		$code      = $this->service->test_extract_error_code( $exception );

		$this->assertSame( 'rate_limit_exceeded', $code );
	}

	/**
	 * Test extract_error_code_from_exception with too many requests.
	 */
	public function test_extract_error_code_too_many_requests(): void {
		$exception = new \Exception( 'Too many requests. Slow down!' );
		$code      = $this->service->test_extract_error_code( $exception );

		$this->assertSame( 'rate_limit_exceeded', $code );
	}

	/**
	 * Test extract_error_code_from_exception with invalid API key.
	 */
	public function test_extract_error_code_invalid_api_key(): void {
		$exception = new \Exception( 'Invalid API key provided.' );
		$code      = $this->service->test_extract_error_code( $exception );

		$this->assertSame( 'invalid_api_key', $code );
	}

	/**
	 * Test extract_error_code_from_exception with unauthorized.
	 */
	public function test_extract_error_code_unauthorized(): void {
		$exception = new \Exception( 'Unauthorized access to API.' );
		$code      = $this->service->test_extract_error_code( $exception );

		$this->assertSame( 'invalid_api_key', $code );
	}

	/**
	 * Test extract_error_code_from_exception with authentication error.
	 */
	public function test_extract_error_code_authentication(): void {
		$exception = new \Exception( 'Authentication failed. Check your credentials.' );
		$code      = $this->service->test_extract_error_code( $exception );

		$this->assertSame( 'invalid_api_key', $code );
	}

	/**
	 * Test extract_error_code_from_exception with billing error.
	 */
	public function test_extract_error_code_billing(): void {
		$exception = new \Exception( 'Billing error: payment method declined.' );
		$code      = $this->service->test_extract_error_code( $exception );

		$this->assertSame( 'billing_error', $code );
	}

	/**
	 * Test extract_error_code_from_exception with quota exceeded.
	 */
	public function test_extract_error_code_quota_exceeded(): void {
		$exception = new \Exception( 'Quota exceeded for the current billing period.' );
		$code      = $this->service->test_extract_error_code( $exception );

		$this->assertSame( 'billing_error', $code );
	}

	/**
	 * Test extract_error_code_from_exception with insufficient funds.
	 */
	public function test_extract_error_code_insufficient(): void {
		$exception = new \Exception( 'Insufficient credits remaining.' );
		$code      = $this->service->test_extract_error_code( $exception );

		$this->assertSame( 'billing_error', $code );
	}

	/**
	 * Test extract_error_code_from_exception with generic error.
	 */
	public function test_extract_error_code_generic(): void {
		$exception = new \Exception( 'Something went wrong on the server.' );
		$code      = $this->service->test_extract_error_code( $exception );

		$this->assertSame( 'ai_request_failed', $code );
	}

	/**
	 * Test that retry succeeds on first attempt.
	 */
	public function test_retry_succeeds_first_attempt(): void {
		$mock_response = '{"feedback": []}';

		$this->service->call_ai_responses = array( $mock_response );

		$result = $this->service->test_call_ai_with_retry(
			'test prompt',
			'test system instruction',
			'test-model',
			3
		);

		$this->assertSame( $mock_response, $result );
		$this->assertSame( 1, $this->service->call_ai_count );
	}

	/**
	 * Test that retry succeeds after transient failure.
	 */
	public function test_retry_succeeds_after_transient_failure(): void {
		$mock_response   = '{"feedback": []}';
		$transient_error = new WP_Error( 'ai_request_failed', 'Network timeout' );

		// Fail once, then succeed.
		$this->service->call_ai_responses = array( $transient_error, $mock_response );

		$result = $this->service->test_call_ai_with_retry(
			'test prompt',
			'test system instruction',
			'test-model',
			3
		);

		$this->assertSame( $mock_response, $result );
		$this->assertSame( 2, $this->service->call_ai_count );
	}

	/**
	 * Test that non-retryable error fails immediately without retry.
	 */
	public function test_non_retryable_error_fails_immediately(): void {
		$non_retryable_error = new WP_Error( 'invalid_api_key', 'Invalid API key' );

		$this->service->call_ai_responses = array( $non_retryable_error );

		$result = $this->service->test_call_ai_with_retry(
			'test prompt',
			'test system instruction',
			'test-model',
			3
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_api_key', $result->get_error_code() );
		// Should only be called once - no retries for non-retryable errors.
		$this->assertSame( 1, $this->service->call_ai_count );
	}

	/**
	 * Test that rate_limit_exceeded error fails immediately.
	 */
	public function test_rate_limit_exceeded_fails_immediately(): void {
		$error = new WP_Error( 'rate_limit_exceeded', 'Rate limit exceeded' );

		$this->service->call_ai_responses = array( $error );

		$result = $this->service->test_call_ai_with_retry(
			'test prompt',
			'test system instruction',
			'test-model',
			3
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rate_limit_exceeded', $result->get_error_code() );
		$this->assertSame( 1, $this->service->call_ai_count );
	}

	/**
	 * Test that billing_error fails immediately.
	 */
	public function test_billing_error_fails_immediately(): void {
		$error = new WP_Error( 'billing_error', 'Payment required' );

		$this->service->call_ai_responses = array( $error );

		$result = $this->service->test_call_ai_with_retry(
			'test prompt',
			'test system instruction',
			'test-model',
			3
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'billing_error', $result->get_error_code() );
		$this->assertSame( 1, $this->service->call_ai_count );
	}

	/**
	 * Test that retries are exhausted after max_retries.
	 */
	public function test_exhausts_retries_and_returns_last_error(): void {
		$error = new WP_Error( 'ai_request_failed', 'Server error' );

		// With max_retries=3, should call 4 times (initial + 3 retries).
		$this->service->call_ai_responses = array( $error, $error, $error, $error );

		$result = $this->service->test_call_ai_with_retry(
			'test prompt',
			'test system instruction',
			'test-model',
			3
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ai_request_failed', $result->get_error_code() );
		$this->assertSame( 4, $this->service->call_ai_count );
	}

	/**
	 * Test that max_retries=0 only makes one attempt.
	 */
	public function test_zero_retries_makes_one_attempt(): void {
		$error = new WP_Error( 'ai_request_failed', 'Server error' );

		$this->service->call_ai_responses = array( $error );

		$result = $this->service->test_call_ai_with_retry(
			'test prompt',
			'test system instruction',
			'test-model',
			0
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 1, $this->service->call_ai_count );
	}

	/**
	 * Test that custom max_retries is respected.
	 */
	public function test_custom_max_retries(): void {
		$error = new WP_Error( 'ai_request_failed', 'Server error' );

		// With max_retries=5, should call 6 times.
		$this->service->call_ai_responses = array( $error, $error, $error, $error, $error, $error );

		$result = $this->service->test_call_ai_with_retry(
			'test prompt',
			'test system instruction',
			'test-model',
			5
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 6, $this->service->call_ai_count );
	}

	/**
	 * Test that retry eventually succeeds after multiple failures.
	 */
	public function test_retry_succeeds_after_multiple_failures(): void {
		$mock_response = '{"feedback": []}';
		$error         = new WP_Error( 'ai_request_failed', 'Server error' );

		// Fail 3 times, then succeed on 4th attempt.
		$this->service->call_ai_responses = array( $error, $error, $error, $mock_response );

		$result = $this->service->test_call_ai_with_retry(
			'test prompt',
			'test system instruction',
			'test-model',
			3
		);

		$this->assertSame( $mock_response, $result );
		$this->assertSame( 4, $this->service->call_ai_count );
	}
}
