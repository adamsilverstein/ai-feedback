<?php
/**
 * Tests for the permission checks on the review ID note routes.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Notes_Controller;
use WP_REST_Request;

/**
 * Test cases for reading and deleting a review's notes.
 */
class NotesReviewPermissionsTest extends TestCase {

	/**
	 * Review whose notes are all on post 10.
	 */
	private const REVIEW_ID = 'aaaaaaaa-0000-4000-8000-000000000001';

	/**
	 * Review whose notes are on posts 10 and 20.
	 */
	private const SPLIT_REVIEW_ID = 'bbbbbbbb-0000-4000-8000-000000000002';

	/**
	 * Controller under test.
	 *
	 * @var Notes_Controller
	 */
	private Notes_Controller $controller;

	/**
	 * Load the fake comment API and add notes for both reviews.
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once __DIR__ . '/fixtures/fake-comments.php';
		require_once dirname( __DIR__, 2 ) . '/includes/class-notes-controller.php';

		\ai_feedback_test_reset_comments(); // @phpstan-ignore function.notFound (defined in the fixture above)

		$this->add_note( 10, self::REVIEW_ID );
		$this->add_note( 10, self::REVIEW_ID );
		$this->add_note( 10, self::SPLIT_REVIEW_ID );
		$this->add_note( 20, self::SPLIT_REVIEW_ID );

		$this->controller = new Notes_Controller();
	}

	/**
	 * Clean up capability overrides.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['test_current_user_can'] );
		\ai_feedback_test_reset_comments(); // @phpstan-ignore function.notFound (defined in the fixture)
		parent::tearDown();
	}

	/**
	 * Create an AI note in the fake store.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $review_id Review ID.
	 */
	private function add_note( int $post_id, string $review_id ): void {
		wp_insert_comment(
			array(
				'comment_post_ID' => $post_id,
				'comment_type'    => 'note',
				'comment_meta'    => array(
					'ai_feedback' => '1',
					'review_id'   => $review_id,
				),
			)
		);
	}

	/**
	 * Act as a user who can edit posts in general, but only the given posts.
	 *
	 * @param int[] $post_ids Posts the user can edit.
	 */
	private function act_as_editor_of( array $post_ids ): void {
		$GLOBALS['test_current_user_can'] = static function ( string $cap, mixed ...$args ) use ( $post_ids ): bool {
			if ( 'edit_post' === $cap ) {
				return in_array( $args[0] ?? 0, $post_ids, true );
			}
			return in_array( $cap, array( 'edit_posts', 'delete_posts' ), true );
		};
	}

	/**
	 * Build a request for a review ID.
	 *
	 * @param  string $review_id Review ID.
	 * @return WP_REST_Request
	 */
	private function request_for( string $review_id ): WP_REST_Request {
		$request = new WP_REST_Request();
		$request->set_param( 'review_id', $review_id );
		return $request;
	}

	/**
	 * Run one of the two permission checks.
	 *
	 * @param  string $check     Permission callback name.
	 * @param  string $review_id Review ID.
	 * @return bool|\WP_Error
	 */
	private function check( string $check, string $review_id ): bool|\WP_Error {
		$request = $this->request_for( $review_id );

		return 'delete_notes_permissions_check' === $check
			? $this->controller->delete_notes_permissions_check( $request )
			: $this->controller->get_review_notes_permissions_check( $request );
	}

	/**
	 * Assert a check refused the request.
	 *
	 * @param bool|\WP_Error $result Permission check result.
	 */
	private function assert_forbidden( bool|\WP_Error $result ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( array( 'status' => 403 ), $result->get_error_data() );
	}

	/**
	 * The permission callbacks of the two review ID routes.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_permission_checks(): array {
		return array(
			'read'   => array( 'get_review_notes_permissions_check' ),
			'delete' => array( 'delete_notes_permissions_check' ),
		);
	}

	/**
	 * A user who can edit posts, but not the review's post, is refused.
	 *
	 * @dataProvider data_permission_checks
	 *
	 * @param string $check Permission callback name.
	 */
	public function test_refuses_user_who_cannot_edit_the_post( string $check ): void {
		$this->act_as_editor_of( array( 99 ) );

		$this->assert_forbidden( $this->check( $check, self::REVIEW_ID ) );
	}

	/**
	 * A user who can edit the review's post is allowed.
	 *
	 * @dataProvider data_permission_checks
	 *
	 * @param string $check Permission callback name.
	 */
	public function test_allows_user_who_can_edit_the_post( string $check ): void {
		$this->act_as_editor_of( array( 10 ) );

		$this->assertTrue( $this->check( $check, self::REVIEW_ID ) );
	}

	/**
	 * A review with notes on several posts needs edit access to all of them.
	 *
	 * @dataProvider data_permission_checks
	 *
	 * @param string $check Permission callback name.
	 */
	public function test_requires_every_post_of_a_review( string $check ): void {
		$this->act_as_editor_of( array( 10 ) );
		$this->assert_forbidden( $this->check( $check, self::SPLIT_REVIEW_ID ) );

		$this->act_as_editor_of( array( 10, 20 ) );
		$this->assertTrue( $this->check( $check, self::SPLIT_REVIEW_ID ) );
	}

	/**
	 * An unknown review ID is refused like one the user has no access to,
	 * even for a user who can edit every post.
	 *
	 * @dataProvider data_permission_checks
	 *
	 * @param string $check Permission callback name.
	 */
	public function test_refuses_unknown_review( string $check ): void {
		$GLOBALS['test_current_user_can'] = true;

		$this->assert_forbidden( $this->check( $check, 'cccccccc-0000-4000-8000-000000000003' ) );
	}

	/**
	 * The post routes refuse a user who cannot edit the post, and a post ID of 0.
	 */
	public function test_post_routes_require_edit_post(): void {
		$this->act_as_editor_of( array( 10 ) );

		$request = new WP_REST_Request();
		$request->set_param( 'post_id', 10 );
		$this->assertTrue( $this->controller->get_notes_permissions_check( $request ) );

		$request->set_param( 'post_id', 20 );
		$this->assert_forbidden( $this->controller->get_notes_permissions_check( $request ) );

		$request->set_param( 'post_id', 0 );
		$this->assert_forbidden( $this->controller->get_notes_permissions_check( $request ) );
	}

	/**
	 * Reading a review returns only that review's notes.
	 */
	public function test_get_review_notes_returns_the_reviews_notes(): void {
		$response = $this->controller->get_review_notes( $this->request_for( self::REVIEW_ID ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 2, $data['total'] );
		$this->assertSame( self::REVIEW_ID, $data['review_id'] );
	}

	/**
	 * Deleting a review removes only that review's notes.
	 */
	public function test_delete_review_notes_deletes_the_reviews_notes(): void {
		$response = $this->controller->delete_review_notes( $this->request_for( self::REVIEW_ID ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 2, $data['deleted'] );

		$this->assertCount( 2, get_comments( array( 'type' => 'note' ) ) );
	}
}
