<?php
/**
 * Review REST API Controller
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use AI_Feedback\Logger;

/**
 * Review REST API controller.
 */
class Review_Controller extends WP_REST_Controller {

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
	protected $rest_base = 'review';

	/**
	 * Review service instance.
	 *
	 * @var Review_Service
	 */
	private Review_Service $review_service;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->review_service = new Review_Service();
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_review' ),
					'permission_callback' => array( $this, 'create_review_permissions_check' ),
					'args'                => $this->get_create_review_args(),
				),
			)
		);
	}

	/**
	 * Create a review.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_review( WP_REST_Request $request ) {
		// Get parameters.
		$post_id     = $request->get_param( 'post_id' );
		$content     = $request->get_param( 'content' );
		$title       = $request->get_param( 'title' );
		$model       = $request->get_param( 'model' );
		$focus_areas = $request->get_param( 'focus_areas' );
		$target_tone = $request->get_param( 'target_tone' );

		// Debug logging.
		Logger::debug( sprintf( 'Review request received for post %d', $post_id ) );
		Logger::debug( sprintf( 'Content provided: %s (%d characters)', $content ? 'yes' : 'no', strlen( $content ?? '' ) ) );

		// Validate post exists and user can edit it.
		$post = get_post( $post_id );
		if ( ! $post ) {
			Logger::debug( sprintf( 'Error: Post %d not found', $post_id ) );
			return new WP_Error(
				'invalid_post',
				__( 'Post not found.', 'ai-feedback' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			Logger::debug( sprintf( 'Error: User lacks permission to edit post %d', $post_id ) );
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to review this post.', 'ai-feedback' ),
				array( 'status' => 403 )
			);
		}

		// Check rate limit.
		$rate_limit_check = $this->review_service->check_rate_limit( get_current_user_id() );
		if ( is_wp_error( $rate_limit_check ) ) {
			Logger::debug( sprintf( 'Error: Rate limit exceeded for user %d', get_current_user_id() ) );
			return $rate_limit_check;
		}

		// Prepare options.
		$options = array(
			'model'       => $model,
			'focus_areas' => $focus_areas,
			'target_tone' => $target_tone,
			'content'     => $content,
			'post_title'  => $title,
		);

		// Perform review.
		$result = $this->review_service->review_document( $post_id, $options );

		if ( is_wp_error( $result ) ) {
			Logger::debug( sprintf( 'Error: Review failed - %s', $result->get_error_message() ) );
			return $result;
		}

		// Debug logging for successful response.
		$note_count = isset( $result['notes'] ) ? count( $result['notes'] ) : 0;
		Logger::debug( sprintf( 'Review completed successfully, returning %d feedback items', $note_count ) );

		// Return response.
		return rest_ensure_response( $result );
	}

	/**
	 * Check permissions for creating a review.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function create_review_permissions_check( WP_REST_Request $request ) {
		// User must be able to edit posts.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to review posts.', 'ai-feedback' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Get create review arguments.
	 *
	 * @return array
	 */
	private function get_create_review_args(): array {
		return array(
			'post_id'     => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param > 0;
				},
			),
			'content'     => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'wp_kses_post',
				'description'       => __( 'Post content from the editor (includes unsaved changes).', 'ai-feedback' ),
			),
			'title'       => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => __( 'Post title from the editor (includes unsaved changes).', 'ai-feedback' ),
			),
			'model'       => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => 'claude-sonnet-4-20250514',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'focus_areas' => array(
				'required' => false,
				'type'     => 'array',
				'default'  => array( 'content', 'tone', 'flow' ),
				'items'    => array(
					'type' => 'string',
					'enum' => array( 'content', 'tone', 'flow', 'design' ),
				),
			),
			'target_tone' => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => 'professional',
				'sanitize_callback' => 'sanitize_text_field',
				'enum'              => array( 'professional', 'casual', 'academic', 'friendly' ),
			),
		);
	}
}
