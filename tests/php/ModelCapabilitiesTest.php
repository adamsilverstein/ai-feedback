<?php
/**
 * Tests for deciding which models are sent images (Model_Capabilities).
 *
 * The core AI client isn't loaded in unit tests, so its registry, provider
 * and model metadata are stubbed with the methods the class calls.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Model_Capabilities;

/**
 * Stands in for a core enum, which casts to its value.
 */
class Stub_Enum implements \Stringable {

	/**
	 * Constructor.
	 *
	 * @param string $value Enum value.
	 */
	public function __construct( private string $value ) {}

	/**
	 * The enum value.
	 *
	 * @return string Value.
	 */
	public function __toString(): string {
		return $this->value;
	}
}

/**
 * Stands in for core's SupportedOption.
 */
class Stub_Supported_Option {

	/**
	 * Constructor.
	 *
	 * @param string            $name   Option name.
	 * @param list<list<mixed>> $values Supported values, each a set of modalities.
	 */
	public function __construct( private string $name, private array $values ) {}

	/**
	 * Option name.
	 *
	 * @return Stub_Enum Name.
	 */
	public function getName(): Stub_Enum { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the AI Client API.
		return new Stub_Enum( $this->name );
	}

	/**
	 * Whether a set of values is supported, compared as core does: cast to
	 * strings and sorted, so order doesn't matter.
	 *
	 * @param  list<mixed> $value Values.
	 * @return bool Whether supported.
	 */
	public function isSupportedValue( array $value ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the AI Client API.
		$normalize = static function ( array $items ): array {
			$items = array_map( 'strval', $items );
			sort( $items );
			return $items;
		};
		foreach ( $this->values as $supported ) {
			if ( $normalize( $supported ) === $normalize( $value ) ) {
				return true;
			}
		}
		return false;
	}
}

/**
 * Stands in for core's ModelMetadata.
 */
class Stub_Model_Metadata {

	/**
	 * Constructor.
	 *
	 * @param list<string>               $capabilities Capability IDs.
	 * @param list<list<Stub_Enum>>|null $modalities   Input modality sets, or null for no input modalities option.
	 * @param string                     $option       Name of the input modalities option. Core 7.0
	 *                                                 reports `inputModalities`.
	 */
	public function __construct( private array $capabilities, private ?array $modalities, private string $option = 'inputModalities' ) {}

	/**
	 * Supported capabilities.
	 *
	 * @return list<Stub_Enum> Capabilities.
	 */
	public function getSupportedCapabilities(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the AI Client API.
		return array_map( static fn ( string $id ): Stub_Enum => new Stub_Enum( $id ), $this->capabilities );
	}

	/**
	 * Supported options.
	 *
	 * @return list<Stub_Supported_Option> Options.
	 */
	public function getSupportedOptions(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the AI Client API.
		$options = array( new Stub_Supported_Option( 'temperature', array() ) );
		if ( null !== $this->modalities ) {
			$options[] = new Stub_Supported_Option( $this->option, $this->modalities );
		}
		return $options;
	}
}

/**
 * Stands in for a provider's model metadata directory.
 */
class Stub_Model_Directory {

	/**
	 * Constructor.
	 *
	 * @param array<string, Stub_Model_Metadata> $models Metadata by model ID.
	 * @param bool                               $fails  Whether listing models throws, like a failed request.
	 */
	public function __construct( private array $models, private bool $fails = false ) {}

	/**
	 * Whether the provider lists the model.
	 *
	 * @param  string $model_id Model ID.
	 * @return bool Whether listed.
	 * @throws \RuntimeException When set to fail.
	 */
	public function hasModelMetadata( string $model_id ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the AI Client API.
		if ( $this->fails ) {
			throw new \RuntimeException( 'Could not list models.' );
		}
		return isset( $this->models[ $model_id ] );
	}

	/**
	 * Every model the provider lists, as objects with an ID.
	 *
	 * @return list<object> Listed models.
	 * @throws \RuntimeException When set to fail.
	 */
	public function listModelMetadata(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the AI Client API.
		if ( $this->fails ) {
			throw new \RuntimeException( 'Could not list models.' );
		}
		return array_map(
			static fn ( string $id ): object => new class( $id ) {
				/**
				 * Constructor.
				 *
				 * @param string $id Model ID.
				 */
				public function __construct( private string $id ) {}

				/**
				 * Model ID.
				 *
				 * @return string ID.
				 */
				public function getId(): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the AI Client API.
					return $this->id;
				}
			},
			array_keys( $this->models )
		);
	}

	/**
	 * The model's metadata.
	 *
	 * @param  string $model_id Model ID.
	 * @return Stub_Model_Metadata Metadata.
	 */
	public function getModelMetadata( string $model_id ): Stub_Model_Metadata { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the AI Client API.
		return $this->models[ $model_id ];
	}
}

/**
 * Stands in for a provider class, whose directory is static in core.
 */
class Stub_Provider_A {

	/**
	 * Directory returned by modelMetadataDirectory().
	 *
	 * @var Stub_Model_Directory|null
	 */
	public static ?Stub_Model_Directory $directory = null;

	/**
	 * The provider's model metadata directory.
	 *
	 * @return Stub_Model_Directory|null Directory.
	 */
	public static function modelMetadataDirectory(): ?Stub_Model_Directory { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the AI Client API.
		return static::$directory;
	}
}

/**
 * A second provider class.
 */
class Stub_Provider_B extends Stub_Provider_A {

	/**
	 * Directory returned by modelMetadataDirectory().
	 *
	 * @var Stub_Model_Directory|null
	 */
	public static ?Stub_Model_Directory $directory = null;
}

/**
 * Stands in for core's ProviderRegistry.
 */
class Stub_Capability_Registry {

	/**
	 * Constructor.
	 *
	 * @param array<string, class-string> $providers  Provider classes by ID.
	 * @param list<string>                $configured Provider IDs that have credentials.
	 */
	public function __construct( private array $providers, private array $configured ) {}

	/**
	 * Registered provider IDs.
	 *
	 * @return list<string> IDs.
	 */
	public function getRegisteredProviderIds(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the AI Client API.
		return array_keys( $this->providers );
	}

	/**
	 * Whether the provider is registered.
	 *
	 * @param  string $id Provider ID.
	 * @return bool Whether registered.
	 */
	public function hasProvider( string $id ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the AI Client API.
		return isset( $this->providers[ $id ] );
	}

	/**
	 * Whether the provider has credentials.
	 *
	 * @param  string $id Provider ID.
	 * @return bool Whether configured.
	 */
	public function isProviderConfigured( string $id ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the AI Client API.
		return in_array( $id, $this->configured, true );
	}

	/**
	 * The provider's class.
	 *
	 * @param  string $id Provider ID.
	 * @return string Class name.
	 */
	public function getProviderClassName( string $id ): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the AI Client API.
		return $this->providers[ $id ];
	}
}

/**
 * Model_Capabilities with a replaceable registry.
 */
class Testable_Model_Capabilities extends Model_Capabilities {

	/**
	 * Registry returned to the class.
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
}

/**
 * Test cases for Model_Capabilities.
 */
class ModelCapabilitiesTest extends TestCase {

	/**
	 * Reset the model list, filters and stub providers.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['test_transients']     = array();
		$GLOBALS['test_filter_results'] = array();
		Stub_Provider_A::$directory     = null;
		Stub_Provider_B::$directory     = null;
	}

	/**
	 * Clear filters.
	 */
	protected function tearDown(): void {
		$GLOBALS['test_filter_results'] = array();
		parent::tearDown();
	}

	/**
	 * Metadata for a text generation model.
	 *
	 * @param  bool $images Whether it takes images with text.
	 * @return Stub_Model_Metadata Metadata.
	 */
	private static function model( bool $images ): Stub_Model_Metadata {
		$text = new Stub_Enum( 'text' );
		$sets = array( array( $text ) );
		if ( $images ) {
			// Listed image first, so the check can't rely on order.
			$sets[] = array( new Stub_Enum( 'image' ), $text );
		}
		return new Stub_Model_Metadata( array( 'text_generation', 'chat_history' ), $sets );
	}

	/**
	 * Capabilities with Anthropic as provider A, configured.
	 *
	 * @param  array<string, Stub_Model_Metadata> $models Models provider A lists.
	 * @return Testable_Model_Capabilities Capabilities.
	 */
	private static function with_anthropic( array $models ): Testable_Model_Capabilities {
		Stub_Provider_A::$directory = new Stub_Model_Directory( $models );
		$capabilities               = new Testable_Model_Capabilities();
		$capabilities->registry     = new Stub_Capability_Registry( array( 'anthropic' => Stub_Provider_A::class ), array( 'anthropic' ) );
		return $capabilities;
	}

	/**
	 * Every model in the plugin's list reads images, so with no core answer
	 * the default model gets proposals.
	 */
	public function test_catalog_marks_listed_models_as_vision(): void {
		$capabilities = new Testable_Model_Capabilities();

		foreach ( array( AI_FEEDBACK_DEFAULT_MODEL, 'claude-opus-5-5', 'gpt-4o', 'gemini-2.0-flash' ) as $model ) {
			$this->assertTrue( $capabilities->supports_vision( $model ), $model );
		}
		$this->assertFalse( $capabilities->supports_vision( 'unknown-model' ) );
	}

	/**
	 * A model added as text only through `ai_feedback_available_models` gets
	 * no images.
	 */
	public function test_catalog_text_only_model(): void {
		$GLOBALS['test_filter_results'] = array(
			'ai_feedback_available_models' => array(
				array(
					'id'           => 'house-model',
					'provider'     => 'example',
					'capabilities' => array( 'text' ),
				),
			),
		);

		$this->assertFalse( ( new Testable_Model_Capabilities() )->supports_vision( 'house-model' ) );
	}

	/**
	 * Core's metadata wins over the list, both ways.
	 */
	public function test_core_metadata_decides(): void {
		$capabilities = self::with_anthropic(
			array(
				AI_FEEDBACK_DEFAULT_MODEL => self::model( false ),
				'claude-opus-5-5'         => self::model( true ),
			)
		);

		$this->assertFalse( $capabilities->supports_vision( AI_FEEDBACK_DEFAULT_MODEL ) );
		$this->assertTrue( $capabilities->supports_vision( 'claude-opus-5-5' ) );
		$this->assertTrue( $capabilities->core_supports_images( 'claude-opus-5-5', 'anthropic' ) );
	}

	/**
	 * A provider that lists only a dated release of the model answers for
	 * it, under the same ID the request is pinned to (Model_Pin).
	 */
	public function test_core_metadata_for_a_dated_release(): void {
		$capabilities = self::with_anthropic(
			array(
				'claude-opus-4-1-20250805' => self::model( true ),
				'claude-opus-4-20250514'   => self::model( false ),
			)
		);

		$this->assertSame( 'claude-opus-4-20250514', $capabilities->find_listed_id( 'anthropic', 'claude-opus-4' ) );
		$this->assertFalse( $capabilities->core_supports_images( 'claude-opus-4', 'anthropic' ) );
	}

	/**
	 * Image input alone, or images without text generation, doesn't count.
	 */
	public function test_core_needs_text_generation_from_text_and_images(): void {
		$capabilities = self::with_anthropic(
			array(
				'image-only'  => new Stub_Model_Metadata( array( 'text_generation' ), array( array( new Stub_Enum( 'image' ) ) ) ),
				'image-maker' => new Stub_Model_Metadata( array( 'image_generation' ), array( array( new Stub_Enum( 'text' ), new Stub_Enum( 'image' ) ) ) ),
				'no-options'  => new Stub_Model_Metadata( array( 'text_generation' ), null ),
			)
		);

		$this->assertFalse( $capabilities->core_supports_images( 'image-only', 'anthropic' ) );
		$this->assertFalse( $capabilities->core_supports_images( 'image-maker', 'anthropic' ) );
		$this->assertFalse( $capabilities->core_supports_images( 'no-options', 'anthropic' ) );
	}

	/**
	 * The option name matches as OptionEnum's constant spells it too.
	 */
	public function test_core_option_name_spellings(): void {
		$sets         = array( array( new Stub_Enum( 'text' ), new Stub_Enum( 'image' ) ) );
		$capabilities = self::with_anthropic(
			array(
				'snake' => new Stub_Model_Metadata( array( 'text_generation' ), $sets, 'input_modalities' ),
				'other' => new Stub_Model_Metadata( array( 'text_generation' ), $sets, 'outputModalities' ),
			)
		);

		$this->assertTrue( $capabilities->core_supports_images( 'snake', 'anthropic' ) );
		$this->assertFalse( $capabilities->core_supports_images( 'other', 'anthropic' ) );
	}

	/**
	 * Without a core answer the list decides: no AI client, the provider
	 * isn't set up, or the provider doesn't list the model.
	 */
	public function test_falls_back_to_catalog_without_core_answer(): void {
		// No AI client.
		$this->assertNull( ( new Testable_Model_Capabilities() )->core_supports_images( 'gpt-4o', 'openai' ) );

		// Provider not configured.
		$capabilities           = self::with_anthropic( array( 'claude-opus-5-5' => self::model( false ) ) );
		$capabilities->registry = new Stub_Capability_Registry( array( 'anthropic' => Stub_Provider_A::class ), array() );
		$this->assertNull( $capabilities->core_supports_images( 'claude-opus-5-5', 'anthropic' ) );
		$this->assertTrue( $capabilities->supports_vision( 'claude-opus-5-5' ) );

		// Provider doesn't list the model.
		$capabilities = self::with_anthropic( array( 'claude-opus-5-5' => self::model( false ) ) );
		$this->assertNull( $capabilities->core_supports_images( AI_FEEDBACK_DEFAULT_MODEL, 'anthropic' ) );
		$this->assertTrue( $capabilities->supports_vision( AI_FEEDBACK_DEFAULT_MODEL ) );

		// A registry without the expected methods.
		$capabilities->registry = new \stdClass();
		$this->assertNull( $capabilities->core_supports_images( 'claude-opus-5-5', 'anthropic' ) );
	}

	/**
	 * A failure listing models is no answer, not an error.
	 */
	public function test_metadata_failure_falls_back(): void {
		$capabilities               = self::with_anthropic( array() );
		Stub_Provider_A::$directory = new Stub_Model_Directory( array(), true );

		$this->assertNull( $capabilities->core_supports_images( 'claude-opus-5-5', 'anthropic' ) );
		$this->assertTrue( $capabilities->supports_vision( 'claude-opus-5-5' ) );
	}

	/**
	 * One provider failing to list its models doesn't stop the next one
	 * answering for a model the plugin doesn't list.
	 */
	public function test_metadata_failure_skips_to_next_provider(): void {
		Stub_Provider_A::$directory = new Stub_Model_Directory( array(), true );
		Stub_Provider_B::$directory = new Stub_Model_Directory( array( 'house-model' => self::model( true ) ) );
		$capabilities               = new Testable_Model_Capabilities();
		$capabilities->registry     = new Stub_Capability_Registry(
			array(
				'a' => Stub_Provider_A::class,
				'b' => Stub_Provider_B::class,
			),
			array( 'a', 'b' )
		);

		$this->assertTrue( $capabilities->core_supports_images( 'house-model', '' ) );
		$this->assertTrue( $capabilities->supports_vision( 'house-model' ) );
	}

	/**
	 * A model the plugin doesn't list is looked up in every configured
	 * provider.
	 */
	public function test_unlisted_model_searches_configured_providers(): void {
		Stub_Provider_A::$directory = new Stub_Model_Directory( array( 'house-model' => self::model( false ) ) );
		Stub_Provider_B::$directory = new Stub_Model_Directory( array( 'house-model' => self::model( true ) ) );
		$capabilities               = new Testable_Model_Capabilities();

		// Provider A isn't set up, so B answers.
		$capabilities->registry = new Stub_Capability_Registry(
			array(
				'a' => Stub_Provider_A::class,
				'b' => Stub_Provider_B::class,
			),
			array( 'b' )
		);
		$this->assertTrue( $capabilities->supports_vision( 'house-model' ) );

		// The first configured provider listing it answers.
		$capabilities->registry = new Stub_Capability_Registry(
			array(
				'a' => Stub_Provider_A::class,
				'b' => Stub_Provider_B::class,
			),
			array( 'a', 'b' )
		);
		$this->assertFalse( $capabilities->supports_vision( 'house-model' ) );
	}

	/**
	 * The `ai_feedback_model_supports_vision` filter has the last word.
	 */
	public function test_filter_overrides(): void {
		$capabilities = self::with_anthropic( array( 'claude-opus-5-5' => self::model( true ) ) );

		$GLOBALS['test_filter_results'] = array( 'ai_feedback_model_supports_vision' => false );
		$this->assertFalse( $capabilities->supports_vision( 'claude-opus-5-5' ) );

		$GLOBALS['test_filter_results'] = array( 'ai_feedback_model_supports_vision' => true );
		$this->assertTrue( $capabilities->supports_vision( 'unknown-model' ) );

		// Only a real true turns images on.
		$GLOBALS['test_filter_results'] = array( 'ai_feedback_model_supports_vision' => 'yes' );
		$this->assertFalse( $capabilities->supports_vision( 'claude-opus-5-5' ) );
	}
}
