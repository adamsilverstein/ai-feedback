<?php
/**
 * Settings REST API Controller
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Settings REST API controller.
 */
class Settings_Controller extends WP_REST_Controller {



	/**
	 * Namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'ai-feedback/v1';

	/**
	 * Rest base.
	 *
	 * @var string
	 */
	protected $rest_base = 'settings';

	/**
	 * Available AI models cache key.
	 *
	 * @var string
	 */
	private const MODELS_CACHE_KEY = 'ai_feedback_available_models_v3';

	/**
	 * Cache duration for models (1 hour).
	 *
	 * @var int
	 */
	private const MODELS_CACHE_DURATION = HOUR_IN_SECONDS;

	/**
	 * Register REST API routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'get_settings_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'update_settings_permissions_check' ),
					'args'                => $this->get_update_settings_args(),
				),
			)
		);

		// Register status endpoint to check AI client availability.
		register_rest_route(
			$this->namespace,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'get_settings_permissions_check' ),
			)
		);
	}

	/**
	 * Get AI client status.
	 *
	 * @return WP_REST_Response Status response.
	 */
	public function get_status(): \WP_REST_Response {
		$status = array(
			'ai_client_available'  => function_exists( 'wp_ai_client_prompt' ),
			'connector_configured' => $this->has_configured_ai_provider(),
			'settings_url'         => admin_url( 'options-general.php?page=connectors' ),
		);

		return rest_ensure_response( $status );
	}

	/**
	 * Whether a configured AI provider can handle text generation.
	 *
	 * Core registers connectors for its built-in providers whether or not
	 * they have credentials, so connector registration alone is not enough.
	 *
	 * @return bool True when at least one provider supports text generation.
	 */
	protected function has_configured_ai_provider(): bool {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}

		try {
			return wp_ai_client_prompt()->is_supported_for_text_generation();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Get the saved default model, falling back when it is no longer offered
	 * (such as a retired model ID).
	 *
	 * The fallback is the plugin default, or the first offered model when a
	 * filter removed the plugin default from the list.
	 *
	 * @return string Model ID.
	 */
	private function get_default_model(): string {
		$model     = get_option( 'ai_feedback_default_model', AI_FEEDBACK_DEFAULT_MODEL );
		$available = wp_list_pluck( $this->get_available_models(), 'id' );

		if ( is_string( $model ) && in_array( $model, $available, true ) ) {
			return $model;
		}

		if ( in_array( AI_FEEDBACK_DEFAULT_MODEL, $available, true ) || empty( $available ) ) {
			return AI_FEEDBACK_DEFAULT_MODEL;
		}

		$first = reset( $available );
		return is_string( $first ) ? $first : AI_FEEDBACK_DEFAULT_MODEL;
	}

	/**
	 * Get settings.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_settings( WP_REST_Request $request ) {
		$settings = array(
			'default_model'         => $this->get_default_model(),
			'default_focus_areas'   => get_option( 'ai_feedback_default_focus_areas', array( 'content', 'tone', 'flow' ) ),
			'default_tone'          => get_option( 'ai_feedback_default_tone', 'professional' ),
			'feedback_locale'       => get_option( 'ai_feedback_feedback_locale', 'auto' ),
			'available_models'      => $this->get_available_models(),
			'available_focus_areas' => $this->get_available_focus_areas(),
			'available_tones'       => $this->get_available_tones(),
			'available_locales'     => $this->get_available_locales(),
			'chunk_size'            => Review_Chunks::get_chunk_size(),
		);

		return rest_ensure_response( $settings );
	}

	/**
	 * Update settings.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_settings( WP_REST_Request $request ) {
		$updated = array();

		// Update default model if provided.
		if ( $request->has_param( 'default_model' ) ) {
			$model = sanitize_text_field( $request->get_param( 'default_model' ) );

			// Validate model exists.
			if ( ! $this->is_valid_model( $model ) ) {
				return new WP_Error(
					'invalid_model',
					__( 'The specified model is not available.', 'ai-feedback' ),
					array( 'status' => 400 )
				);
			}

			update_option( 'ai_feedback_default_model', $model );
			$updated['default_model'] = $model;
		}

		// Update default focus areas if provided.
		if ( $request->has_param( 'default_focus_areas' ) ) {
			$focus_areas = $request->get_param( 'default_focus_areas' );

			// Validate all focus areas.
			foreach ( $focus_areas as $area ) {
				if ( ! $this->is_valid_focus_area( $area ) ) {
					return new WP_Error(
						'invalid_focus_area',
						sprintf(
						/* translators: %s: invalid focus area */
							__( 'Invalid focus area: %s', 'ai-feedback' ),
							$area
						),
						array( 'status' => 400 )
					);
				}
			}

			update_option( 'ai_feedback_default_focus_areas', $focus_areas );
			$updated['default_focus_areas'] = $focus_areas;
		}

		// Update default tone if provided.
		if ( $request->has_param( 'default_tone' ) ) {
			$tone = sanitize_text_field( $request->get_param( 'default_tone' ) );

			// Validate tone.
			if ( ! $this->is_valid_tone( $tone ) ) {
				return new WP_Error(
					'invalid_tone',
					__( 'The specified tone is not available.', 'ai-feedback' ),
					array( 'status' => 400 )
				);
			}

			update_option( 'ai_feedback_default_tone', $tone );
			$updated['default_tone'] = $tone;
		}

		// Update feedback locale if provided.
		if ( $request->has_param( 'feedback_locale' ) ) {
			$locale = sanitize_text_field( $request->get_param( 'feedback_locale' ) );

			// Validate locale.
			if ( ! $this->is_valid_locale( $locale ) ) {
				return new WP_Error(
					'invalid_locale',
					__( 'The specified locale is not available.', 'ai-feedback' ),
					array( 'status' => 400 )
				);
			}

			update_option( 'ai_feedback_feedback_locale', $locale );
			$updated['feedback_locale'] = $locale;
		}

		// Return updated settings.
		return $this->get_settings( $request );
	}

	/**
	 * Check permissions for getting settings.
	 *
	 * @return bool
	 */
	public function get_settings_permissions_check(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Check permissions for updating settings.
	 *
	 * @return bool
	 */
	public function update_settings_permissions_check(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Get update settings arguments.
	 *
	 * @return array
	 */
	private function get_update_settings_args(): array {
		return array(
			'default_model'       => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( $this, 'is_valid_model' ),
			),
			'default_focus_areas' => array(
				'type'  => 'array',
				'items' => array(
					'type' => 'string',
				),
			),
			'default_tone'        => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( $this, 'is_valid_tone' ),
			),
			'feedback_locale'     => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( $this, 'is_valid_locale' ),
			),
		);
	}

	/**
	 * Get available AI models.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function get_available_models(): array {
		/** @var list<array<string, mixed>>|false $models */
		$models = get_transient( self::MODELS_CACHE_KEY );
		if ( ! is_array( $models ) ) {
			$models = $this->get_model_catalog();
			set_transient( self::MODELS_CACHE_KEY, $models, self::MODELS_CACHE_DURATION );
		}

		// Connectors can be added or removed at any time, so only the catalog
		// is cached and the configured providers are checked on every read.
		$models = $this->filter_configured_models( $models );

		// Allow filtering of available models.
		$filtered = apply_filters( 'ai_feedback_available_models', $models );

		/** @var list<array<string, mixed>> */
		return is_array( $filtered ) ? $filtered : $models;
	}

	/**
	 * Lists every model the plugin knows about.
	 *
	 * @return list<array<string, mixed>> Models.
	 */
	private function get_model_catalog(): array {
		return array(
			array(
				'id'           => 'claude-sonnet-5-5',
				'name'         => 'Claude Sonnet 5.5',
				'provider'     => 'anthropic',
				'capabilities' => array( 'text' ),
				'max_tokens'   => 200000,
			),
			array(
				'id'           => 'claude-opus-4',
				'name'         => 'Claude Opus 4',
				'provider'     => 'anthropic',
				'capabilities' => array( 'text' ),
				'max_tokens'   => 200000,
			),
			array(
				'id'           => 'gpt-4o',
				'name'         => 'GPT-4o',
				'provider'     => 'openai',
				'capabilities' => array( 'text', 'vision' ),
				'max_tokens'   => 128000,
			),
			array(
				'id'           => 'gemini-2.0-flash',
				'name'         => 'Gemini 2.0 Flash',
				'provider'     => 'google',
				'capabilities' => array( 'text' ),
				'max_tokens'   => 1000000,
			),
		);
	}

	/**
	 * Limits models to the providers that are set up under Settings > Connectors.
	 *
	 * Keeps the full list when no listed provider is configured, so the
	 * selector still has options before a connector is added. Runs before the
	 * `ai_feedback_available_models` filter, so models added there are kept.
	 *
	 * @param  list<array<string, mixed>> $models Models, each with a `provider` ID.
	 * @return list<array<string, mixed>> Models whose provider is configured.
	 */
	protected function filter_configured_models( array $models ): array {
		$registry = $this->get_provider_registry();
		if ( null === $registry || ! method_exists( $registry, 'hasProvider' ) || ! method_exists( $registry, 'isProviderConfigured' ) ) {
			return $models;
		}

		try {
			$configured = array();
			foreach ( $models as $model ) {
				$provider = is_string( $model['provider'] ?? null ) ? $model['provider'] : '';
				if ( ! isset( $configured[ $provider ] ) ) {
					$configured[ $provider ] = $registry->hasProvider( $provider ) && $registry->isProviderConfigured( $provider );
				}
			}
		} catch ( \Throwable $e ) {
			return $models;
		}

		$available = array_values(
			array_filter(
				$models,
				static fn ( array $model ): bool => is_string( $model['provider'] ?? null ) && ! empty( $configured[ $model['provider'] ] )
			)
		);

		return array() === $available ? $models : $available;
	}

	/**
	 * Gets the core AI Client provider registry.
	 *
	 * @return object|null Registry, or null when the AI Client is unavailable.
	 */
	protected function get_provider_registry(): ?object {
		$get_registry = array( '\WordPress\AiClient\AiClient', 'defaultRegistry' );
		if ( ! is_callable( $get_registry ) ) {
			return null;
		}

		try {
			$registry = call_user_func( $get_registry );
		} catch ( \Throwable $e ) {
			return null;
		}

		return is_object( $registry ) ? $registry : null;
	}

	/**
	 * Get available focus areas.
	 *
	 * @return array
	 */
	private function get_available_focus_areas(): array {
		return array(
			array(
				'id'          => 'content',
				'label'       => __( 'Content Quality', 'ai-feedback' ),
				'description' => __( 'Clarity, accuracy, and completeness', 'ai-feedback' ),
			),
			array(
				'id'          => 'tone',
				'label'       => __( 'Tone & Voice', 'ai-feedback' ),
				'description' => __( 'Consistency and audience appropriateness', 'ai-feedback' ),
			),
			array(
				'id'          => 'flow',
				'label'       => __( 'Flow & Structure', 'ai-feedback' ),
				'description' => __( 'Logical progression and transitions', 'ai-feedback' ),
			),
			array(
				'id'          => 'design',
				'label'       => __( 'Design & Formatting', 'ai-feedback' ),
				'description' => __( 'Block usage and visual hierarchy', 'ai-feedback' ),
			),
		);
	}

	/**
	 * Get available tones.
	 *
	 * @return array
	 */
	private function get_available_tones(): array {
		return array(
			array(
				'id'    => 'professional',
				'label' => __( 'Professional', 'ai-feedback' ),
			),
			array(
				'id'    => 'casual',
				'label' => __( 'Casual', 'ai-feedback' ),
			),
			array(
				'id'    => 'academic',
				'label' => __( 'Academic', 'ai-feedback' ),
			),
			array(
				'id'    => 'friendly',
				'label' => __( 'Friendly', 'ai-feedback' ),
			),
		);
	}

	/**
	 * Get available locales for feedback.
	 *
	 * @return array
	 */
	private function get_available_locales(): array {
		$locales = array(
			array(
				'id'    => 'auto',
				'label' => __( 'Match WordPress locale', 'ai-feedback' ),
			),
			array(
				'id'    => 'en_US',
				'label' => 'English',
			),
		);

		// Get language configs from prompt builder to ensure consistency.
		$language_configs = apply_filters( 'ai_feedback_language_configs', $this->get_default_language_configs() );

		// Map language configs to locale options.
		$locale_labels = array(
			'es_ES' => 'Español',
			'fr_FR' => 'Français',
			'de_DE' => 'Deutsch',
			'it_IT' => 'Italiano',
			'pt_BR' => 'Português (Brasil)',
			'nl_NL' => 'Nederlands',
			'ja'    => '日本語',
			'zh_CN' => '简体中文',
		);

		foreach ( array_keys( $language_configs ) as $locale_id ) {
			if ( isset( $locale_labels[ $locale_id ] ) ) {
				$locales[] = array(
					'id'    => $locale_id,
					'label' => $locale_labels[ $locale_id ],
				);
			}
		}

		return $locales;
	}

	/**
	 * Get default language configurations.
	 * This mirrors the configurations in Prompt_Builder to ensure consistency.
	 *
	 * @return array Language configurations.
	 */
	private function get_default_language_configs(): array {
		return array(
			'es_ES' => array( 'name' => 'Spanish' ),
			'fr_FR' => array( 'name' => 'French' ),
			'de_DE' => array( 'name' => 'German' ),
			'it_IT' => array( 'name' => 'Italian' ),
			'pt_BR' => array( 'name' => 'Brazilian Portuguese' ),
			'nl_NL' => array( 'name' => 'Dutch' ),
			'ja'    => array( 'name' => 'Japanese' ),
			'zh_CN' => array( 'name' => 'Simplified Chinese' ),
		);
	}

	/**
	 * Check if model is valid.
	 *
	 * @param  string $model Model ID.
	 * @return bool
	 */
	public function is_valid_model( string $model ): bool {
		$models    = $this->get_available_models();
		$model_ids = array_column( $models, 'id' );
		return in_array( $model, $model_ids, true );
	}

	/**
	 * Check if focus area is valid.
	 *
	 * @param  string $area Focus area ID.
	 * @return bool
	 */
	private function is_valid_focus_area( string $area ): bool {
		$areas    = $this->get_available_focus_areas();
		$area_ids = array_column( $areas, 'id' );
		return in_array( $area, $area_ids, true );
	}

	/**
	 * Check if tone is valid.
	 *
	 * @param  string $tone Tone ID.
	 * @return bool
	 */
	public function is_valid_tone( string $tone ): bool {
		$tones    = $this->get_available_tones();
		$tone_ids = array_column( $tones, 'id' );
		return in_array( $tone, $tone_ids, true );
	}

	/**
	 * Check if locale is valid.
	 *
	 * @param  string $locale Locale code.
	 * @return bool
	 */
	public function is_valid_locale( string $locale ): bool {
		$locales    = $this->get_available_locales();
		$locale_ids = array_column( $locales, 'id' );
		return in_array( $locale, $locale_ids, true );
	}
}
