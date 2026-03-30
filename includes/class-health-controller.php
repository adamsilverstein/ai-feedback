<?php
/**
 * Health Check REST API Controller
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Response;

/**
 * Health check REST API controller.
 *
 * Provides a /ai-feedback/v1/health endpoint for checking
 * plugin status and dependency availability.
 */
class Health_Controller extends WP_REST_Controller {

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
	protected $rest_base = 'health';

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_health' ),
					'permission_callback' => array( $this, 'get_health_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Permission check for health endpoint.
	 *
	 * Requires edit_posts capability so only authenticated editors
	 * and above can access dependency/version details.
	 *
	 * @return bool
	 */
	public function get_health_permissions_check(): bool {
		return \current_user_can( 'edit_posts' );
	}

	/**
	 * Get health status.
	 *
	 * @return WP_REST_Response
	 */
	public function get_health(): WP_REST_Response {
		$ai_available = class_exists( 'WordPress\AiClient\AiClient' );

		$status = $ai_available ? 'ok' : 'degraded';

		return new WP_REST_Response(
			array(
				'status'       => $status,
				'version'      => AI_FEEDBACK_VERSION,
				'ai_available' => $ai_available,
				'notes_api'    => true,
				'php_version'  => PHP_VERSION,
				'wp_version'   => \get_bloginfo( 'version' ),
			)
		);
	}
}
