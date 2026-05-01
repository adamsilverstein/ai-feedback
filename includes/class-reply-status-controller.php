<?php
/**
 * Reply Status REST API Controller
 *
 * Exposes the processing state of an AI reply that was scheduled
 * via Reply_Cron_Dispatcher, so the frontend can poll for completion.
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
 * Reply status controller.
 */
class Reply_Status_Controller extends WP_REST_Controller {

	/**
	 * Namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'ai-feedback/v1';

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'replies';

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'get_status_permissions_check' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission check.
	 *
	 * @return true|WP_Error
	 */
	public function get_status_permissions_check() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access reply status.', 'ai-feedback' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * GET /replies/{id}/status
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_status( WP_REST_Request $request ): WP_REST_Response {
		$comment_id = (int) $request['id'];

		$status = (string) get_comment_meta( $comment_id, Reply_Cron_Dispatcher::STATUS_META_KEY, true );

		if ( '' === $status ) {
			return new WP_REST_Response(
				array(
					'reply_id' => $comment_id,
					'status'   => 'unknown',
				),
				200
			);
		}

		$payload = array(
			'reply_id' => $comment_id,
			'status'   => $status,
		);

		if ( 'complete' === $status ) {
			$ai_reply_id = (int) get_comment_meta( $comment_id, 'ai_feedback_reply_comment_id', true );
			if ( $ai_reply_id > 0 ) {
				$payload['ai_reply_comment_id'] = $ai_reply_id;
			}
		}

		if ( 'failed' === $status ) {
			$error = (string) get_comment_meta( $comment_id, 'ai_feedback_reply_error', true );
			if ( '' !== $error ) {
				$payload['error'] = $error;
			}
		}

		return new WP_REST_Response( $payload, 200 );
	}
}
