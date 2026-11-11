<?php
/**
 * Analytics REST API Controller
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
 * Analytics REST API controller.
 */
class Analytics_Controller extends WP_REST_Controller {

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
	protected $rest_base = 'analytics';

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
					'callback'            => array( $this, 'get_analytics' ),
					'permission_callback' => array( $this, 'get_analytics_permissions_check' ),
					'args'                => array(
						'period' => array(
							'type'    => 'string',
							'default' => '30days',
							'enum'    => array_keys( Analytics::PERIODS ),
						),
					),
				),
			)
		);
	}

	/**
	 * Get the usage summary for a period.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_analytics( WP_REST_Request $request ): WP_REST_Response {
		$period = $request->get_param( 'period' );

		return new WP_REST_Response(
			Analytics::get_summary( is_string( $period ) ? $period : '30days' )
		);
	}

	/**
	 * Only site administrators may view usage analytics.
	 *
	 * @return bool|WP_Error
	 */
	public function get_analytics_permissions_check(): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view AI Feedback analytics.', 'ai-feedback' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}
}
