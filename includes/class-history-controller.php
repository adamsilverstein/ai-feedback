<?php
/**
 * Review History REST API Controller
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
 * Review History REST API controller.
 */
class History_Controller extends WP_REST_Controller {

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
	protected $rest_base = 'history';

	/**
	 * Notes manager instance.
	 *
	 * @var Notes_Manager
	 */
	private Notes_Manager $notes_manager;

	/**
	 * Constructor.
	 *
	 * @param Notes_Manager|null $notes_manager Optional notes manager, for tests.
	 */
	public function __construct( ?Notes_Manager $notes_manager = null ) {
		$this->notes_manager = $notes_manager ?? new Notes_Manager();
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes(): void {
		$post_id_arg = array(
			'required'          => true,
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/post/(?P<post_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_history' ),
					'permission_callback' => array( $this, 'get_history_permissions_check' ),
					'args'                => array(
						'post_id' => $post_id_arg,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/post/(?P<post_id>\d+)/review/(?P<review_id>[a-f0-9\-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_history_review' ),
					'permission_callback' => array( $this, 'get_history_permissions_check' ),
					'args'                => array(
						'post_id'   => $post_id_arg,
						'review_id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * List the review history for a post, newest first.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_history( WP_REST_Request $request ): WP_REST_Response {
		$post_id = $this->get_post_id( $request );
		$history = array_reverse( Review_History::get( $post_id ) );

		return new WP_REST_Response(
			array(
				'history' => $history,
				'total'   => count( $history ),
			)
		);
	}

	/**
	 * Get one past review with its notes.
	 *
	 * Notes deleted since the review ran are simply absent; the stored
	 * summary still reflects the review as it was.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_history_review( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id   = $this->get_post_id( $request );
		$review_id = $request->get_param( 'review_id' );
		$review_id = is_string( $review_id ) ? $review_id : '';

		$entry = Review_History::find( $post_id, $review_id );
		if ( null === $entry ) {
			return new WP_Error(
				'review_not_found',
				__( 'Review not found in this post\'s history.', 'ai-feedback' ),
				array( 'status' => 404 )
			);
		}

		$notes = array_values(
			array_filter(
				$this->notes_manager->get_notes_by_review( $review_id ),
				static fn( mixed $note ): bool => is_array( $note ) && ( $note['post'] ?? 0 ) === $post_id
			)
		);

		$entry['post_id'] = $post_id;
		$entry['notes']   = $notes;

		return new WP_REST_Response( $entry );
	}

	/**
	 * Read the post ID route parameter.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @return int Post ID, or 0 when missing.
	 */
	private function get_post_id( WP_REST_Request $request ): int {
		$post_id = $request->get_param( 'post_id' );

		return is_numeric( $post_id ) ? (int) $post_id : 0;
	}

	/**
	 * Only users who can edit the post may read its review history.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function get_history_permissions_check( WP_REST_Request $request ): bool|WP_Error {
		$post_id = $this->get_post_id( $request );

		if ( $post_id && ! get_post( $post_id ) ) {
			return new WP_Error(
				'rest_post_invalid_id',
				__( 'Invalid post ID.', 'ai-feedback' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view the review history for this post.', 'ai-feedback' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}
}
