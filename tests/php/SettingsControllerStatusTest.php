<?php
/**
 * Tests for Settings_Controller::get_status() connector detection.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Settings_Controller;

/**
 * Subclass that lets each test simulate a different Connectors API state
 * without redefining global functions.
 */
class TestableSettingsController extends Settings_Controller {

	/**
	 * Override flag: when true, has_ai_provider_connector() returns true.
	 *
	 * @var bool
	 */
	public bool $force_has_ai_provider = false;

	/**
	 * Override flag: when true, has_ai_provider_connector() returns the value
	 * of $force_has_ai_provider; otherwise it falls through to the parent
	 * implementation which checks function_exists('wp_get_connectors').
	 *
	 * @var bool
	 */
	public bool $override_active = true;

	/**
	 * Allow tests to control the connector check directly.
	 *
	 * @return bool Connector availability per test fixture.
	 */
	protected function has_ai_provider_connector(): bool {
		if ( $this->override_active ) {
			return $this->force_has_ai_provider;
		}
		return parent::has_ai_provider_connector();
	}
}

/**
 * Test cases for the connector_configured branch of get_status().
 *
 * The three states tested correspond to:
 *   1. wp_get_connectors() unavailable / no AI providers (returns false).
 *   2. AI provider connector registered (returns true).
 *   3. Settings URL is always emitted regardless of connector state.
 */
class SettingsControllerStatusTest extends TestCase {

	/**
	 * connector_configured is false when has_ai_provider_connector() returns false.
	 */
	public function test_connector_configured_false_when_no_provider(): void {
		$controller                       = new TestableSettingsController();
		$controller->override_active      = true;
		$controller->force_has_ai_provider = false;

		$data = $controller->get_status()->get_data();

		$this->assertArrayHasKey( 'connector_configured', $data );
		$this->assertFalse( $data['connector_configured'] );
	}

	/**
	 * connector_configured is true when at least one AI provider is registered.
	 */
	public function test_connector_configured_true_when_provider_present(): void {
		$controller                       = new TestableSettingsController();
		$controller->override_active      = true;
		$controller->force_has_ai_provider = true;

		$data = $controller->get_status()->get_data();

		$this->assertTrue( $data['connector_configured'] );
	}

	/**
	 * Status payload always includes the Connectors admin URL.
	 */
	public function test_settings_url_points_to_connectors_screen(): void {
		$controller                  = new TestableSettingsController();
		$controller->override_active = true;

		$data = $controller->get_status()->get_data();

		$this->assertArrayHasKey( 'settings_url', $data );
		$this->assertStringContainsString( 'page=connectors', $data['settings_url'] );
	}

	/**
	 * Real has_ai_provider_connector() returns false when wp_get_connectors() is missing.
	 *
	 * The bootstrap does not define wp_get_connectors(), so this exercises the
	 * function-missing branch directly.
	 */
	public function test_has_ai_provider_connector_returns_false_without_connectors_api(): void {
		$controller                  = new TestableSettingsController();
		$controller->override_active = false;

		$data = $controller->get_status()->get_data();

		$this->assertFalse( $data['connector_configured'] );
	}
}
