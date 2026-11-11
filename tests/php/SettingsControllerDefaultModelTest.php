<?php
/**
 * Tests for the default model returned by Settings_Controller::get_settings().
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Settings_Controller;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Test cases for the saved default model fallback.
 */
class SettingsControllerDefaultModelTest extends TestCase {

	/**
	 * Transient key holding the cached model list.
	 */
	private const MODELS_CACHE_KEY = 'ai_feedback_available_models_v2';

	/**
	 * Reset stored options and the model cache between tests.
	 */
	protected function setUp(): void {
		$GLOBALS['test_options']    = array();
		$GLOBALS['test_transients'] = array();
	}

	/**
	 * Get default_model from the settings endpoint response.
	 *
	 * @return mixed Default model from the response.
	 */
	private function get_default_model(): mixed {
		$controller = new Settings_Controller();
		$response   = $controller->get_settings( new WP_REST_Request() );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertIsArray( $data );

		return $data['default_model'] ?? null;
	}

	/**
	 * Without a saved option, the plugin default model is used.
	 */
	public function test_uses_plugin_default_when_unset(): void {
		$this->assertSame( AI_FEEDBACK_DEFAULT_MODEL, $this->get_default_model() );
	}

	/**
	 * A saved model that is still offered is kept.
	 */
	public function test_keeps_saved_available_model(): void {
		$GLOBALS['test_options'] = array( 'ai_feedback_default_model' => 'gpt-4o' );

		$this->assertSame( 'gpt-4o', $this->get_default_model() );
	}

	/**
	 * A saved model that is no longer offered falls back to the default.
	 */
	public function test_replaces_retired_saved_model(): void {
		$GLOBALS['test_options'] = array( 'ai_feedback_default_model' => 'claude-sonnet-4' );

		$this->assertSame( AI_FEEDBACK_DEFAULT_MODEL, $this->get_default_model() );
	}

	/**
	 * A saved value that is not a string falls back to the default.
	 */
	public function test_replaces_non_string_saved_model(): void {
		$GLOBALS['test_options'] = array( 'ai_feedback_default_model' => array( 'gpt-4o' ) );

		$this->assertSame( AI_FEEDBACK_DEFAULT_MODEL, $this->get_default_model() );
	}

	/**
	 * When the model list no longer offers the plugin default, the first
	 * offered model is used.
	 */
	public function test_uses_first_offered_model_when_default_filtered_out(): void {
		$GLOBALS['test_transients'] = array(
			self::MODELS_CACHE_KEY => array(
				array(
					'id'       => 'custom-model',
					'name'     => 'Custom Model',
					'provider' => 'custom',
				),
				array(
					'id'       => 'other-model',
					'name'     => 'Other Model',
					'provider' => 'custom',
				),
			),
		);

		$GLOBALS['test_options'] = array( 'ai_feedback_default_model' => 'claude-sonnet-4' );

		$this->assertSame( 'custom-model', $this->get_default_model() );
	}
}
