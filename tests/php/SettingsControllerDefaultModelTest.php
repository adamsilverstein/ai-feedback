<?php
/**
 * Tests for the default model returned by Settings_Controller::get_settings().
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Settings_Controller;
use ReflectionMethod;

/**
 * Test cases for the saved default model fallback.
 */
class SettingsControllerDefaultModelTest extends TestCase {

	/**
	 * Reset stored options and the model cache between tests.
	 */
	protected function setUp(): void {
		$GLOBALS['test_options']    = array();
		$GLOBALS['test_transients'] = array();
	}

	/**
	 * Call the private get_default_model() method.
	 *
	 * @return string Default model ID.
	 */
	private function get_default_model(): string {
		$method = new ReflectionMethod( Settings_Controller::class, 'get_default_model' );
		return $method->invoke( new Settings_Controller() );
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
		$GLOBALS['test_options']['ai_feedback_default_model'] = 'gpt-4o';

		$this->assertSame( 'gpt-4o', $this->get_default_model() );
	}

	/**
	 * A saved model that is no longer offered falls back to the default.
	 */
	public function test_replaces_retired_saved_model(): void {
		$GLOBALS['test_options']['ai_feedback_default_model'] = 'claude-sonnet-4';

		$this->assertSame( AI_FEEDBACK_DEFAULT_MODEL, $this->get_default_model() );
	}
}
