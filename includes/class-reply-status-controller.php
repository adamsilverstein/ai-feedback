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
							'description'       => __( 'ID of the user reply comment.', 'ai-feedback' ),
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);
	}

	/**
	 * Permission check.
	 *
	 * Status is visible to users who can edit the post the reply belongs to,
	 * matching who can read the note thread itself.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return bool|WP_Error
	 */
	public function get_status_permissions_check( WP_REST_Request $request ): bool|WP_Error {
		$comment = $this->get_reply_comment( $request );
		if ( ! $comment || 'note' !== $comment->comment_type ) {
			return new WP_Error(
				'rest_comment_invalid_id',
				__( 'Invalid reply ID.', 'ai-feedback' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_post', (int) $comment->comment_post_ID ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access reply status.', 'ai-feedback' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * GET /replies/{id}/status
	 *
	 * Note the response uses `ai_reply_comment_id`, while the backing meta key
	 * is Reply_Cron_Dispatcher::REPLY_COMMENT_ID_META_KEY.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_status( WP_REST_Request $request ): WP_REST_Response {
		$comment_id = $this->get_reply_id( $request );
		$status     = self::meta_string( $comment_id, Reply_Cron_Dispatcher::STATUS_META_KEY );
		$payload    = array(
			'reply_id' => $comment_id,
			'status'   => in_array( $status, array( 'pending', 'complete', 'failed' ), true ) ? $status : 'unknown',
		);

		if ( 'complete' === $payload['status'] ) {
			$ai_reply_id = get_comment_meta( $comment_id, Reply_Cron_Dispatcher::REPLY_COMMENT_ID_META_KEY, true );
			$ai_reply_id = is_numeric( $ai_reply_id ) ? (int) $ai_reply_id : 0;

			if ( $ai_reply_id > 0 ) {
				$payload['ai_reply_comment_id'] = $ai_reply_id;
			} else {
				// Complete without a reply ID cannot be rendered; report it as a failure.
				$payload['status'] = 'failed';
				$payload['error']  = __( 'The AI reply could not be found.', 'ai-feedback' );
			}
		}

		if ( 'failed' === $status ) {
			$error = self::meta_string( $comment_id, Reply_Cron_Dispatcher::REPLY_ERROR_META_KEY );
			if ( '' !== $error ) {
				$payload['error'] = $error;
			}
		}

		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * Get the reply status schema.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'ai-feedback-reply-status',
			'type'       => 'object',
			'properties' => array(
				'reply_id'            => array(
					'description' => __( 'ID of the user reply comment.', 'ai-feedback' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'status'              => array(
					'description' => __( 'Processing state of the AI reply.', 'ai-feedback' ),
					'type'        => 'string',
					'enum'        => array( 'unknown', 'pending', 'complete', 'failed' ),
					'readonly'    => true,
				),
				'ai_reply_comment_id' => array(
					'description' => __( 'ID of the generated AI reply comment. Present when complete.', 'ai-feedback' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'error'               => array(
					'description' => __( 'Error message. Present when failed.', 'ai-feedback' ),
					'type'        => 'string',
					'readonly'    => true,
				),
			),
		);
	}

	/**
	 * Read the reply ID from the request.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return int
	 */
	private function get_reply_id( WP_REST_Request $request ): int {
		$id = $request->get_param( 'id' );
		return is_numeric( $id ) ? absint( $id ) : 0;
	}

	/**
	 * Load the reply comment for the request.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return \WP_Comment|null
	 */
	private function get_reply_comment( WP_REST_Request $request ): ?\WP_Comment {
		$id = $this->get_reply_id( $request );
		if ( $id <= 0 ) {
			return null;
		}
		$comment = get_comment( $id );
		return $comment instanceof \WP_Comment ? $comment : null;
	}

	/**
	 * Read a string comment meta value.
	 *
	 * @param  int    $comment_id Comment ID.
	 * @param  string $key        Meta key.
	 * @return string
	 */
	private static function meta_string( int $comment_id, string $key ): string {
		$value = get_comment_meta( $comment_id, $key, true );
		return is_string( $value ) ? $value : '';
	}
}
