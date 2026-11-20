<?php
/**
 * Model Pin
 *
 * Holds every AI request to the provider and model the user picked.
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

use WP_Error;

/**
 * Pins a prompt to one provider and model, or explains why it can't.
 *
 * Passing a model ID to core as a preference lets core pick any other
 * model, from any configured provider, when the ID isn't one it finds.
 * This class looks the model up in the provider from the plugin's model
 * list and hands core that exact model, so a request either runs on it or
 * fails with an error that names it.
 */
class Model_Pin {

	/**
	 * Error code: the provider doesn't offer the model.
	 */
	public const ERROR_MODEL_UNAVAILABLE = 'ai_feedback_model_unavailable';

	/**
	 * Error code: the model's provider isn't registered or set up.
	 */
	public const ERROR_PROVIDER_UNAVAILABLE = 'ai_feedback_provider_unavailable';

	/**
	 * Error code: the provider's model list couldn't be read.
	 */
	public const ERROR_MODEL_CHECK_FAILED = 'ai_feedback_model_check_failed';

	/**
	 * Error code: a provider other than the pinned one answered.
	 */
	public const ERROR_WRONG_PROVIDER = 'ai_feedback_wrong_provider';

	/**
	 * Settings controller holding the model list.
	 *
	 * @var Settings_Controller
	 */
	private Settings_Controller $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings_Controller|null $settings Settings controller, for the model list.
	 */
	public function __construct( ?Settings_Controller $settings = null ) {
		$this->settings = $settings ?? new Settings_Controller();
	}

	/**
	 * Pins a core prompt builder to a model.
	 *
	 * @template T of object
	 * @param  object $builder  Core prompt builder from wp_ai_client_prompt().
	 * @param  string $model_id Model ID the user picked.
	 * @return array{builder: object, provider: string, model: string}|WP_Error The builder, pinned,
	 *                                                                         with the provider and
	 *                                                                         model ID it runs on.
	 * @phpstan-param T $builder
	 * @phpstan-return array{builder: T, provider: string, model: string}|WP_Error
	 */
	public function pin( object $builder, string $model_id ): array|WP_Error {
		$target = $this->resolve( $model_id );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		// Core's builder takes using_model() through __call() and returns
		// itself, keeping any error for generate_text_result() to return.
		$using_model = array( $builder, 'using_model' );
		if ( ! is_callable( $using_model ) ) {
			return new WP_Error( 'ai_request_failed', __( 'The AI request failed unexpectedly. Please try again.', 'fresh-eyes' ) );
		}
		$using_model( $target['model'] );

		return array(
			'builder'  => $builder,
			'provider' => $target['provider'],
			'model'    => $target['model_id'],
		);
	}

	/**
	 * Finds the provider and model a model ID runs on.
	 *
	 * The provider comes from the plugin's model list. A model added through
	 * the `ai_feedback_available_models` filter without one runs on the first
	 * configured provider that lists its exact ID.
	 *
	 * @param  string $model_id Model ID the user picked.
	 * @return array{provider: string, model_id: string, model: object}|WP_Error Core model instance, or an error.
	 */
	public function resolve( string $model_id ): array|WP_Error {
		$registry = $this->get_provider_registry();
		if ( null === $registry || ! method_exists( $registry, 'getProviderModel' ) ) {
			return new WP_Error(
				'ai_client_missing',
				__( 'The WordPress core AI Client is unavailable. WordPress 7.0 or higher is required.', 'fresh-eyes' )
			);
		}

		$entry    = $this->settings->get_model( $model_id );
		$provider = is_string( $entry['provider'] ?? null ) ? $entry['provider'] : '';
		$name     = is_string( $entry['name'] ?? null ) && '' !== $entry['name'] ? $entry['name'] : $model_id;

		if ( '' === $provider ) {
			return $this->resolve_without_provider( $registry, $model_id, $name );
		}

		if ( ! $this->is_provider_ready( $registry, $provider ) ) {
			return new WP_Error(
				self::ERROR_PROVIDER_UNAVAILABLE,
				sprintf(
					/* translators: 1: model name, 2: AI provider name. */
					__( 'Fresh Eyes is set to use %1$s from %2$s, but %2$s is not set up. Add it under Settings > Connectors, or choose another model.', 'fresh-eyes' ),
					$name,
					$provider
				),
				array(
					'status'   => 400,
					'model'    => $model_id,
					'provider' => $provider,
				)
			);
		}

		$provider_name = $this->provider_name( $registry, $provider );

		try {
			$listed_id = $this->find_listed_id( $registry, $provider, $model_id );
		} catch ( \Throwable $e ) {
			Logger::debug( sprintf( 'Could not list %s models to check %s: %s', $provider, $model_id, $e->getMessage() ) );
			return $this->check_failed_error( $model_id, $name, $provider, $provider_name );
		}

		if ( null === $listed_id ) {
			return new WP_Error(
				self::ERROR_MODEL_UNAVAILABLE,
				sprintf(
					/* translators: 1: model name, 2: model ID, 3: AI provider name. */
					__( '%1$s (%2$s) is not available from %3$s, so Fresh Eyes did not send the request. Choose another model in the Fresh Eyes settings, or check the models your %3$s account offers.', 'fresh-eyes' ),
					$name,
					$model_id,
					$provider_name
				),
				array(
					'status'   => 400,
					'model'    => $model_id,
					'provider' => $provider,
				)
			);
		}

		try {
			$model = $registry->getProviderModel( $provider, $listed_id );
		} catch ( \Throwable $e ) {
			Logger::debug( sprintf( 'Could not load %s model %s: %s', $provider, $listed_id, $e->getMessage() ) );
			return $this->check_failed_error( $model_id, $name, $provider, $provider_name );
		}

		if ( ! is_object( $model ) ) {
			return $this->check_failed_error( $model_id, $name, $provider, $provider_name );
		}

		if ( $listed_id !== $model_id ) {
			Logger::debug( sprintf( 'Model %s runs as %s on %s', $model_id, $listed_id, $provider ) );
		}

		return array(
			'provider' => $provider,
			'model_id' => $listed_id,
			'model'    => $model,
		);
	}

	/**
	 * The provider and model that produced a core result.
	 *
	 * @param  mixed $result Core GenerativeAiResult.
	 * @return array{provider: string, model: string}|null Null when the result doesn't say.
	 */
	public static function answered_by( mixed $result ): ?array {
		if ( ! is_object( $result ) || ! method_exists( $result, 'getProviderMetadata' ) || ! method_exists( $result, 'getModelMetadata' ) ) {
			return null;
		}

		try {
			$provider = $result->getProviderMetadata();
			$model    = $result->getModelMetadata();
		} catch ( \Throwable $e ) {
			return null;
		}

		$provider_id = is_object( $provider ) && method_exists( $provider, 'getId' ) ? $provider->getId() : null;
		$model_id    = is_object( $model ) && method_exists( $model, 'getId' ) ? $model->getId() : null;

		return is_string( $provider_id ) && is_string( $model_id )
			? array(
				'provider' => $provider_id,
				'model'    => $model_id,
			)
			: null;
	}

	/**
	 * Reads the text of a core result, refusing one from another provider.
	 *
	 * @param  mixed                                  $result Core GenerativeAiResult, or WP_Error.
	 * @param  array{provider: string, model: string} $pinned Provider and model the request was pinned to.
	 * @return array{text: string, answered_by: array{provider: string, model: string}|null}|WP_Error
	 */
	public static function read_result( mixed $result, array $pinned ): array|WP_Error {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_object( $result ) || ! method_exists( $result, 'toText' ) ) {
			return new WP_Error( 'ai_empty_response', __( 'The AI returned an empty response.', 'fresh-eyes' ) );
		}

		$answered_by = self::answered_by( $result );
		if ( null !== $answered_by && $answered_by['provider'] !== $pinned['provider'] ) {
			// Can't happen with a pinned model; a guard so no answer from
			// another provider is ever shown as the chosen one's.
			Logger::debug( sprintf( 'Pinned to %s/%s, but %s/%s answered', $pinned['provider'], $pinned['model'], $answered_by['provider'], $answered_by['model'] ) );
			return new WP_Error(
				self::ERROR_WRONG_PROVIDER,
				sprintf(
					/* translators: 1: AI provider ID that answered, 2: AI provider ID the request was sent to. */
					__( 'The answer came from %1$s instead of %2$s, so Fresh Eyes did not use it.', 'fresh-eyes' ),
					$answered_by['provider'],
					$pinned['provider']
				),
				array( 'status' => 502 )
			);
		}

		try {
			$text = $result->toText();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'ai_empty_response', __( 'The AI returned an empty response.', 'fresh-eyes' ) );
		}

		return array(
			'text'        => is_string( $text ) ? $text : '',
			'answered_by' => $answered_by,
		);
	}

	/**
	 * Finds the ID a provider lists a model under.
	 *
	 * The exact ID wins. Otherwise a dated release of it (`<id>-20250514`,
	 * `<id>-2024-08-06`) or `<id>-latest`, newest first, because providers
	 * often list only dated IDs for what the plugin calls by its short name.
	 * Google lists IDs with a `models/` prefix, which is ignored.
	 *
	 * @param  object $registry Provider registry.
	 * @param  string $provider Provider ID.
	 * @param  string $model_id Model ID.
	 * @return string|null Listed ID, or null when the provider doesn't list the model.
	 * @throws \Throwable When the provider's model list can't be read.
	 */
	private function find_listed_id( object $registry, string $provider, string $model_id ): ?string {
		$directory = $this->get_model_directory( $registry, $provider );
		if ( null === $directory || ! method_exists( $directory, 'hasModelMetadata' ) || ! method_exists( $directory, 'listModelMetadata' ) ) {
			return null;
		}

		if ( true === $directory->hasModelMetadata( $model_id ) ) {
			return $model_id;
		}

		$listed = array();
		foreach ( (array) $directory->listModelMetadata() as $metadata ) {
			$id = is_object( $metadata ) && method_exists( $metadata, 'getId' ) ? $metadata->getId() : null;
			if ( is_string( $id ) ) {
				$listed[] = $id;
			}
		}

		$pattern = '/^(?:models\/)?' . preg_quote( $model_id, '/' ) . '(?:-\d{8}|-\d{4}-\d{2}-\d{2}|-latest)?$/';
		$matches = array_values( array_filter( $listed, static fn ( string $id ): bool => 1 === preg_match( $pattern, $id ) ) );
		rsort( $matches, SORT_STRING );
		$match = $matches[0] ?? null;

		/**
		 * Filters the ID a provider runs a model under, for a model the
		 * provider lists under a name Fresh Eyes doesn't recognize.
		 *
		 * Return an ID from `$listed`; any other value is ignored.
		 *
		 * @param string|null  $match    Listed ID, or null when none matched.
		 * @param string       $model_id Model ID from the Fresh Eyes model list.
		 * @param string       $provider Provider ID.
		 * @param list<string> $listed   IDs the provider lists.
		 */
		$filtered = apply_filters( 'ai_feedback_provider_model_id', $match, $model_id, $provider, $listed );

		return is_string( $filtered ) && in_array( $filtered, $listed, true ) ? $filtered : null;
	}

	/**
	 * Finds a model ID in the configured providers, for a model with no provider in the list.
	 *
	 * @param  object $registry Provider registry.
	 * @param  string $model_id Model ID.
	 * @param  string $name     Model name for messages.
	 * @return array{provider: string, model_id: string, model: object}|WP_Error
	 */
	private function resolve_without_provider( object $registry, string $model_id, string $name ): array|WP_Error {
		if ( ! method_exists( $registry, 'getProviderModel' ) ) {
			return new WP_Error( 'ai_client_missing', __( 'The WordPress core AI Client is unavailable. WordPress 7.0 or higher is required.', 'fresh-eyes' ) );
		}

		$providers = method_exists( $registry, 'getRegisteredProviderIds' ) ? $registry->getRegisteredProviderIds() : array();
		foreach ( is_array( $providers ) ? $providers : array() as $provider ) {
			if ( ! is_string( $provider ) || ! $this->is_provider_ready( $registry, $provider ) ) {
				continue;
			}
			try {
				$directory = $this->get_model_directory( $registry, $provider );
				if ( null === $directory || ! method_exists( $directory, 'hasModelMetadata' ) || true !== $directory->hasModelMetadata( $model_id ) ) {
					continue;
				}
				$model = $registry->getProviderModel( $provider, $model_id );
			} catch ( \Throwable $e ) {
				Logger::debug( sprintf( 'Could not check %s for model %s: %s', $provider, $model_id, $e->getMessage() ) );
				continue;
			}
			if ( is_object( $model ) ) {
				return array(
					'provider' => $provider,
					'model_id' => $model_id,
					'model'    => $model,
				);
			}
		}

		return new WP_Error(
			self::ERROR_MODEL_UNAVAILABLE,
			sprintf(
				/* translators: 1: model name, 2: model ID. */
				__( '%1$s (%2$s) is not available from any AI provider set up under Settings > Connectors, so Fresh Eyes did not send the request. Choose another model in the Fresh Eyes settings.', 'fresh-eyes' ),
				$name,
				$model_id
			),
			array(
				'status' => 400,
				'model'  => $model_id,
			)
		);
	}

	/**
	 * Error for a model list that couldn't be read. Nothing is sent: without
	 * the list there is no way to tell the model is there.
	 *
	 * @param  string $model_id      Model ID.
	 * @param  string $name          Model name.
	 * @param  string $provider      Provider ID.
	 * @param  string $provider_name Provider name.
	 * @return WP_Error
	 */
	private function check_failed_error( string $model_id, string $name, string $provider, string $provider_name ): WP_Error {
		return new WP_Error(
			self::ERROR_MODEL_CHECK_FAILED,
			sprintf(
				/* translators: 1: model name, 2: AI provider name. */
				__( 'Fresh Eyes could not confirm that %1$s is available from %2$s, so it did not send the request. Please try again in a moment.', 'fresh-eyes' ),
				$name,
				$provider_name
			),
			array(
				'status'   => 503,
				'model'    => $model_id,
				'provider' => $provider,
			)
		);
	}

	/**
	 * Whether a provider is registered and has credentials.
	 *
	 * @param  object $registry Provider registry.
	 * @param  string $provider Provider ID.
	 * @return bool
	 */
	private function is_provider_ready( object $registry, string $provider ): bool {
		if ( ! method_exists( $registry, 'hasProvider' ) || ! method_exists( $registry, 'isProviderConfigured' ) ) {
			return false;
		}
		try {
			return true === $registry->hasProvider( $provider ) && true === $registry->isProviderConfigured( $provider );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * A provider's model metadata directory.
	 *
	 * @param  object $registry Provider registry.
	 * @param  string $provider Provider ID.
	 * @return object|null Directory, or null when the provider has none.
	 */
	private function get_model_directory( object $registry, string $provider ): ?object {
		if ( ! method_exists( $registry, 'getProviderClassName' ) ) {
			return null;
		}
		$class = $registry->getProviderClassName( $provider );
		if ( ! is_string( $class ) || ! class_exists( $class ) || ! method_exists( $class, 'modelMetadataDirectory' ) ) {
			return null;
		}
		$directory = $class::modelMetadataDirectory();
		return is_object( $directory ) ? $directory : null;
	}

	/**
	 * A provider's display name, or its ID.
	 *
	 * @param  object $registry Provider registry.
	 * @param  string $provider Provider ID.
	 * @return string
	 */
	private function provider_name( object $registry, string $provider ): string {
		try {
			$class    = method_exists( $registry, 'getProviderClassName' ) ? $registry->getProviderClassName( $provider ) : null;
			$metadata = is_string( $class ) && method_exists( $class, 'metadata' ) ? $class::metadata() : null;
			$name     = is_object( $metadata ) && method_exists( $metadata, 'getName' ) ? $metadata->getName() : null;
		} catch ( \Throwable $e ) {
			$name = null;
		}
		return is_string( $name ) && '' !== $name ? $name : $provider;
	}

	/**
	 * Gets the core AI Client provider registry.
	 *
	 * @return object|null Registry, or null when the AI Client is unavailable.
	 */
	protected function get_provider_registry(): ?object {
		if ( ! class_exists( \WordPress\AiClient\AiClient::class ) ) {
			return null;
		}

		try {
			return \WordPress\AiClient\AiClient::defaultRegistry();
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
