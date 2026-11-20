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
	 * Longest editor content accepted with a request to answer a reply.
	 */
	public const MAX_EDITOR_CONTENT_LENGTH = 1000000;

	/**
	 * Most characters of a thread's opening note listed for a mention thread.
	 */
	public const ROOT_EXCERPT_LENGTH = 300;

	/**
	 * Reply service that answers replies for the process route.
	 *
	 * @var Reply_Service
	 */
	private Reply_Service $reply_service;

	/**
	 * Constructor.
	 *
	 * @param Reply_Service|null $reply_service Optional reply service (for testing).
	 */
	public function __construct( ?Reply_Service $reply_service = null ) {
		$this->reply_service = $reply_service ?? new Reply_Service();
	}

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
							'description'       => __( 'ID of the user reply comment.', 'fresh-eyes' ),
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
			'/' . $this->rest_base . '/(?P<id>\d+)/process',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'process_reply' ),
					'permission_callback' => array( $this, 'process_reply_permissions_check' ),
					'args'                => array(
						'id'      => array(
							'description'       => __( 'ID of the user reply comment.', 'fresh-eyes' ),
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'retry'   => array(
							'description' => __( 'Whether to retry a failed or stalled reply.', 'fresh-eyes' ),
							'type'        => 'boolean',
							'default'     => false,
						),
						'content' => array(
							'description' => __( 'The editor\'s post content, used to find the block of a note that mentions Fresh Eyes before the post is saved.', 'fresh-eyes' ),
							'type'        => 'string',
							'default'     => '',
							'maxLength'   => self::MAX_EDITOR_CONTENT_LENGTH,
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
							'description'       => __( 'ID of the post whose Fresh Eyes reply threads to list.', 'fresh-eyes' ),
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
				__( 'You do not have permission to view these conversations.', 'fresh-eyes' ),
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
	 * waiting for an AI response. An AI reply that proposes a change to the
	 * note's block carries it as `edit`. One request covers the whole sidebar.
	 *
	 * A thread a person started is listed once someone in it mentions Fresh
	 * Eyes, flagged `is_mention` with an excerpt of its opening note as
	 * `root`. Its opening note can itself be the one waiting for an answer,
	 * and only notes that asked Fresh Eyes count as waiting.
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
		$mention_ids = array();
		$roots       = array();
		$replies     = array();
		foreach ( $comments as $comment ) {
			$id     = (int) $comment->comment_ID;
			$parent = (int) $comment->comment_parent;
			if ( 0 === $parent ) {
				$roots[ $id ] = $comment;
				if ( get_comment_meta( $id, 'ai_feedback', true ) ) {
					$ai_note_ids[ $id ] = true;
				}
			} else {
				$replies[] = $comment;
			}
			if ( get_comment_meta( $id, Mentions::META_KEY, true ) ) {
				// A thread a person started, in which someone asked Fresh Eyes.
				$mention_ids[ 0 === $parent ? $id : $parent ] = true;
			}
		}

		$threads   = array();
		$revisions = array();
		foreach ( array_keys( $mention_ids ) as $root_id ) {
			$root = $roots[ $root_id ] ?? null;
			if ( null === $root || isset( $ai_note_ids[ $root_id ] ) ) {
				continue;
			}
			$threads[ $root_id ] = array(
				'replies'          => array(),
				'is_mention'       => true,
				'root'             => array(
					'id'      => $root_id,
					'author'  => $root->comment_author,
					'content' => mb_substr( trim( wp_strip_all_tags( $root->comment_content ) ), 0, self::ROOT_EXCERPT_LENGTH ),
				),
				'pending_reply_id' => self::is_pending( self::meta_string( $root_id, Reply_Cron_Dispatcher::STATUS_META_KEY ) ) ? $root_id : null,
			);
		}

		foreach ( $replies as $comment ) {
			$parent_id = (int) $comment->comment_parent;
			if ( ! isset( $ai_note_ids[ $parent_id ] ) && ! isset( $threads[ $parent_id ] ) ) {
				continue;
			}

			$id       = (int) $comment->comment_ID;
			$is_ai    = (bool) get_comment_meta( $id, 'ai_feedback', true );
			$edit     = $is_ai ? Notes_Manager::decode_reply_edit( get_comment_meta( $id, Notes_Manager::REPLY_EDIT_META_KEY, true ) ) : null;
			$revision = null;
			if ( null !== $edit ) {
				// Edits saved before revisions were stored are counted in
				// order, with the review's quick fix as the first.
				$revisions[ $parent_id ] = ( $revisions[ $parent_id ] ?? ( self::has_quick_fix( $parent_id ) ? 1 : 0 ) ) + 1;

				$stored   = get_comment_meta( $id, Notes_Manager::REVISION_META_KEY, true );
				$revision = is_numeric( $stored ) && (int) $stored > 0 ? (int) $stored : $revisions[ $parent_id ];
			}

			$threads[ $parent_id ]['replies'][] = array(
				'id'       => $id,
				'author'   => $comment->comment_author,
				'content'  => trim( wp_strip_all_tags( $comment->comment_content ) ),
				'is_ai'    => $is_ai,
				'date'     => $comment->comment_date,
				'edit'     => $edit,
				'revision' => $revision,
			);

			if ( $is_ai ) {
				continue;
			}

			$status = self::meta_string( $id, Reply_Cron_Dispatcher::STATUS_META_KEY );
			if ( ! empty( $threads[ $parent_id ]['is_mention'] ) && '' === $status ) {
				// In a thread a person started, a note that does not ask
				// Fresh Eyes leaves the pending question as it is.
				continue;
			}

			// Only the newest user reply matters: an older pending one has been superseded.
			$threads[ $parent_id ]['pending_reply_id'] = self::is_pending( $status ) ? $id : null;
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
	 * Whether a note carries a review quick fix, structural edit or
	 * formatting fix, which is the first revision of its thread's edit.
	 *
	 * @param int $note_id Note ID.
	 * @return bool
	 */
	private static function has_quick_fix( int $note_id ): bool {
		return null !== ( new Notes_Manager() )->decode_quick_fix( get_comment_meta( $note_id, 'feedback_quick_fix', true ) )
			|| null !== Notes_Manager::decode_block_action( get_comment_meta( $note_id, Notes_Manager::BLOCK_ACTION_META_KEY, true ) )
			|| null !== Block_Change::decode( get_comment_meta( $note_id, Notes_Manager::BLOCK_CHANGE_META_KEY, true ) );
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
				__( 'Invalid reply ID.', 'fresh-eyes' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_post', (int) $comment->comment_post_ID ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access reply status.', 'fresh-eyes' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Permission check for answering a reply. Same rules as reading its status.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return bool|WP_Error
	 */
	public function process_reply_permissions_check( WP_REST_Request $request ): bool|WP_Error {
		return $this->get_status_permissions_check( $request );
	}

	/**
	 * POST /replies/{id}/process
	 *
	 * Generates the AI answer to a queued reply within this request, so the
	 * editor does not depend on WP-Cron. Safe to call more than once: a reply
	 * that is already answered or being answered is left alone. Responds
	 * with the reply status, like GET /replies/{id}/status.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function process_reply( WP_REST_Request $request ): WP_REST_Response {
		$comment_id = $this->get_reply_id( $request );

		// Finish the answer even if the editor navigates away mid-request.
		ignore_user_abort( true );
		self::raise_time_limit( Reply_Service::REQUEST_TIME_LIMIT );
		// Serialized blocks, kept raw: the service only parses them for a
		// note that mentions Fresh Eyes, as prompt input, and never saves them.
		$content = $request->get_param( 'content' );
		$this->reply_service->process(
			$comment_id,
			true === $request->get_param( 'retry' ),
			is_string( $content ) ? $content : '',
			get_current_user_id()
		);

		return new WP_REST_Response( $this->build_status( $comment_id ), 200 );
	}

	/**
	 * Raise the PHP time limit so a slow AI call is not cut off mid-answer.
	 *
	 * Never lowers an existing limit, and leaves an unlimited one alone.
	 *
	 * @param int $seconds Minimum time limit in seconds.
	 */
	private static function raise_time_limit( int $seconds ): void {
		$current = (int) ini_get( 'max_execution_time' );
		if ( 0 === $current || $current >= $seconds || ! function_exists( 'set_time_limit' ) ) {
			return;
		}

		set_time_limit( $seconds );
	}

	/**
	 * GET /replies/{id}/status
	 *
	 * @param  WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_status( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->build_status( $this->get_reply_id( $request ) ), 200 );
	}

	/**
	 * Build the status payload for a reply.
	 *
	 * A reply being answered ('processing') is reported as 'pending'. Note the
	 * payload uses `ai_reply_comment_id`, while the backing meta key is
	 * Reply_Cron_Dispatcher::REPLY_COMMENT_ID_META_KEY.
	 *
	 * @param int $comment_id Reply comment ID.
	 * @return array<string, mixed>
	 */
	private function build_status( int $comment_id ): array {
		$status  = self::meta_string( $comment_id, Reply_Cron_Dispatcher::STATUS_META_KEY );
		$pending = self::is_pending( $status );
		$payload = array(
			'reply_id' => $comment_id,
			'status'   => match ( true ) {
				$pending                => 'pending',
				'complete' === $status,
				'failed' === $status    => $status,
				default                 => 'unknown',
			},
		);

		if ( $pending ) {
			$since = get_comment_meta( $comment_id, Reply_Cron_Dispatcher::PENDING_SINCE_META_KEY, true );
			if ( is_numeric( $since ) && time() - (int) $since > Reply_Cron_Dispatcher::PENDING_TIMEOUT ) {
				// WP-Cron never ran the job, so stop the editor from waiting forever.
				$payload['status'] = 'failed';
				$payload['error']  = __( 'The AI reply timed out.', 'fresh-eyes' );
			}
		}

		if ( 'complete' === $payload['status'] ) {
			$ai_reply_id = get_comment_meta( $comment_id, Reply_Cron_Dispatcher::REPLY_COMMENT_ID_META_KEY, true );
			$ai_reply_id = is_numeric( $ai_reply_id ) ? (int) $ai_reply_id : 0;

			if ( $ai_reply_id > 0 ) {
				$payload['ai_reply_comment_id'] = $ai_reply_id;
			} else {
				// Complete without a reply ID cannot be rendered; report it as a failure.
				$payload['status'] = 'failed';
				$payload['error']  = __( 'The AI reply could not be found.', 'fresh-eyes' );
			}
		}

		if ( 'failed' === $status ) {
			$error = self::meta_string( $comment_id, Reply_Cron_Dispatcher::REPLY_ERROR_META_KEY );
			if ( '' !== $error ) {
				$payload['error'] = $error;
			}

			$retry_after = self::retry_after( $comment_id );
			if ( $retry_after > 0 ) {
				$payload['retry_after'] = $retry_after;
			}
		}

		return $payload;
	}

	/**
	 * Seconds until a failed reply can be retried.
	 *
	 * @param int $comment_id Reply comment ID.
	 * @return int Seconds left in the retry cooldown, or 0 when a retry would run.
	 */
	private static function retry_after( int $comment_id ): int {
		$claimed_at = get_comment_meta( $comment_id, Reply_Cron_Dispatcher::CLAIMED_AT_META_KEY, true );
		if ( ! is_numeric( $claimed_at ) ) {
			return 0;
		}

		return max( 0, (int) $claimed_at + Reply_Cron_Dispatcher::RETRY_COOLDOWN - time() );
	}

	/**
	 * Whether a reply status means the AI answer is still on its way.
	 *
	 * @param string $status Status meta value.
	 * @return bool
	 */
	private static function is_pending( string $status ): bool {
		return 'pending' === $status || 'processing' === $status;
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
					'description' => __( 'ID of the user reply comment.', 'fresh-eyes' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'status'              => array(
					'description' => __( 'Processing state of the AI reply.', 'fresh-eyes' ),
					'type'        => 'string',
					'enum'        => array( 'unknown', 'pending', 'complete', 'failed' ),
					'readonly'    => true,
				),
				'ai_reply_comment_id' => array(
					'description' => __( 'ID of the generated AI reply comment. Present when complete.', 'fresh-eyes' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'error'               => array(
					'description' => __( 'Error message. Present when failed.', 'fresh-eyes' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'retry_after'         => array(
					'description' => __( 'Seconds until a failed reply can be retried. Present while a retry would be ignored.', 'fresh-eyes' ),
					'type'        => 'integer',
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
