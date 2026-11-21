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
	private const MODELS_CACHE_KEY = 'ai_feedback_available_models_v5';

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
	 * Get the user's saved model, or the site default, falling back when it
	 * is no longer offered (such as a retired model ID).
	 *
	 * The fallback is the plugin default, or the first offered model when a
	 * filter removed the plugin default from the list.
	 *
	 * @return string Model ID.
	 */
	private function get_default_model(): string {
		$available = wp_list_pluck( $this->get_available_models(), 'id' );

		// The user's own choice wins over the site default.
		$saved = array(
			User_Preferences::get( 'ai_feedback_default_model', AI_FEEDBACK_DEFAULT_MODEL ),
			get_option( 'ai_feedback_default_model', AI_FEEDBACK_DEFAULT_MODEL ),
		);
		foreach ( $saved as $model ) {
			if ( is_string( $model ) && in_array( $model, $available, true ) ) {
				return $model;
			}
		}

		if ( in_array( AI_FEEDBACK_DEFAULT_MODEL, $available, true ) || empty( $available ) ) {
			return AI_FEEDBACK_DEFAULT_MODEL;
		}

		$first = reset( $available );
		return is_string( $first ) ? $first : AI_FEEDBACK_DEFAULT_MODEL;
	}

	/**
	 * Get settings: the current user's preferences over the site defaults.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_settings( WP_REST_Request $request ) {
		$settings = array(
			'default_model'         => $this->get_default_model(),
			'default_focus_areas'   => User_Preferences::get( 'ai_feedback_default_focus_areas', array( 'content', 'tone', 'flow' ) ),
			'default_tone'          => User_Preferences::get( 'ai_feedback_default_tone', 'professional' ),
			'feedback_locale'       => User_Preferences::get( 'ai_feedback_feedback_locale', 'auto' ),
			'default_persona'       => Personas::normalize( User_Preferences::get( 'ai_feedback_default_persona', Personas::DEFAULT_PERSONA ) ),
			'review_audience'       => Personas::sanitize_audience( User_Preferences::get( 'ai_feedback_review_audience', '' ) ),
			'available_personas'    => Personas::get_for_rest(),
			'available_models'      => $this->get_available_models(),
			'available_focus_areas' => $this->get_available_focus_areas(),
			'available_tones'       => $this->get_available_tones(),
			'available_locales'     => $this->get_available_locales(),
			'chunk_size'            => Review_Chunks::get_chunk_size(),
			'send_images'           => Review_Images::is_enabled(),
			'can_manage_site'       => current_user_can( 'manage_options' ),
		);

		return rest_ensure_response( $settings );
	}

	/**
	 * Update settings.
	 *
	 * Saves the current user's preferences, or the site defaults when `scope`
	 * is `site`. `send_images` is a setting of the site under either scope.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_settings( WP_REST_Request $request ) {
		$updated = array();
		$save    = 'site' === $request->get_param( 'scope' ) ? 'update_option' : array( User_Preferences::class, 'set' );

		// Update default model if provided.
		if ( $request->has_param( 'default_model' ) ) {
			$model = sanitize_text_field( $request->get_param( 'default_model' ) );

			// Validate model exists.
			if ( ! $this->is_valid_model( $model ) ) {
				return new WP_Error(
					'invalid_model',
					__( 'The specified model is not available.', 'fresh-eyes' ),
					array( 'status' => 400 )
				);
			}

			$save( 'ai_feedback_default_model', $model );
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
							__( 'Invalid focus area: %s', 'fresh-eyes' ),
							$area
						),
						array( 'status' => 400 )
					);
				}
			}

			$save( 'ai_feedback_default_focus_areas', $focus_areas );
			$updated['default_focus_areas'] = $focus_areas;
		}

		// Update default tone if provided.
		if ( $request->has_param( 'default_tone' ) ) {
			$tone = sanitize_text_field( $request->get_param( 'default_tone' ) );

			// Validate tone.
			if ( ! $this->is_valid_tone( $tone ) ) {
				return new WP_Error(
					'invalid_tone',
					__( 'The specified tone is not available.', 'fresh-eyes' ),
					array( 'status' => 400 )
				);
			}

			$save( 'ai_feedback_default_tone', $tone );
			$updated['default_tone'] = $tone;
		}

		// Update feedback locale if provided.
		if ( $request->has_param( 'feedback_locale' ) ) {
			$locale = sanitize_text_field( $request->get_param( 'feedback_locale' ) );

			// Validate locale.
			if ( ! $this->is_valid_locale( $locale ) ) {
				return new WP_Error(
					'invalid_locale',
					__( 'The specified locale is not available.', 'fresh-eyes' ),
					array( 'status' => 400 )
				);
			}

			$save( 'ai_feedback_feedback_locale', $locale );
			$updated['feedback_locale'] = $locale;
		}

		// Update default persona if provided.
		if ( $request->has_param( 'default_persona' ) ) {
			$persona = $request->get_param( 'default_persona' );

			if ( ! Personas::is_valid( $persona ) ) {
				return new WP_Error(
					'invalid_persona',
					__( 'The specified persona is not available.', 'fresh-eyes' ),
					array( 'status' => 400 )
				);
			}

			$save( 'ai_feedback_default_persona', $persona );
			$updated['default_persona'] = $persona;
		}

		// Update the "Who is this for?" audience if provided.
		if ( $request->has_param( 'review_audience' ) ) {
			$audience = Personas::sanitize_audience( $request->get_param( 'review_audience' ) );

			$save( 'ai_feedback_review_audience', $audience );
			$updated['review_audience'] = $audience;
		}

		// Update whether reviews send the post's images, for the whole site.
		if ( $request->has_param( 'send_images' ) ) {
			$value       = $request->get_param( 'send_images' );
			$send_images = ( is_bool( $value ) || is_int( $value ) || is_string( $value ) ) && rest_sanitize_boolean( $value );

			// Saved as a string: update_option() can't add an option as false.
			update_option( Review_Images::OPTION, $send_images ? '1' : '0' );
			$updated['send_images'] = $send_images;
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
	 * Users who can edit posts may save their own preferences. Changing the
	 * site defaults, or a setting of the site such as `send_images`,
	 * requires `manage_options`.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function update_settings_permissions_check( WP_REST_Request $request ): bool|WP_Error {
		$is_site    = 'site' === $request->get_param( 'scope' ) || $request->has_param( 'send_images' );
		$capability = $is_site ? 'manage_options' : 'edit_posts';

		if ( ! current_user_can( $capability ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to change these settings.', 'fresh-eyes' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Get update settings arguments.
	 *
	 * @return array
	 */
	private function get_update_settings_args(): array {
		return array(
			'scope'               => array(
				'description' => __( 'Whether to save the current user\'s preferences or the site defaults.', 'fresh-eyes' ),
				'type'        => 'string',
				'enum'        => array( 'user', 'site' ),
				'default'     => 'user',
			),
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
			'default_persona'     => array(
				'type'              => 'string',
				'validate_callback' => array( Personas::class, 'is_valid' ),
			),
			'review_audience'     => array(
				'type'              => 'string',
				'maxLength'         => Personas::MAX_AUDIENCE_LENGTH,
				'sanitize_callback' => array( Personas::class, 'sanitize_audience' ),
			),
			'send_images'         => array(
				'description'       => __( 'Whether reviews send the post\'s images to the AI provider for alt text suggestions. A setting of the site, which requires manage_options.', 'fresh-eyes' ),
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
		);
	}

	/**
	 * Whether a model the plugin lists has a capability, eg. `vision`.
	 *
	 * @param  string $model_id   Model ID.
	 * @param  string $capability Capability.
	 * @return bool False for unknown models.
	 */
	public function model_has_capability( string $model_id, string $capability ): bool {
		$capabilities = $this->get_model( $model_id )['capabilities'] ?? array();
		return is_array( $capabilities ) && in_array( $capability, $capabilities, true );
	}

	/**
	 * A model the plugin lists, with its provider and capabilities.
	 *
	 * @param  string $model_id Model ID.
	 * @return array<string, mixed>|null Model, or null for unknown models.
	 */
	public function get_model( string $model_id ): ?array {
		foreach ( $this->get_available_models() as $model ) {
			if ( ( $model['id'] ?? null ) === $model_id ) {
				return $model;
			}
		}
		return null;
	}

	/**
	 * Get available AI models.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function get_available_models(): array {
		/**
		 * Cached model catalog.
		 *
		 * @var list<array<string, mixed>>|false $models
		 */
		$models = get_transient( self::MODELS_CACHE_KEY );
		if ( ! is_array( $models ) ) {
			$models = $this->get_model_catalog();
			set_transient( self::MODELS_CACHE_KEY, $models, self::MODELS_CACHE_DURATION );
		}

		// Connectors can be added or removed at any time, so only the catalog
		// is cached and the configured providers are checked on every read.
		$models = $this->filter_configured_models( $models );

		// Allow filtering of available models.
		/**
		 * Models after the `ai_feedback_available_models` filter.
		 *
		 * @var list<array<string, mixed>>|null $filtered
		 */
		$filtered = apply_filters( 'ai_feedback_available_models', $models );

		return is_array( $filtered ) ? $filtered : $models;
	}

	/**
	 * Lists every model the plugin knows about.
	 *
	 * `vision` marks models that read images. Model_Capabilities asks core
	 * first and only falls back to it when core has no metadata for a model.
	 *
	 * @return list<array<string, mixed>> Models.
	 */
	private function get_model_catalog(): array {
		return array(
			array(
				'id'           => 'claude-sonnet-5-5',
				'name'         => 'Claude Sonnet 5.5',
				'provider'     => 'anthropic',
				'capabilities' => array( 'text', 'vision' ),
				'max_tokens'   => 200000,
			),
			array(
				'id'           => 'claude-opus-5-5',
				'name'         => 'Claude Opus 5.5',
				'provider'     => 'anthropic',
				'capabilities' => array( 'text', 'vision' ),
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
				'capabilities' => array( 'text', 'vision' ),
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
		if ( ! class_exists( \WordPress\AiClient\AiClient::class ) ) {
			return null;
		}

		try {
			return \WordPress\AiClient\AiClient::defaultRegistry();
		} catch ( \Throwable $e ) {
			return null;
		}
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
				'label'       => __( 'Content Quality', 'fresh-eyes' ),
				'description' => __( 'Clarity, accuracy, and completeness', 'fresh-eyes' ),
			),
			array(
				'id'          => 'tone',
				'label'       => __( 'Tone & Voice', 'fresh-eyes' ),
				'description' => __( 'Consistency and audience appropriateness', 'fresh-eyes' ),
			),
			array(
				'id'          => 'flow',
				'label'       => __( 'Flow & Structure', 'fresh-eyes' ),
				'description' => __( 'Logical progression and transitions', 'fresh-eyes' ),
			),
			array(
				'id'          => 'design',
				'label'       => __( 'Design & Formatting', 'fresh-eyes' ),
				'description' => __( 'Block usage and visual hierarchy', 'fresh-eyes' ),
			),
			array(
				'id'          => 'grammar',
				'label'       => __( 'Grammar & Spelling', 'fresh-eyes' ),
				'description' => __( 'Typos, spelling and grammar, as one-click fixes', 'fresh-eyes' ),
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
				'label' => __( 'Professional', 'fresh-eyes' ),
			),
			array(
				'id'    => 'casual',
				'label' => __( 'Casual', 'fresh-eyes' ),
			),
			array(
				'id'    => 'academic',
				'label' => __( 'Academic', 'fresh-eyes' ),
			),
			array(
				'id'    => 'friendly',
				'label' => __( 'Friendly', 'fresh-eyes' ),
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
				'label' => __( 'Match WordPress locale', 'fresh-eyes' ),
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
