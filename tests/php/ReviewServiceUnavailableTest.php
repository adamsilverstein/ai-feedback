<?php
/**
 * Tests for Review_Service handling of an unavailable AI service.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Review_Service;
use WP_Error;

/**
 * Review_Service subclass that skips dependencies and exposes helpers.
 */
class UnavailableTestReviewService extends Review_Service {

	/**
	 * Skip the parent constructor.
	 */
	public function __construct() {
	}

	/**
	 * Expose service_unavailable_error().
	 *
	 * @param  WP_Error $error Original error.
	 * @return WP_Error Wrapped error.
	 */
	public function wrap( WP_Error $error ): WP_Error {
		return $this->service_unavailable_error( $error );
	}
}

/**
 * Test cases for service-unavailable handling.
 */
class ReviewServiceUnavailableTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var UnavailableTestReviewService
	 */
	private UnavailableTestReviewService $service;

	/**
	 * Set up the service and reset user meta.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['test_user_meta'] = array();
		$this->service             = new UnavailableTestReviewService();
	}

	/**
	 * Errors that mean the service is temporarily down.
	 *
	 * @dataProvider data_unavailable_errors
	 *
	 * @param WP_Error $error Error from the AI Client.
	 */
	public function test_detects_unavailable_errors( WP_Error $error ): void {
		$this->assertTrue( $this->service->is_service_unavailable_error( $error ) );
	}

	/**
	 * Data provider for unavailable errors.
	 *
	 * @return array<string, array{WP_Error}>
	 */
	public static function data_unavailable_errors(): array {
		return array(
			'network error'         => array( new WP_Error( 'prompt_network_error', 'cURL error 28', array( 'status' => 503 ) ) ),
			'upstream server error' => array( new WP_Error( 'prompt_upstream_server_error', 'Overloaded', array( 'status' => 529 ) ) ),
			'bad gateway status'    => array( new WP_Error( 'prompt_client_error', 'Bad gateway', array( 'status' => 502 ) ) ),
			'rate limited'          => array( new WP_Error( 'prompt_client_error', 'Slow down', array( 'status' => 429 ) ) ),
		);
	}

	/**
	 * Errors a retry cannot fix are not treated as an outage.
	 *
	 * @dataProvider data_permanent_errors
	 *
	 * @param WP_Error $error Error from the AI Client.
	 */
	public function test_ignores_permanent_errors( WP_Error $error ): void {
		$this->assertFalse( $this->service->is_service_unavailable_error( $error ) );
	}

	/**
	 * Data provider for permanent errors.
	 *
	 * @return array<string, array{WP_Error}>
	 */
	public static function data_permanent_errors(): array {
		return array(
			'invalid key'       => array( new WP_Error( 'prompt_client_error', 'Invalid API key', array( 'status' => 401 ) ) ),
			'exhausted quota'   => array( new WP_Error( 'prompt_client_error', 'You exceeded your current quota', array( 'status' => 429 ) ) ),
			'token limit'       => array( new WP_Error( 'prompt_token_limit_reached', 'Too long', array( 'status' => 400 ) ) ),
			'missing ai client' => array( new WP_Error( 'ai_client_missing', 'Missing' ) ),
		);
	}

	/**
	 * The wrapped error has a stable code, a 503 status and a retry delay.
	 */
	public function test_wraps_error_for_the_editor(): void {
		$error = $this->service->wrap( new WP_Error( 'prompt_network_error', 'cURL error 28: secret-host', array( 'status' => 503 ) ) );

		$this->assertSame( 'ai_service_unavailable', $error->get_error_code() );
		$this->assertStringNotContainsString( 'secret-host', $error->get_error_message() );
		$this->assertSame(
			array(
				'status'        => 503,
				'retry_after'   => 30,
				'original_code' => 'prompt_network_error',
			),
			$error->get_error_data()
		);
	}

	/**
	 * Refunding removes only the latest review from the rate limit.
	 */
	public function test_refund_rate_limit_removes_latest_review(): void {
		update_user_meta( 7, 'ai_feedback_reviews', array( 100, 200, 300 ) );

		$this->assertTrue( $this->service->refund_rate_limit( 7 ) );

		$this->assertSame( array( 100, 200 ), get_user_meta( 7, 'ai_feedback_reviews', true ) );
	}

	/**
	 * Refunds stop after the hourly cap, so a failing provider sees a bounded
	 * number of requests.
	 */
	public function test_refunds_are_capped_per_hour(): void {
		update_user_meta( 7, 'ai_feedback_reviews', array( 100, 200 ) );
		update_user_meta( 7, 'ai_feedback_refunds', array_fill( 0, Review_Service::MAX_REFUNDS_PER_HOUR, time() ) );

		$this->assertFalse( $this->service->refund_rate_limit( 7 ) );
		$this->assertSame( array( 100, 200 ), get_user_meta( 7, 'ai_feedback_reviews', true ) );
	}

	/**
	 * Refunds older than an hour do not count toward the cap.
	 */
	public function test_old_refunds_do_not_count(): void {
		update_user_meta( 7, 'ai_feedback_reviews', array( 100, 200 ) );
		update_user_meta( 7, 'ai_feedback_refunds', array_fill( 0, Review_Service::MAX_REFUNDS_PER_HOUR, time() - 2 * HOUR_IN_SECONDS ) );

		$this->assertTrue( $this->service->refund_rate_limit( 7 ) );
		$this->assertCount( 1, get_user_meta( 7, 'ai_feedback_refunds', true ) );
	}

	/**
	 * Refunding without earlier reviews does nothing.
	 */
	public function test_refund_rate_limit_without_reviews(): void {
		$this->assertFalse( $this->service->refund_rate_limit( 7 ) );

		$this->assertSame( '', get_user_meta( 7, 'ai_feedback_reviews', true ) );
	}
}
