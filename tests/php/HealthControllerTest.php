<?php
/**
 * Tests for Health_Controller.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Health_Controller;

// Mock WordPress constants needed by the controller.
if ( ! defined( 'AI_FEEDBACK_VERSION' ) ) {
	define( 'AI_FEEDBACK_VERSION', '0.1.0' );
}

/**
 * Test cases for Health_Controller.
 */
class HealthControllerTest extends TestCase {

	/**
	 * Controller instance.
	 *
	 * @var Health_Controller
	 */
	private Health_Controller $controller;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->controller = new Health_Controller();
		// Default to authenticated user with permissions.
		$GLOBALS['test_current_user_can'] = true;
	}

	/**
	 * Clean up globals after each test.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['test_current_user_can'] );
		parent::tearDown();
	}

	/**
	 * Test health endpoint returns expected fields.
	 */
	public function test_health_response_has_required_fields(): void {
		$response = $this->controller->get_health();
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'status', $data );
		$this->assertArrayHasKey( 'version', $data );
		$this->assertArrayHasKey( 'ai_available', $data );
		$this->assertArrayHasKey( 'notes_api', $data );
		$this->assertArrayHasKey( 'php_version', $data );
		$this->assertArrayHasKey( 'wp_version', $data );
	}

	/**
	 * Test health response returns correct version using constant.
	 */
	public function test_health_response_version(): void {
		$response = $this->controller->get_health();
		$data     = $response->get_data();

		$this->assertSame( AI_FEEDBACK_VERSION, $data['version'] );
	}

	/**
	 * Test health response returns PHP version.
	 */
	public function test_health_response_php_version(): void {
		$response = $this->controller->get_health();
		$data     = $response->get_data();

		$this->assertSame( PHP_VERSION, $data['php_version'] );
	}

	/**
	 * Test health response returns WP version.
	 */
	public function test_health_response_wp_version(): void {
		$response = $this->controller->get_health();
		$data     = $response->get_data();

		$this->assertSame( '7.0', $data['wp_version'] );
	}

	/**
	 * Test notes_api is computed dynamically from WP version.
	 */
	public function test_health_response_notes_api_is_boolean(): void {
		$response = $this->controller->get_health();
		$data     = $response->get_data();

		$this->assertIsBool( $data['notes_api'] );
	}

	/**
	 * Test notes_api is true when WP version >= 6.9.
	 */
	public function test_health_notes_api_true_for_supported_wp(): void {
		// Our mock returns '7.0' which is >= 6.9.
		$response = $this->controller->get_health();
		$data     = $response->get_data();

		$this->assertTrue( $data['notes_api'] );
	}

	/**
	 * Test ai_available is a boolean.
	 */
	public function test_health_response_ai_available_is_boolean(): void {
		$response = $this->controller->get_health();
		$data     = $response->get_data();

		$this->assertIsBool( $data['ai_available'] );
	}

	/**
	 * Test status is ok when both AI client and Notes API are available.
	 */
	public function test_health_status_ok_when_dependencies_available(): void {
		$response = $this->controller->get_health();
		$data     = $response->get_data();

		// In the test environment AiClient is loaded via Composer and
		// the mocked WP version is 7.0 (>= 6.9), so both are available.
		if ( $data['ai_available'] && $data['notes_api'] ) {
			$this->assertSame( 'ok', $data['status'] );
		} else {
			$this->assertSame( 'degraded', $data['status'] );
		}
	}

	/**
	 * Test status value is always ok or degraded.
	 */
	public function test_health_status_is_valid_value(): void {
		$response = $this->controller->get_health();
		$data     = $response->get_data();

		$this->assertContains( $data['status'], array( 'ok', 'degraded' ) );
	}

	/**
	 * Test response has 200 status code.
	 */
	public function test_health_response_status_code(): void {
		$response = $this->controller->get_health();

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Test permission check returns true for authorized users.
	 */
	public function test_permissions_check_allows_authorized_user(): void {
		$GLOBALS['test_current_user_can'] = true;

		$result = $this->controller->get_health_permissions_check();

		$this->assertTrue( $result );
	}

	/**
	 * Test permission check returns WP_Error for unauthorized users.
	 */
	public function test_permissions_check_denies_unauthorized_user(): void {
		$GLOBALS['test_current_user_can'] = false;

		$result = $this->controller->get_health_permissions_check();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data( 'rest_forbidden' )['status'] );
	}
}
