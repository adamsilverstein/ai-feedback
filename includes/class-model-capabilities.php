<?php
/**
 * Model Capabilities
 *
 * Whether a model can read the images a review sends for alt text.
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

/**
 * Answers whether a model reads images.
 *
 * Asks the core AI client first, from the model metadata the provider
 * reports, which is the same check core makes when it picks a model for a
 * prompt with images. When core has no metadata for the model (no AI
 * client, provider not registered or set up, or a model the provider
 * doesn't list), the `vision` capability in the plugin's model list
 * decides. The `ai_feedback_model_supports_vision` filter has the last word.
 */
class Model_Capabilities {

	/**
	 * Core's ID for the text generation capability.
	 */
	private const TEXT_GENERATION = 'text_generation';

	/**
	 * Core's ID for the input modalities option.
	 */
	private const INPUT_MODALITIES = 'input_modalities';

	/**
	 * Input modalities of a review request with images.
	 */
	private const IMAGE_REVIEW_MODALITIES = array( 'text', 'image' );

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
	 * Whether a model can be sent images with a review.
	 *
	 * @param  string $model_id Model ID.
	 * @return bool
	 */
	public function supports_vision( string $model_id ): bool {
		$model    = $this->settings->get_model( $model_id );
		$provider = is_string( $model['provider'] ?? null ) ? $model['provider'] : '';
		$supports = $this->core_supports_images( $model_id, $provider );
		$source   = 'core';

		if ( null === $supports ) {
			$supports = $this->settings->model_has_capability( $model_id, 'vision' );
			$source   = 'catalog';
		}

		Logger::debug( sprintf( 'Model %s %s images (from %s)', $model_id, $supports ? 'reads' : 'does not read', $source ) );

		/**
		 * Filters whether a model is sent images, so it can propose alt text.
		 *
		 * @param bool   $supports Whether the model reads images.
		 * @param string $model_id Model ID.
		 * @param string $source   What decided: `core` for the AI client's model
		 *                         metadata, `catalog` for the plugin's model list.
		 */
		return true === apply_filters( 'ai_feedback_model_supports_vision', $supports, $model_id, $source );
	}

	/**
	 * Whether core's model metadata says a model takes text and image input.
	 *
	 * @param  string $model_id Model ID.
	 * @param  string $provider Provider ID from the model list, or '' to look
	 *                          through every configured provider.
	 * @return bool|null Null when core has no metadata for the model.
	 */
	public function core_supports_images( string $model_id, string $provider ): ?bool {
		$registry = $this->get_provider_registry();
		if ( null === $registry ) {
			return null;
		}

		try {
			$providers = '' !== $provider ? array( $provider ) : $this->registered_providers( $registry );
			foreach ( $providers as $provider_id ) {
				$metadata = $this->find_model_metadata( $registry, $provider_id, $model_id );
				if ( null !== $metadata ) {
					return self::metadata_accepts_images( $metadata );
				}
			}
		} catch ( \Throwable $e ) {
			// Listing models can need a request to the provider; treat a
			// failure as no answer rather than failing the review.
			Logger::debug( sprintf( 'Could not read model metadata for %s: %s', $model_id, $e->getMessage() ) );
		}

		return null;
	}

	/**
	 * Provider IDs the registry knows.
	 *
	 * @param  object $registry Provider registry.
	 * @return list<string> Provider IDs.
	 */
	private function registered_providers( object $registry ): array {
		if ( ! method_exists( $registry, 'getRegisteredProviderIds' ) ) {
			return array();
		}
		$ids = $registry->getRegisteredProviderIds();
		return is_array( $ids ) ? array_values( array_filter( $ids, 'is_string' ) ) : array();
	}

	/**
	 * A configured provider's metadata for a model.
	 *
	 * @param  object $registry    Provider registry.
	 * @param  string $provider_id Provider ID.
	 * @param  string $model_id    Model ID.
	 * @return object|null Model metadata, or null when the provider isn't set up or doesn't list the model.
	 */
	private function find_model_metadata( object $registry, string $provider_id, string $model_id ): ?object {
		if (
			! method_exists( $registry, 'hasProvider' )
			|| ! method_exists( $registry, 'isProviderConfigured' )
			|| ! method_exists( $registry, 'getProviderClassName' )
			|| true !== $registry->hasProvider( $provider_id )
			|| true !== $registry->isProviderConfigured( $provider_id )
		) {
			return null;
		}

		$class = $registry->getProviderClassName( $provider_id );
		if ( ! is_string( $class ) || ! class_exists( $class ) || ! method_exists( $class, 'modelMetadataDirectory' ) ) {
			return null;
		}

		$directory = $class::modelMetadataDirectory();
		if (
			! is_object( $directory )
			|| ! method_exists( $directory, 'hasModelMetadata' )
			|| ! method_exists( $directory, 'getModelMetadata' )
			|| true !== $directory->hasModelMetadata( $model_id )
		) {
			return null;
		}

		$metadata = $directory->getModelMetadata( $model_id );
		return is_object( $metadata ) ? $metadata : null;
	}

	/**
	 * Whether model metadata meets a review's needs with images: text
	 * generation, from text and image input together. Mirrors core's
	 * ModelRequirements::areMetBy() for that prompt.
	 *
	 * @param  object $metadata Core ModelMetadata.
	 * @return bool
	 */
	private static function metadata_accepts_images( object $metadata ): bool {
		if ( ! method_exists( $metadata, 'getSupportedCapabilities' ) || ! method_exists( $metadata, 'getSupportedOptions' ) ) {
			return false;
		}

		$capabilities = $metadata->getSupportedCapabilities();
		$generates    = false;
		foreach ( is_array( $capabilities ) ? $capabilities : array() as $capability ) {
			if ( self::TEXT_GENERATION === self::enum_value( $capability ) ) {
				$generates = true;
				break;
			}
		}
		if ( ! $generates ) {
			return false;
		}

		$options = $metadata->getSupportedOptions();
		foreach ( is_array( $options ) ? $options : array() as $option ) {
			if (
				is_object( $option )
				&& method_exists( $option, 'getName' )
				&& method_exists( $option, 'isSupportedValue' )
				&& self::INPUT_MODALITIES === self::enum_value( $option->getName() )
			) {
				// Core compares a set of modalities by value, so strings match its enums.
				return true === $option->isSupportedValue( self::IMAGE_REVIEW_MODALITIES );
			}
		}

		// No input modalities listed: core wouldn't send this model images.
		return false;
	}

	/**
	 * The string value of a core enum.
	 *
	 * @param  mixed $value Enum, or anything else.
	 * @return string Value, or '' when there is none.
	 */
	private static function enum_value( mixed $value ): string {
		// Core enums cast to their value.
		return is_string( $value ) || $value instanceof \Stringable ? (string) $value : '';
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
