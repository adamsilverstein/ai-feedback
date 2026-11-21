<?php
/**
 * Tests for the endpoint that suggests house style rules from published posts.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\House_Style;
use AI_Feedback\House_Style_Controller;
use AI_Feedback\Review_Service;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

require_once __DIR__ . '/house-style-mocks.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-house-style-suggestions.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-house-style-controller.php';

/**
 * Review service that answers suggestion requests with a set result and
 * keeps the real rate limit.
 */
class SuggestControllerReviewService extends Review_Service {

	/**
	 * Arguments of each suggestion request.
	 *
	 * @var list<array{posts: array<mixed>, model: string}>
	 */
	public array $requests = array();

	/**
	 * What the request returns.
	 *
	 * @var list<string>|WP_Error
	 */
	public array|WP_Error $result = array( 'Spell out numbers under ten' );

	/**
	 * Skip the parent constructor.
	 */
	public function __construct() {
	}

	/**
	 * Record the request and return the set result.
	 *
	 * @param  array<mixed> $posts Posts.
	 * @param  string       $model Model ID.
	 * @return list<string>|WP_Error
	 */
	public function suggest_house_rules( array $posts, string $model ): array|WP_Error {
		$this->requests[] = array(
			'posts' => $posts,
			'model' => $model,
		);
		return $this->result;
	}
}

/**
 * POST /house-style/suggestions/posts.
 */
class HouseStyleSuggestControllerTest extends TestCase {

	/**
	 * ID of the acting user.
	 */
	private const USER_ID = 7;

	/**
	 * User meta holding the hourly limit's timestamps.
	 */
	private const RATE_LIMIT_KEY = 'ai_feedback_reviews';

	/**
	 * Review service the controller calls.
	 *
	 * @var SuggestControllerReviewService
	 */
	private SuggestControllerReviewService $service;

	/**
	 * Controller under test.
	 *
	 * @var House_Style_Controller
	 */
	private House_Style_Controller $controller;

	/**
	 * Reset storage and seed two published posts.
	 */
	protected function setUp(): void {
		$this->service                   = new SuggestControllerReviewService();
		$this->controller                = new House_Style_Controller( $this->service );
		$GLOBALS['test_options']         = array();
		$GLOBALS['test_user_meta']       = array();
		$GLOBALS['test_current_user_id'] = self::USER_ID;
		$GLOBALS['test_get_posts_args']  = array();
		$GLOBALS['test_get_posts']       = array(
			self::post( 1, self::USER_ID ),
			self::post( 2, 9 ),
		);
	}

	/**
	 * Clean up globals.
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['test_options'],
			$GLOBALS['test_user_meta'],
			$GLOBALS['test_current_user_id'],
			$GLOBALS['test_current_user_can'],
			$GLOBALS['test_get_posts'],
			$GLOBALS['test_get_posts_args']
		);
	}

	/**
	 * Build a published post.
	 *
	 * @param  int $id     Post ID.
	 * @param  int $author Author ID.
	 * @return object
	 */
	private static function post( int $id, int $author ): object {
		return (object) array(
			'ID'            => $id,
			'post_author'   => (string) $author,
			'post_status'   => 'publish',
			'post_type'     => 'post',
			'post_password' => '',
			'post_title'    => 'Post ' . $id,
			'post_content'  => '<p>Body of post ' . $id . '.</p>',
		);
	}

	/**
	 * Build a request.
	 *
	 * @param  array<string, mixed> $params Parameters.
	 * @return WP_REST_Request
	 */
	private function request( array $params = array() ): WP_REST_Request {
		$request = new WP_REST_Request();
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * Timestamps counted against the user's hourly limit.
	 *
	 * @return array<mixed>
	 */
	private function slots(): array {
		$slots = get_user_meta( self::USER_ID, self::RATE_LIMIT_KEY, true );
		return is_array( $slots ) ? $slots : array();
	}

	/**
	 * Asking needs what saving a rule of that scope needs.
	 */
	public function test_asking_needs_the_capability_to_save_that_scope(): void {
		$as = static function ( array $capabilities ): void {
			$GLOBALS['test_current_user_can'] = static fn ( string $cap ): bool => in_array( $cap, $capabilities, true );
		};

		$as( array( 'read' ) );
		$this->assertInstanceOf( WP_Error::class, $this->controller->edit_rules_permissions_check( $this->request( array( 'scope' => 'user' ) ) ) );

		$as( array( 'edit_posts' ) );
		$this->assertTrue( $this->controller->edit_rules_permissions_check( $this->request( array( 'scope' => 'user' ) ) ) );
		$forbidden = $this->controller->edit_rules_permissions_check( $this->request( array( 'scope' => 'site' ) ) );
		$this->assertInstanceOf( WP_Error::class, $forbidden );
		$this->assertSame( 'rest_forbidden', $forbidden->get_error_code() );

		$as( array( 'edit_posts', 'manage_options' ) );
		$this->assertTrue( $this->controller->edit_rules_permissions_check( $this->request( array( 'scope' => 'site' ) ) ) );
	}

	/**
	 * Suggestions come back without anything being saved, and the request
	 * uses one slot of the hourly limit.
	 */
	public function test_returns_suggestions_saves_nothing_and_counts_one_request(): void {
		$response = $this->controller->suggest_rules( $this->request( array( 'model' => 'some-model' ) ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'suggestions' => array( 'Spell out numbers under ten' ),
				'scope'       => 'user',
				'post_count'  => 1,
			),
			$response->get_data()
		);
		$this->assertCount( 1, $this->slots() );
		$this->assertSame( array(), House_Style::get_rules( House_Style::SCOPE_USER ) );
		$this->assertSame( array(), House_Style::get_rules( House_Style::SCOPE_SITE ) );

		// Personal rules read only the user's own post.
		$this->assertCount( 1, $this->service->requests );
		$this->assertSame( 'some-model', $this->service->requests[0]['model'] );
		$this->assertSame( array( 'Post 1' ), array_column( $this->service->requests[0]['posts'], 'title' ) );
		$this->assertSame( array( self::USER_ID ), array_column( (array) $GLOBALS['test_get_posts_args'], 'author' ) );
	}

	/**
	 * Site rules read posts by every author.
	 */
	public function test_site_scope_reads_all_authors(): void {
		$response = $this->controller->suggest_rules( $this->request( array( 'scope' => 'site' ) ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'site', $data['scope'] );
		$this->assertSame( 2, $data['post_count'] );
		$this->assertSame( array( 'Post 1', 'Post 2' ), array_column( $this->service->requests[0]['posts'], 'title' ) );
		$this->assertSame( AI_FEEDBACK_DEFAULT_MODEL, $this->service->requests[0]['model'] );
	}

	/**
	 * A user at the hourly limit gets the limit error and no AI request.
	 */
	public function test_rate_limited_user_makes_no_request(): void {
		update_user_meta( self::USER_ID, self::RATE_LIMIT_KEY, array_fill( 0, 10, time() ) );

		$result = $this->controller->suggest_rules( $this->request() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rate_limit_exceeded', $result->get_error_code() );
		$this->assertSame( array(), $this->service->requests );
		$this->assertCount( 10, $this->slots() );
	}

	/**
	 * Without published posts there is nothing to ask about, and no slot is used.
	 */
	public function test_no_posts_uses_no_slot(): void {
		$GLOBALS['test_get_posts'] = array();

		$result = $this->controller->suggest_rules( $this->request() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'house_style_no_posts', $result->get_error_code() );
		$this->assertSame( array( 'status' => 400 ), $result->get_error_data() );
		$this->assertSame( array(), $this->service->requests );
		$this->assertSame( array(), $this->slots() );
	}

	/**
	 * An outage gives the slot back, as a failed review does.
	 */
	public function test_outage_refunds_the_slot(): void {
		$this->service->result = new WP_Error( 'ai_service_unavailable', 'Down.', array( 'status' => 503 ) );

		$result = $this->controller->suggest_rules( $this->request() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ai_service_unavailable', $result->get_error_code() );
		$this->assertSame( array(), $this->slots() );
	}

	/**
	 * Any other failure keeps the slot.
	 */
	public function test_other_failures_keep_the_slot(): void {
		$this->service->result = new WP_Error( 'invalid_rule_suggestions', 'Unreadable.' );

		$result = $this->controller->suggest_rules( $this->request() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertCount( 1, $this->slots() );
	}
}
