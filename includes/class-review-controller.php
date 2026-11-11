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
	 * Chunked review session tracker.
	 *
	 * @var Review_Chunks
	 */
	private Review_Chunks $review_chunks;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->review_service = new Review_Service();
		$this->review_chunks  = new Review_Chunks();
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
	 * @param  WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_review( WP_REST_Request $request ) {
		// Get parameters.
		$post_id           = $request->get_param( 'post_id' );
		$title             = $request->get_param( 'title' );
		$blocks            = $request->get_param( 'blocks' );
		$model             = $request->get_param( 'model' );
		$focus_areas       = $request->get_param( 'focus_areas' );
		$target_tone       = $request->get_param( 'target_tone' );
		$locale            = $request->get_param( 'locale' );
		$existing_feedback = $request->get_param( 'existing_feedback' );
		$chunk_index       = $this->int_param( $request->get_param( 'chunk_index' ) );
		$chunk_total       = max( 1, $this->int_param( $request->get_param( 'chunk_total' ) ) );
		$review_id         = $request->get_param( 'review_id' );
		$review_id         = is_string( $review_id ) ? $review_id : '';
		$user_id           = get_current_user_id();

		// Debug logging.
		Logger::debug( sprintf( 'Review request received for post %d', $post_id ) );
		Logger::debug( sprintf( 'Blocks provided: %d', is_array( $blocks ) ? count( $blocks ) : 0 ) );

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

		// Validate blocks array.
		if ( empty( $blocks ) || ! is_array( $blocks ) ) {
			Logger::debug( 'Error: No blocks provided for review' );
			return new WP_Error(
				'no_blocks',
				__( 'No content blocks provided for review.', 'ai-feedback' ),
				array( 'status' => 400 )
			);
		}

		$is_chunked = $chunk_total > 1;

		// Every part but the last must be full, so a review can't be split into
		// many tiny parts to buy extra AI calls with one rate limit slot.
		$block_count = count( $blocks );
		$chunk_size  = Review_Chunks::get_chunk_size();
		$is_last     = $chunk_index + 1 >= $chunk_total;
		if ( $is_chunked && ( $block_count > $chunk_size || ( ! $is_last && $block_count !== $chunk_size ) ) ) {
			return new WP_Error(
				'invalid_chunk_size',
				__( 'This review chunk has the wrong number of blocks.', 'ai-feedback' ),
				array( 'status' => 400 )
			);
		}

		if ( $is_chunked && $chunk_total > Review_Chunks::get_max_chunks() ) {
			return new WP_Error(
				'too_many_blocks',
				__( 'Post has too many blocks (max 100). Please split into smaller posts.', 'ai-feedback' ),
				array( 'status' => 400 )
			);
		}

		if ( $is_chunked && $chunk_index > 0 ) {
			// Follow-up chunks belong to a review that already passed the rate limit.
			// Claim the chunk before the AI call so it cannot be replayed.
			$chunk_check = $this->review_chunks->claim( $user_id, $this->int_param( $post_id ), $review_id, $chunk_index, $chunk_total );
			if ( is_wp_error( $chunk_check ) ) {
				Logger::debug( sprintf( 'Error: Invalid review chunk %d of %d', $chunk_index + 1, $chunk_total ) );
				return $chunk_check;
			}
		} else {
			if ( $chunk_index > 0 ) {
				return new WP_Error(
					'invalid_review_chunk',
					__( 'Invalid review chunk.', 'ai-feedback' ),
					array( 'status' => 400 )
				);
			}

			// Check rate limit.
			$rate_limit_check = $this->review_service->check_rate_limit( $user_id );
			if ( is_wp_error( $rate_limit_check ) ) {
				Logger::debug( sprintf( 'Error: Rate limit exceeded for user %d', $user_id ) );
				return $rate_limit_check;
			}

			$review_id = '';
			if ( $is_chunked ) {
				$started = $this->review_chunks->start( $user_id, $this->int_param( $post_id ), $chunk_total );
				if ( is_wp_error( $started ) ) {
					return $started;
				}
				$review_id = $started;
			}
		}

		// Prepare options.
		$options = array(
			'model'              => $model,
			'focus_areas'        => $focus_areas,
			'target_tone'        => $target_tone,
			'locale'             => $locale,
			'blocks'             => $blocks,
			'post_title'         => $title,
			'existing_feedback'  => is_array( $existing_feedback ) ? $existing_feedback : array(),
			'review_id'          => $review_id,
			'chunk'              => array(
				'index' => $chunk_index,
				'total' => $chunk_total,
			),
			'previous_summaries' => $this->sanitize_previous_summaries( $request->get_param( 'previous_summaries' ), $chunk_total ),
		);

		// Log continuation review status.
		if ( ! empty( $options['existing_feedback'] ) ) {
			Logger::debug( sprintf( 'Continuation review with %d existing feedback items', count( $options['existing_feedback'] ) ) );
		}

		// Perform review.
		$result = $this->review_service->review_document( $post_id, $options );

		if ( is_wp_error( $result ) ) {
			Logger::debug( sprintf( 'Error: Review failed - %s', $result->get_error_message() ) );

			// An outage is not the user's fault, so a retry should not cost them.
			// Later parts of a chunked review were never charged.
			$was_charged = ! ( $is_chunked && $chunk_index > 0 );
			if ( $was_charged && 'ai_service_unavailable' === $result->get_error_code() ) {
				$this->review_service->refund_rate_limit( get_current_user_id() );
			}

			if ( $is_chunked && $chunk_index > 0 ) {
				$this->review_chunks->release( $user_id );
			} elseif ( $is_chunked ) {
				// Without a first part there is no review to continue.
				$this->review_chunks->end( $user_id );
			}

			return $result;
		}

		if ( $is_chunked ) {
			$this->review_chunks->complete( $user_id, $chunk_index );
			$result['chunk'] = array(
				'index' => $chunk_index,
				'total' => $chunk_total,
			);
		}

		// Debug logging for successful response.
		$note_count = isset( $result['notes'] ) ? count( $result['notes'] ) : 0;
		Logger::debug( sprintf( 'Review completed successfully, returning %d feedback items', $note_count ) );

		// Return response.
		return rest_ensure_response( $result );
	}

	/**
	 * Read a validated integer request parameter.
	 *
	 * @param  mixed $value Raw parameter value.
	 * @return int Non-negative integer, or 0 when not numeric.
	 */
	private function int_param( mixed $value ): int {
		return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}

	/**
	 * Sanitize summaries of earlier chunks sent with the final chunk.
	 *
	 * @param  mixed $summaries   Raw `previous_summaries` parameter.
	 * @param  int   $chunk_total Number of chunks in the review.
	 * @return string[] At most one summary per earlier chunk, each capped in length.
	 */
	private function sanitize_previous_summaries( mixed $summaries, int $chunk_total ): array {
		if ( ! is_array( $summaries ) || $chunk_total < 2 ) {
			return array();
		}

		$clean = array();
		foreach ( array_slice( array_values( $summaries ), 0, $chunk_total - 1 ) as $summary ) {
			if ( is_string( $summary ) && '' !== trim( $summary ) ) {
				$clean[] = mb_substr( sanitize_textarea_field( $summary ), 0, 500 );
			}
		}

		return $clean;
	}

	/**
	 * Check permissions for creating a review.
	 *
	 * @param  WP_REST_Request $request Request object.
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
			'post_id'            => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param > 0;
				},
			),
			'title'              => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => __( 'Post title from the editor.', 'ai-feedback' ),
			),
			'blocks'             => array(
				'required'    => true,
				'type'        => 'array',
				'description' => __( 'Array of blocks with clientId, name, and content.', 'ai-feedback' ),
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'clientId' => array(
							'type'     => 'string',
							'required' => true,
							'format'   => 'text-field',
						),
						'name'     => array(
							'type'     => 'string',
							'required' => true,
							'format'   => 'text-field',
						),
						'content'  => array(
							'type'     => 'string',
							'required' => true,
							'format'   => 'text-field',
						),
					),
				),
			),
			'model'              => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => AI_FEEDBACK_DEFAULT_MODEL,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'focus_areas'        => array(
				'required' => false,
				'type'     => 'array',
				'default'  => array( 'content', 'tone', 'flow' ),
				'items'    => array(
					'type' => 'string',
					'enum' => array( 'content', 'tone', 'flow', 'design' ),
				),
			),
			'target_tone'        => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => 'professional',
				'sanitize_callback' => 'sanitize_text_field',
				'enum'              => array( 'professional', 'casual', 'academic', 'friendly' ),
			),
			'locale'             => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => 'auto',
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => __( 'Locale for feedback language.', 'ai-feedback' ),
			),
			'existing_feedback'  => array(
				'required'    => false,
				'type'        => 'array',
				'default'     => array(),
				'description' => __( 'Existing feedback from previous reviews for continuation.', 'ai-feedback' ),
			),
			'review_id'          => array(
				'required'    => false,
				'type'        => 'string',
				'default'     => '',
				'format'      => 'uuid',
				'description' => __( 'Review ID returned for the first chunk of a chunked review.', 'ai-feedback' ),
			),
			'chunk_index'        => array(
				'required'    => false,
				'type'        => 'integer',
				'default'     => 0,
				'minimum'     => 0,
				'description' => __( 'Zero-based index of this chunk in a chunked review.', 'ai-feedback' ),
			),
			'chunk_total'        => array(
				'required'    => false,
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
				'maximum'     => Review_Chunks::MAX_BLOCKS,
				'description' => __( 'Number of chunks in a chunked review.', 'ai-feedback' ),
			),
			'previous_summaries' => array(
				'required'    => false,
				'type'        => 'array',
				'default'     => array(),
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'Summaries of earlier chunks, sent with the final chunk.', 'ai-feedback' ),
			),
		);
	}
}
