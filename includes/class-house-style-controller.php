<?php
/**
 * House Style REST API Controller
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
 * Lists, adds, edits and removes house style rules, records what the
 * author did with a rule an AI reply proposed, and suggests rules from
 * published posts.
 *
 * Anyone who can edit posts can read the site rules, which shape their
 * reviews, and manage their own. Changing site rules needs `manage_options`,
 * and so does asking for suggested site rules.
 */
class House_Style_Controller extends WP_REST_Controller {

	/**
	 * Route namespace.
	 */
	private const ROUTE_NAMESPACE = 'ai-feedback/v1';

	/**
	 * Namespace.
	 *
	 * @var string
	 */
	protected $namespace = self::ROUTE_NAMESPACE;

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'house-style';

	/**
	 * Review service, for the AI call and hourly limit suggestions share
	 * with reviews.
	 *
	 * @var Review_Service|null
	 */
	private ?Review_Service $review_service;

	/**
	 * Constructor.
	 *
	 * @param Review_Service|null $review_service Review service. Created when needed by default.
	 */
	public function __construct( ?Review_Service $review_service = null ) {
		$this->review_service = $review_service;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$scope = array(
			'description' => __( 'Whether the rule is the current user\'s own or applies to the whole site.', 'fresh-eyes' ),
			'type'        => 'string',
			'enum'        => array( House_Style::SCOPE_USER, House_Style::SCOPE_SITE ),
			'default'     => House_Style::SCOPE_USER,
		);
		$text  = array(
			'description' => __( 'The rule, as plain text.', 'fresh-eyes' ),
			'type'        => 'string',
			'required'    => true,
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_rules' ),
					'permission_callback' => array( $this, 'get_rules_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_rule' ),
					'permission_callback' => array( $this, 'edit_rules_permissions_check' ),
					'args'                => array(
						'scope'   => $scope,
						'text'    => $text,
						'note_id' => array(
							'description'       => __( 'AI reply that proposed the rule, marked as saved.', 'fresh-eyes' ),
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/' . $this->rest_base . '/(?P<id>[a-z0-9-]{1,64})',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_rule' ),
					'permission_callback' => array( $this, 'edit_rules_permissions_check' ),
					'args'                => array(
						'scope' => $scope,
						'text'  => $text,
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_rule' ),
					'permission_callback' => array( $this, 'edit_rules_permissions_check' ),
					'args'                => array(
						'scope' => $scope,
					),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/' . $this->rest_base . '/suggestions/posts',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'suggest_rules' ),
					'permission_callback' => array( $this, 'edit_rules_permissions_check' ),
					'args'                => array(
						'scope' => $scope,
						'model' => array(
							'description'       => __( 'AI model to ask, as for a review.', 'fresh-eyes' ),
							'type'              => 'string',
							'default'           => AI_FEEDBACK_DEFAULT_MODEL,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/' . $this->rest_base . '/proposals/(?P<note_id>\d+)/dismiss',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'dismiss_proposal' ),
					'permission_callback' => array( $this, 'proposal_permissions_check' ),
					'args'                => array(
						'note_id' => array(
							'description'       => __( 'AI reply that proposed the rule.', 'fresh-eyes' ),
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
	 * Permission check for reading rules.
	 *
	 * @return bool|WP_Error
	 */
	public function get_rules_permissions_check(): bool|WP_Error {
		return current_user_can( 'edit_posts' ) ? true : self::forbidden( __( 'You do not have permission to view these rules.', 'fresh-eyes' ) );
	}

	/**
	 * Permission check for adding, editing and removing rules.
	 *
	 * Site rules need `manage_options`. A rule saved from a proposal also
	 * needs access to the post the proposal is on.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return bool|WP_Error
	 */
	public function edit_rules_permissions_check( WP_REST_Request $request ): bool|WP_Error {
		$capability = House_Style::SCOPE_SITE === $request->get_param( 'scope' ) ? 'manage_options' : 'edit_posts';
		if ( ! current_user_can( $capability ) ) {
			return self::forbidden();
		}

		if ( self::int_param( $request, 'note_id' ) > 0 ) {
			return $this->proposal_permissions_check( $request );
		}

		return true;
	}

	/**
	 * Permission check for acting on a proposed rule: the note must be an AI
	 * reply with a proposal, on a post the user can edit.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return bool|WP_Error
	 */
	public function proposal_permissions_check( WP_REST_Request $request ): bool|WP_Error {
		$note_id = self::int_param( $request, 'note_id' );
		$note    = $note_id > 0 ? get_comment( $note_id ) : null;
		if ( ! is_object( $note ) || 'note' !== $note->comment_type || null === House_Style::get_proposal( $note_id ) ) {
			return new WP_Error(
				'rest_comment_invalid_id',
				__( 'Invalid note ID.', 'fresh-eyes' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_post', (int) $note->comment_post_ID ) ) {
			return self::forbidden();
		}

		return true;
	}

	/**
	 * GET /house-style
	 *
	 * @return WP_REST_Response
	 */
	public function get_rules(): WP_REST_Response {
		return new WP_REST_Response( self::get_state(), 200 );
	}

	/**
	 * POST /house-style
	 *
	 * @param  WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_rule( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$scope    = self::scope( $request );
		$existing = array_column( House_Style::get_rules( $scope ), 'id' );
		$rule     = House_Style::add_rule( $scope, $request->get_param( 'text' ) );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}

		$note_id = self::int_param( $request, 'note_id' );
		if ( $note_id > 0 ) {
			House_Style::set_proposal_status( $note_id, 'saved' );
		}

		// 200 when the list already had the rule.
		return new WP_REST_Response( self::get_state( $rule ), in_array( $rule['id'], $existing, true ) ? 200 : 201 );
	}

	/**
	 * PUT /house-style/{id}
	 *
	 * @param  WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_rule( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rule = House_Style::update_rule( self::scope( $request ), self::string_param( $request, 'id' ), $request->get_param( 'text' ) );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}

		return new WP_REST_Response( self::get_state( $rule ), 200 );
	}

	/**
	 * DELETE /house-style/{id}
	 *
	 * @param  WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_rule( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$deleted = House_Style::delete_rule( self::scope( $request ), self::string_param( $request, 'id' ) );
		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		return new WP_REST_Response( self::get_state(), 200 );
	}

	/**
	 * POST /house-style/proposals/{note_id}/dismiss
	 *
	 * @param  WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function dismiss_proposal( WP_REST_Request $request ): WP_REST_Response {
		$note_id = self::int_param( $request, 'note_id' );
		House_Style::set_proposal_status( $note_id, 'dismissed' );

		return new WP_REST_Response(
			array(
				'note_id'  => $note_id,
				'proposal' => House_Style::get_proposal( $note_id ),
			),
			200
		);
	}

	/**
	 * POST /house-style/suggestions/posts
	 *
	 * Proposes rules from recent published posts without saving any. Uses
	 * one request of the hourly limit, like a review, given back when the
	 * AI service is unavailable.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function suggest_rules( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$scope   = self::scope( $request );
		$user_id = get_current_user_id();
		$service = $this->get_review_service();

		// Before the limit, so a site with nothing to read doesn't use a request.
		$posts = House_Style_Suggestions::get_sample_posts( $scope, $user_id );
		if ( array() === $posts ) {
			return new WP_Error(
				'house_style_no_posts',
				House_Style::SCOPE_SITE === $scope
					? __( 'This site has no published posts to suggest rules from yet.', 'fresh-eyes' )
					: __( 'You have no published posts to suggest rules from yet.', 'fresh-eyes' ),
				array( 'status' => 400 )
			);
		}

		$allowed = $service->check_rate_limit( $user_id );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$model = self::string_param( $request, 'model' );
		$rules = $service->suggest_house_rules( $posts, '' !== $model ? $model : AI_FEEDBACK_DEFAULT_MODEL );
		if ( is_wp_error( $rules ) ) {
			// An outage is not the user's fault, so a retry should not cost them.
			if ( 'ai_service_unavailable' === $rules->get_error_code() ) {
				$service->refund_rate_limit( $user_id );
			}
			return $rules;
		}

		return new WP_REST_Response(
			array(
				'suggestions' => $rules,
				'scope'       => $scope,
				'post_count'  => count( $posts ),
			),
			200
		);
	}

	/**
	 * Get the review service.
	 *
	 * @return Review_Service
	 */
	private function get_review_service(): Review_Service {
		if ( null === $this->review_service ) {
			$this->review_service = new Review_Service();
		}
		return $this->review_service;
	}

	/**
	 * The rules the current user sees, the limits and what they may change.
	 *
	 * @param  array{id: string, text: string}|null $rule Rule just added or changed.
	 * @return array<string, mixed>
	 */
	private static function get_state( ?array $rule = null ): array {
		$state = array(
			'site'            => House_Style::get_rules( House_Style::SCOPE_SITE ),
			'user'            => House_Style::get_rules( House_Style::SCOPE_USER ),
			'can_manage_site' => current_user_can( 'manage_options' ),
			'max_rules'       => House_Style::MAX_RULES,
			'max_length'      => House_Style::MAX_RULE_LENGTH,
		);
		if ( null !== $rule ) {
			$state['rule'] = $rule;
		}
		return $state;
	}

	/**
	 * Read the request's scope.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return string
	 */
	private static function scope( WP_REST_Request $request ): string {
		return House_Style::SCOPE_SITE === $request->get_param( 'scope' ) ? House_Style::SCOPE_SITE : House_Style::SCOPE_USER;
	}

	/**
	 * Read an integer parameter.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @param  string          $key     Parameter name.
	 * @return int
	 */
	private static function int_param( WP_REST_Request $request, string $key ): int {
		$value = $request->get_param( $key );
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Read a string parameter.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @param  string          $key     Parameter name.
	 * @return string
	 */
	private static function string_param( WP_REST_Request $request, string $key ): string {
		$value = $request->get_param( $key );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Error for a user who may not make the request.
	 *
	 * @param  string $message Message. Defaults to one about changing rules.
	 * @return WP_Error
	 */
	private static function forbidden( string $message = '' ): WP_Error {
		return new WP_Error(
			'rest_forbidden',
			'' !== $message ? $message : __( 'You do not have permission to change these rules.', 'fresh-eyes' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}
}
