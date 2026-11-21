<?php
/**
 * Fake WordPress 7.0 AI Client and filter API for ReviewServiceCallAiTest.
 *
 * @package AI_Feedback\Tests
 */

// phpcs:disable

/**
 * Records builder calls and returns or throws $GLOBALS['fake_ai_result'].
 */
class Fake_AI_Client_Prompt_Builder {
	public array $calls = array();
	public ?int $timeout_during_call = null;

	public function __call( string $name, array $args ): self {
		if ( 'with_file' === $name ) {
			$this->calls['with_file'][] = $args;
			return $this;
		}
		$this->calls[ $name ] = 'using_model_preference' === $name ? $args : $args[0];
		return $this;
	}

	public function using_model( $model ): self {
		$this->calls['using_model'] = $model;
		return $this;
	}

	public function generate_text() {
		$result = $GLOBALS['fake_ai_result'];
		if ( $result instanceof Throwable ) {
			throw $result;
		}
		return $result;
	}

	/**
	 * Like core: the result names the provider and model that answered,
	 * which is the pinned model unless a test says another answered.
	 */
	public function generate_text_result() {
		$this->calls['generate_text_result'] = true;
		$text = $this->generate_text();
		if ( $text instanceof WP_Error ) {
			return $text;
		}
		$model    = $this->calls['using_model'] ?? null;
		$answered = $GLOBALS['fake_ai_answered_by'] ?? (
			$model instanceof Fake_Core_Model ? array( $model->provider_id, $model->model_id ) : array( 'unknown', 'unknown' )
		);
		return new Fake_Core_Result( (string) $text, $answered[0], $answered[1] );
	}
}

/**
 * An object with getId(), like core's provider and model metadata.
 */
class Fake_Core_Id {
	public function __construct( private string $id, private string $name = '' ) {}
	public function getId(): string {
		return $this->id;
	}
	public function getName(): string {
		return $this->name;
	}
}

/**
 * Core's GenerativeAiResult, reduced to what the plugin reads.
 */
class Fake_Core_Result {
	public function __construct( private string $text, private string $provider, private string $model ) {}
	public function toText(): string {
		return $this->text;
	}
	public function getProviderMetadata(): Fake_Core_Id {
		return new Fake_Core_Id( $this->provider );
	}
	public function getModelMetadata(): Fake_Core_Id {
		return new Fake_Core_Id( $this->model );
	}
}

/**
 * A core model instance from one provider.
 */
class Fake_Core_Model {
	public function __construct( public string $provider_id, public string $model_id ) {}
	public function metadata(): Fake_Core_Id {
		return new Fake_Core_Id( $this->model_id );
	}
	public function providerMetadata(): Fake_Core_Id {
		return new Fake_Core_Id( $this->provider_id );
	}
}

/**
 * A provider's model directory, read from $GLOBALS['fake_providers'].
 */
class Fake_Core_Directory {
	public function __construct( private string $provider ) {}
	private function ids(): array {
		$provider = $GLOBALS['fake_providers'][ $this->provider ];
		if ( ! empty( $provider['fails'] ) ) {
			throw new RuntimeException( 'Could not list models: connection timed out.' );
		}
		return $provider['models'];
	}
	public function hasModelMetadata( string $id ): bool {
		return in_array( $id, $this->ids(), true );
	}
	public function listModelMetadata(): array {
		return array_map( static fn ( string $id ): Fake_Core_Id => new Fake_Core_Id( $id ), $this->ids() );
	}
}

/**
 * Two fake providers. Their class names carry the provider ID, as core's
 * registry hands back a class for each ID.
 */
abstract class Fake_Core_Provider {
	abstract protected static function id(): string;
	public static function modelMetadataDirectory(): Fake_Core_Directory {
		return new Fake_Core_Directory( static::id() );
	}
	public static function metadata(): Fake_Core_Id {
		return new Fake_Core_Id( static::id(), $GLOBALS['fake_providers'][ static::id() ]['name'] );
	}
}

class Fake_Core_Provider_Anthropic extends Fake_Core_Provider {
	protected static function id(): string {
		return 'anthropic';
	}
}

class Fake_Core_Provider_Other extends Fake_Core_Provider {
	protected static function id(): string {
		return 'fakeother';
	}
}

/**
 * Core's ProviderRegistry over $GLOBALS['fake_providers'], in its order.
 */
class Fake_Core_Registry {
	private const CLASSES = array(
		'anthropic' => Fake_Core_Provider_Anthropic::class,
		'fakeother' => Fake_Core_Provider_Other::class,
	);
	public function getRegisteredProviderIds(): array {
		return array_keys( $GLOBALS['fake_providers'] );
	}
	public function hasProvider( string $id ): bool {
		return isset( $GLOBALS['fake_providers'][ $id ] );
	}
	public function isProviderConfigured( string $id ): bool {
		return ! empty( $GLOBALS['fake_providers'][ $id ]['configured'] );
	}
	public function getProviderClassName( string $id ): string {
		return self::CLASSES[ $id ];
	}
	public function getProviderModel( string $id, string $model_id ): Fake_Core_Model {
		// Core throws for a model the provider doesn't list.
		if ( ! ( new Fake_Core_Directory( $id ) )->hasModelMetadata( $model_id ) ) {
			throw new InvalidArgumentException( 'No model with ID ' . $model_id . ' was found in the provider' );
		}
		return new Fake_Core_Model( $id, $model_id );
	}
}

/**
 * Fake providers: "anthropic" lists the plugin's default model and
 * "fakeother" lists another. Tests change $GLOBALS['fake_providers'].
 */
$GLOBALS['fake_providers'] ??= array(
	'fakeother' => array(
		'name'       => 'Fake Other',
		'configured' => true,
		'models'     => array( 'other-model-1' ),
	),
	'anthropic' => array(
		'name'       => 'Anthropic',
		'configured' => true,
		'models'     => array( 'claude-sonnet-5-5' ),
	),
);

require __DIR__ . '/fake-ai-client-entry.php';

function wp_ai_client_prompt( $prompt = null ) {
	$builder                    = new Fake_AI_Client_Prompt_Builder();
	$builder->calls['prompt']   = $prompt;
	// Core reads the timeout filter when the builder is created.
	$callbacks                    = $GLOBALS['fake_filters']['wp_ai_client_default_request_timeout'] ?? array();
	$builder->timeout_during_call = $callbacks ? (int) end( $callbacks )( 30.0 ) : null;
	$GLOBALS['fake_ai_builder'] = $builder;
	return $builder;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['fake_filters'][ $hook ][ spl_object_id( $callback ) ] = $callback;
	return true;
}

function remove_filter( $hook, $callback, $priority = 10 ) {
	unset( $GLOBALS['fake_filters'][ $hook ][ spl_object_id( $callback ) ] );
	return true;
}
