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
	 * Test health response returns correct version.
	 */
	public function test_health_response_version(): void {
		$response = $this->controller->get_health();
		$data     = $response->get_data();

		$this->assertSame( '0.1.0', $data['version'] );
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
	 * Test notes_api is always true.
	 */
	public function test_health_response_notes_api(): void {
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
	 * Test status reflects AI availability.
	 */
	public function test_health_status_reflects_ai_availability(): void {
		$response = $this->controller->get_health();
		$data     = $response->get_data();

		if ( $data['ai_available'] ) {
			$this->assertSame( 'ok', $data['status'] );
		} else {
			$this->assertSame( 'degraded', $data['status'] );
		}
	}

	/**
	 * Test response has 200 status code.
	 */
	public function test_health_response_status_code(): void {
		$response = $this->controller->get_health();

		$this->assertSame( 200, $response->get_status() );
	}
}
