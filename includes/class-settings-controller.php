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
	private const MODELS_CACHE_KEY = 'ai_feedback_available_models';

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
		$ai_client_available = class_exists( 'WordPress\AiClient\AiClient' );

		$status = array(
			'ai_client_available' => $ai_client_available,
			'settings_url'        => admin_url( 'options-general.php?page=wp-ai-client' ),
		);

		return rest_ensure_response( $status );
	}

	/**
	 * Get settings.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_settings( WP_REST_Request $request ) {
		$settings = array(
			'default_model'         => get_option( 'ai_feedback_default_model', 'claude-sonnet-4' ),
			'default_focus_areas'   => get_option( 'ai_feedback_default_focus_areas', array( 'content', 'tone', 'flow' ) ),
			'default_tone'          => get_option( 'ai_feedback_default_tone', 'professional' ),
			'available_models'      => $this->get_available_models(),
			'available_focus_areas' => $this->get_available_focus_areas(),
			'available_tones'       => $this->get_available_tones(),
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
		);
	}

	/**
	 * Get available AI models.
	 *
	 * @return array
	 */
	private function get_available_models(): array {
		// Try to get from cache.
		$cached = get_transient( self::MODELS_CACHE_KEY );
		if ( false !== $cached ) {
			return $cached;
		}

		// Define available models.
		$models = array(
			array(
				'id'           => 'claude-sonnet-4',
				'name'         => 'Claude Sonnet 4',
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

		// Allow filtering of available models.
		$models = apply_filters( 'ai_feedback_available_models', $models );

		// Cache the result.
		set_transient( self::MODELS_CACHE_KEY, $models, self::MODELS_CACHE_DURATION );

		return $models;
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
}
