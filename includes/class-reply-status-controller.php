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

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_threads' ),
					'permission_callback' => array( $this, 'get_threads_permissions_check' ),
					'args'                => array(
						'post' => array(
							'description'       => __( 'ID of the post whose AI Feedback reply threads to list.', 'ai-feedback' ),
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
	 * Permission check for a post's reply threads.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return bool|WP_Error
	 */
	public function get_threads_permissions_check( WP_REST_Request $request ): bool|WP_Error {
		$post_id = $this->get_int_param( $request, 'post' );
		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view these conversations.', 'ai-feedback' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * GET /replies?post={post_id}
	 *
	 * Lists the replies under each AI Feedback note in a post, oldest first,
	 * as plain text, plus the newest user reply in each thread that is still
	 * waiting for an AI response. One request covers the whole sidebar.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_threads( WP_REST_Request $request ): WP_REST_Response {
		$post_id  = $this->get_int_param( $request, 'post' );
		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'type'    => 'note',
				'status'  => 'all',
				'orderby' => array(
					'comment_date' => 'ASC',
					'comment_ID'   => 'ASC',
				),
			)
		);

		$ai_note_ids = array();
		$replies     = array();
		foreach ( is_array( $comments ) ? $comments : array() as $comment ) {
			if ( ! is_object( $comment ) ) {
				continue;
			}
			$id = (int) $comment->comment_ID;
			if ( get_comment_meta( $id, 'ai_feedback', true ) && 0 === (int) $comment->comment_parent ) {
				$ai_note_ids[ $id ] = true;
			}
			if ( (int) $comment->comment_parent > 0 ) {
				$replies[] = $comment;
			}
		}

		$threads = array();
		foreach ( $replies as $comment ) {
			$parent_id = (int) $comment->comment_parent;
			if ( ! isset( $ai_note_ids[ $parent_id ] ) ) {
				continue;
			}

			$id    = (int) $comment->comment_ID;
			$is_ai = (bool) get_comment_meta( $id, 'ai_feedback', true );

			$threads[ $parent_id ]['replies'][] = array(
				'id'      => $id,
				'author'  => $comment->comment_author,
				'content' => trim( wp_strip_all_tags( $comment->comment_content ) ),
				'is_ai'   => $is_ai,
				'date'    => $comment->comment_date,
			);

			if ( ! $is_ai ) {
				// Only the newest user reply matters: an older pending one has been superseded.
				$status = self::meta_string( $id, Reply_Cron_Dispatcher::STATUS_META_KEY );

				$threads[ $parent_id ]['pending_reply_id'] = 'pending' === $status ? $id : null;
			}
		}

		foreach ( $threads as $parent_id => $thread ) {
			$threads[ $parent_id ]['pending_reply_id'] = $thread['pending_reply_id'] ?? null;
		}

		return new WP_REST_Response(
			array(
				'post'    => $post_id,
				// Cast so an empty result encodes as {} rather than [].
				'threads' => (object) $threads,
			),
			200
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
		return $this->get_int_param( $request, 'id' );
	}

	/**
	 * Read a positive integer parameter from the request.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @param  string          $name    Parameter name.
	 * @return int The value, or 0 when missing or invalid.
	 */
	private function get_int_param( WP_REST_Request $request, string $name ): int {
		$value = $request->get_param( $name );
		return is_numeric( $value ) ? absint( $value ) : 0;
	}

	/**
	 * Load the reply comment for the request.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return \WP_Comment|null
	 */
	private function get_reply_comment( WP_REST_Request $request ): ?\WP_Comment {
		$id = $this->get_int_param( $request, 'id' );
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
