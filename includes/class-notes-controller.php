<?php
/**
 * Notes REST API Controller
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
 * Notes REST API controller.
 */
class Notes_Controller extends WP_REST_Controller {




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
	protected $rest_base = 'notes';

	/**
	 * Notes manager instance.
	 *
	 * @var Notes_Manager
	 */
	private Notes_Manager $notes_manager;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->notes_manager = new Notes_Manager();
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes(): void {
		// Get notes for a post.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/post/(?P<post_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_post_notes' ),
					'permission_callback' => array( $this, 'get_notes_permissions_check' ),
					'args'                => array(
						'post_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'ai_only' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);

		// Get notes by review ID.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/review/(?P<review_id>[a-f0-9\-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_review_notes' ),
					'permission_callback' => array( $this, 'get_notes_permissions_check' ),
					'args'                => array(
						'review_id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// Resolve a note.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<note_id>\d+)/resolve',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'resolve_note' ),
					'permission_callback' => array( $this, 'update_note_permissions_check' ),
					'args'                => array(
						'note_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Unresolve a note.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<note_id>\d+)/unresolve',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'unresolve_note' ),
					'permission_callback' => array( $this, 'update_note_permissions_check' ),
					'args'                => array(
						'note_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Delete notes by review ID.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/review/(?P<review_id>[a-f0-9\-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_review_notes' ),
					'permission_callback' => array( $this, 'delete_notes_permissions_check' ),
					'args'                => array(
						'review_id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// Get latest review for a post.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/post/(?P<post_id>\d+)/latest-review',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_latest_review' ),
					'permission_callback' => array( $this, 'get_notes_permissions_check' ),
					'args'                => array(
						'post_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Get feedback history for continuation reviews.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/post/(?P<post_id>\d+)/feedback-history',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_feedback_history' ),
					'permission_callback' => array( $this, 'get_notes_permissions_check' ),
					'args'                => array(
						'post_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Get notes for a post.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_post_notes( WP_REST_Request $request ) {
		$post_id = $request->get_param( 'post_id' );
		$ai_only = $request->get_param( 'ai_only' );

		// Verify post exists.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error(
				'invalid_post',
				__( 'Post not found.', 'ai-feedback' ),
				array( 'status' => 404 )
			);
		}

		$notes = $this->notes_manager->get_notes_for_post( $post_id, $ai_only );

		return rest_ensure_response(
			array(
				'notes' => $notes,
				'total' => count( $notes ),
			)
		);
	}

	/**
	 * Get notes by review ID.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_review_notes( WP_REST_Request $request ) {
		$review_id = $request->get_param( 'review_id' );

		$notes = $this->notes_manager->get_notes_by_review( $review_id );

		return rest_ensure_response(
			array(
				'notes'     => $notes,
				'total'     => count( $notes ),
				'review_id' => $review_id,
			)
		);
	}

	/**
	 * Resolve a note.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function resolve_note( WP_REST_Request $request ) {
		$note_id = $request->get_param( 'note_id' );

		// Verify note exists.
		$note = get_comment( $note_id );
		if ( ! $note || 'note' !== $note->comment_type ) {
			return new WP_Error(
				'invalid_note',
				__( 'Note not found.', 'ai-feedback' ),
				array( 'status' => 404 )
			);
		}

		$success = $this->notes_manager->resolve_note( $note_id );

		if ( ! $success ) {
			return new WP_Error(
				'resolve_failed',
				__( 'Failed to resolve note.', 'ai-feedback' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success'     => true,
				'note_id'     => $note_id,
				'is_resolved' => true,
			)
		);
	}

	/**
	 * Unresolve a note.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function unresolve_note( WP_REST_Request $request ) {
		$note_id = $request->get_param( 'note_id' );

		// Verify note exists.
		$note = get_comment( $note_id );
		if ( ! $note || 'note' !== $note->comment_type ) {
			return new WP_Error(
				'invalid_note',
				__( 'Note not found.', 'ai-feedback' ),
				array( 'status' => 404 )
			);
		}

		$success = $this->notes_manager->unresolve_note( $note_id );

		if ( ! $success ) {
			return new WP_Error(
				'unresolve_failed',
				__( 'Failed to unresolve note.', 'ai-feedback' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success'     => true,
				'note_id'     => $note_id,
				'is_resolved' => false,
			)
		);
	}

	/**
	 * Delete notes by review ID.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_review_notes( WP_REST_Request $request ) {
		$review_id = $request->get_param( 'review_id' );

		$deleted_count = $this->notes_manager->delete_notes_by_review( $review_id );

		return rest_ensure_response(
			array(
				'success'   => true,
				'review_id' => $review_id,
				'deleted'   => $deleted_count,
			)
		);
	}

	/**
	 * Get the latest review for a post.
	 *
	 * Returns the most recent review data reconstructed from persisted notes.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_latest_review( WP_REST_Request $request ) {
		$post_id = $request->get_param( 'post_id' );

		// Verify post exists.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error(
				'invalid_post',
				__( 'Post not found.', 'ai-feedback' ),
				array( 'status' => 404 )
			);
		}

		$review = $this->notes_manager->get_latest_review_for_post( $post_id );

		if ( null === $review ) {
			return rest_ensure_response(
				array(
					'has_review' => false,
					'review'     => null,
				)
			);
		}

		return rest_ensure_response(
			array(
				'has_review' => true,
				'review'     => $review,
			)
		);
	}

	/**
	 * Get feedback history for continuation reviews.
	 *
	 * Returns all unresolved AI feedback notes with their user replies
	 * for inclusion in continuation review prompts.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_feedback_history( WP_REST_Request $request ) {
		$post_id = $request->get_param( 'post_id' );

		// Verify post exists.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error(
				'invalid_post',
				__( 'Post not found.', 'ai-feedback' ),
				array( 'status' => 404 )
			);
		}

		// Get unresolved notes with their replies.
		$notes = $this->notes_manager->get_notes_with_replies( $post_id, true );

		return rest_ensure_response(
			array(
				'notes'       => $notes,
				'total'       => count( $notes ),
				'has_history' => count( $notes ) > 0,
			)
		);
	}

	/**
	 * Check permissions for getting notes.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function get_notes_permissions_check( WP_REST_Request $request ) {
		// User must be able to edit posts.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view notes.', 'ai-feedback' ),
				array( 'status' => 403 )
			);
		}

		// If getting notes for a specific post, check post permissions.
		$post_id = $request->get_param( 'post_id' );
		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view notes for this post.', 'ai-feedback' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Check permissions for updating notes.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function update_note_permissions_check( WP_REST_Request $request ) {
		// User must be able to edit posts.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to update notes.', 'ai-feedback' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Check permissions for deleting notes.
	 *
	 * @param  WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function delete_notes_permissions_check( WP_REST_Request $request ) {
		// User must be able to delete posts.
		if ( ! current_user_can( 'delete_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to delete notes.', 'ai-feedback' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
