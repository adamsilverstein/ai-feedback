<?php
/**
 * Tests for limiting the model list to configured providers.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Settings_Controller;

/**
 * Stub provider registry with a fixed set of configured providers.
 */
class Stub_Provider_Registry {

	/**
	 * Provider IDs the registry knows.
	 *
	 * @var list<string>
	 */
	private array $registered;

	/**
	 * Provider IDs that have credentials.
	 *
	 * @var list<string>
	 */
	private array $configured;

	/**
	 * Constructor.
	 *
	 * @param list<string> $registered Provider IDs the registry knows.
	 * @param list<string> $configured Provider IDs that have credentials.
	 */
	public function __construct( array $registered, array $configured ) {
		$this->registered = $registered;
		$this->configured = $configured;
	}

	/**
	 * Whether the provider is registered.
	 *
	 * @param string $id Provider ID.
	 * @return bool Whether the provider is registered.
	 */
	public function hasProvider( string $id ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the AI Client API.
		return in_array( $id, $this->registered, true );
	}

	/**
	 * Whether the provider has credentials.
	 *
	 * @param string $id Provider ID.
	 * @return bool Whether the provider is configured.
	 */
	public function isProviderConfigured( string $id ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the AI Client API.
		return in_array( $id, $this->configured, true );
	}
}

/**
 * Settings controller with a replaceable provider registry.
 */
class Testable_Settings_Controller extends Settings_Controller {

	/**
	 * Registry returned to the controller.
	 *
	 * @var object|null
	 */
	public ?object $registry = null;

	/**
	 * Returns the stub registry.
	 *
	 * @return object|null Registry.
	 */
	protected function get_provider_registry(): ?object {
		return $this->registry;
	}

	/**
	 * Exposes the provider filter.
	 *
	 * @param list<array<string, mixed>> $models Models.
	 * @return list<array<string, mixed>> Filtered models.
	 */
	public function filter( array $models ): array {
		return $this->filter_configured_models( $models );
	}
}

/**
 * Test cases for Settings_Controller::filter_configured_models().
 */
class SettingsControllerConfiguredModelsTest extends TestCase {

	/**
	 * Models from two providers.
	 *
	 * @var list<array<string, mixed>>
	 */
	private const MODELS = array(
		array(
			'id'       => 'claude-sonnet-5-5',
			'provider' => 'anthropic',
		),
		array(
			'id'       => 'gpt-4o',
			'provider' => 'openai',
		),
	);

	/**
	 * Get the model IDs left after filtering.
	 *
	 * @param object|null $registry Provider registry.
	 * @return list<mixed> Model IDs.
	 */
	private function filtered_ids( ?object $registry ): array {
		$controller           = new Testable_Settings_Controller();
		$controller->registry = $registry;

		return array_column( $controller->filter( self::MODELS ), 'id' );
	}

	/**
	 * Only models from configured providers are kept.
	 */
	public function test_keeps_configured_providers(): void {
		$registry = new Stub_Provider_Registry( array( 'anthropic', 'openai' ), array( 'anthropic' ) );

		$this->assertSame( array( 'claude-sonnet-5-5' ), $this->filtered_ids( $registry ) );
	}

	/**
	 * A configured but unregistered provider is not kept.
	 */
	public function test_skips_unregistered_providers(): void {
		$registry = new Stub_Provider_Registry( array( 'anthropic' ), array( 'anthropic', 'openai' ) );

		$this->assertSame( array( 'claude-sonnet-5-5' ), $this->filtered_ids( $registry ) );
	}

	/**
	 * The full list is kept before any provider is configured.
	 */
	public function test_keeps_all_models_when_none_configured(): void {
		$registry = new Stub_Provider_Registry( array( 'anthropic', 'openai' ), array() );

		$this->assertSame( array( 'claude-sonnet-5-5', 'gpt-4o' ), $this->filtered_ids( $registry ) );
	}

	/**
	 * The full list is kept without the AI Client.
	 */
	public function test_keeps_all_models_without_registry(): void {
		$this->assertSame( array( 'claude-sonnet-5-5', 'gpt-4o' ), $this->filtered_ids( null ) );
	}

	/**
	 * The full list is kept when the registry lacks the expected methods.
	 */
	public function test_keeps_all_models_with_unexpected_registry(): void {
		$this->assertSame( array( 'claude-sonnet-5-5', 'gpt-4o' ), $this->filtered_ids( new \stdClass() ) );
	}
}
