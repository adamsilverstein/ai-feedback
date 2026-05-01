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
class TestableReviewService extends Review_Service
{

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
    public function __construct()
    {
        // Skip parent constructor to avoid loading dependencies.
    }

    /**
     * Override call_ai to return queued responses.
     *
     * @param  string $prompt             User prompt.
     * @param  string $system_instruction System instruction.
     * @param  string $model              Model to use.
     * @return string|WP_Error Response from queue.
     */
    protected function call_ai( string $prompt, string $system_instruction, string $model ): string|WP_Error
    {
        $this->call_ai_count++;
        return array_shift($this->call_ai_responses) ?? new WP_Error('no_response', 'No response queued');
    }

    /**
     * Expose call_ai_with_retry for testing.
     *
     * @param  string $prompt             User prompt.
     * @param  string $system_instruction System instruction.
     * @param  string $model              Model to use.
     * @param  int    $max_retries        Maximum retry attempts.
     * @return string|WP_Error Response.
     */
    public function test_call_ai_with_retry(
        string $prompt,
        string $system_instruction,
        string $model,
        int $max_retries = 3
    ): string|WP_Error {
        return $this->call_ai_with_retry($prompt, $system_instruction, $model, $max_retries);
    }
}

/**
 * Test cases for call_ai_with_retry() method.
 */
class ReviewServiceRetryTest extends TestCase
{

    /**
     * Testable review service instance.
     *
     * @var TestableReviewService
     */
    private TestableReviewService $service;

    /**
     * Set up test fixtures.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TestableReviewService();
    }

    /**
     * Test that NON_RETRYABLE_ERRORS constant covers the legacy plugin codes
     * plus the broader set introduced for the WordPress 7.0 core AI Client.
     */
    public function test_non_retryable_errors_constant(): void
    {
        $codes = Review_Service::NON_RETRYABLE_ERRORS;

        $this->assertContains('rate_limit_exceeded', $codes);
        $this->assertContains('invalid_api_key', $codes);
        $this->assertContains('billing_error', $codes);
        $this->assertContains('http_request_failed', $codes);
    }

    /**
     * Test that retry succeeds on first attempt.
     */
    public function test_retry_succeeds_first_attempt(): void
    {
        $mock_response = '{"feedback": []}';

        $this->service->call_ai_responses = array( $mock_response );

        $result = $this->service->test_call_ai_with_retry(
            'test prompt',
            'test system instruction',
            'test-model',
            3
        );

        $this->assertSame($mock_response, $result);
        $this->assertSame(1, $this->service->call_ai_count);
    }

    /**
     * Test that retry succeeds after transient failure.
     */
    public function test_retry_succeeds_after_transient_failure(): void
    {
        $mock_response   = '{"feedback": []}';
        $transient_error = new WP_Error('ai_request_failed', 'Network timeout');

        // Fail once, then succeed.
        $this->service->call_ai_responses = array( $transient_error, $mock_response );

        $result = $this->service->test_call_ai_with_retry(
            'test prompt',
            'test system instruction',
            'test-model',
            3
        );

        $this->assertSame($mock_response, $result);
        $this->assertSame(2, $this->service->call_ai_count);
    }

    /**
     * Test that non-retryable error fails immediately without retry.
     */
    public function test_non_retryable_error_fails_immediately(): void
    {
        $non_retryable_error = new WP_Error('invalid_api_key', 'Invalid API key');

        $this->service->call_ai_responses = array( $non_retryable_error );

        $result = $this->service->test_call_ai_with_retry(
            'test prompt',
            'test system instruction',
            'test-model',
            3
        );

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_api_key', $result->get_error_code());
        // Should only be called once - no retries for non-retryable errors.
        $this->assertSame(1, $this->service->call_ai_count);
    }

    /**
     * Test that rate_limit_exceeded error fails immediately.
     */
    public function test_rate_limit_exceeded_fails_immediately(): void
    {
        $error = new WP_Error('rate_limit_exceeded', 'Rate limit exceeded');

        $this->service->call_ai_responses = array( $error );

        $result = $this->service->test_call_ai_with_retry(
            'test prompt',
            'test system instruction',
            'test-model',
            3
        );

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('rate_limit_exceeded', $result->get_error_code());
        $this->assertSame(1, $this->service->call_ai_count);
    }

    /**
     * Test that billing_error fails immediately.
     */
    public function test_billing_error_fails_immediately(): void
    {
        $error = new WP_Error('billing_error', 'Payment required');

        $this->service->call_ai_responses = array( $error );

        $result = $this->service->test_call_ai_with_retry(
            'test prompt',
            'test system instruction',
            'test-model',
            3
        );

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('billing_error', $result->get_error_code());
        $this->assertSame(1, $this->service->call_ai_count);
    }

    /**
     * Test that retries are exhausted after max_retries.
     */
    public function test_exhausts_retries_and_returns_last_error(): void
    {
        $error = new WP_Error('ai_request_failed', 'Server error');

        // With max_retries=3, should call 4 times (initial + 3 retries).
        $this->service->call_ai_responses = array( $error, $error, $error, $error );

        $result = $this->service->test_call_ai_with_retry(
            'test prompt',
            'test system instruction',
            'test-model',
            3
        );

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('ai_request_failed', $result->get_error_code());
        $this->assertSame(4, $this->service->call_ai_count);
    }

    /**
     * Test that max_retries=0 only makes one attempt.
     */
    public function test_zero_retries_makes_one_attempt(): void
    {
        $error = new WP_Error('ai_request_failed', 'Server error');

        $this->service->call_ai_responses = array( $error );

        $result = $this->service->test_call_ai_with_retry(
            'test prompt',
            'test system instruction',
            'test-model',
            0
        );

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(1, $this->service->call_ai_count);
    }

    /**
     * Test that custom max_retries is respected.
     */
    public function test_custom_max_retries(): void
    {
        $error = new WP_Error('ai_request_failed', 'Server error');

        // With max_retries=5, should call 6 times.
        $this->service->call_ai_responses = array( $error, $error, $error, $error, $error, $error );

        $result = $this->service->test_call_ai_with_retry(
            'test prompt',
            'test system instruction',
            'test-model',
            5
        );

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(6, $this->service->call_ai_count);
    }

    /**
     * Test that retry eventually succeeds after multiple failures.
     */
    public function test_retry_succeeds_after_multiple_failures(): void
    {
        $mock_response = '{"feedback": []}';
        $error         = new WP_Error('ai_request_failed', 'Server error');

        // Fail 3 times, then succeed on 4th attempt.
        $this->service->call_ai_responses = array( $error, $error, $error, $mock_response );

        $result = $this->service->test_call_ai_with_retry(
            'test prompt',
            'test system instruction',
            'test-model',
            3
        );

        $this->assertSame($mock_response, $result);
        $this->assertSame(4, $this->service->call_ai_count);
    }
}
