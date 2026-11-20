<?php
/**
 * Tests for the hidden Fresh Eyes user.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use AI_Feedback\Fresh_Eyes_User;
use AI_Feedback\Mentions;

require_once __DIR__ . '/fresh-eyes-user-mocks.php';

/**
 * Fresh_Eyes_User tests.
 */
class FreshEyesUserTest extends TestCase {

	/**
	 * The database object other tests use.
	 *
	 * @var mixed
	 */
	private mixed $wpdb = null;

	/**
	 * Reset state.
	 */
	protected function setUp(): void {
		parent::setUp();
		ai_feedback_test_reset_users();
		$this->wpdb       = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new \wpdb();
	}

	/**
	 * Clean up.
	 */
	protected function tearDown(): void {
		ai_feedback_test_reset_users();
		$GLOBALS['wpdb'] = $this->wpdb;
		parent::tearDown();
	}

	/**
	 * Create the user as activation does.
	 *
	 * @return int User ID.
	 */
	private function create_user(): int {
		$id = Fresh_Eyes_User::ensure();
		$this->assertGreaterThan( 0, $id );
		return $id;
	}

	/**
	 * Add a person's account.
	 *
	 * @param int    $id    User ID.
	 * @param string $login Login.
	 */
	private function add_person( int $id, string $login ): void {
		$user               = new \Test_Fresh_Eyes_WP_User();
		$user->ID           = $id;
		$user->user_login   = $login;
		$user->user_email   = $login . '@example.com';
		$user->display_name = ucfirst( $login );

		$GLOBALS['test_user_objects'][ $id ]                  = $user;
		$GLOBALS['test_user_meta'][ $id ]['wp_capabilities'] = array( 'editor' => true );
		$GLOBALS['test_logins'][]                             = $login;
	}

	/**
	 * Build a users request.
	 *
	 * @param array<string, mixed> $params Query parameters.
	 * @param string               $method Method.
	 * @param string               $route  Route.
	 * @return \WP_REST_Request
	 */
	private function users_request( array $params, string $method = 'GET', string $route = '/wp/v2/users' ): \WP_REST_Request {
		$request = new \WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	public function test_creates_a_user_with_no_role_email_or_known_password(): void {
		$id = $this->create_user();

		$data = $GLOBALS['test_inserted_users'][ $id ];
		$this->assertSame( 'fresh-eyes', $data['user_login'] );
		$this->assertSame( 'Fresh Eyes', $data['display_name'] );
		$this->assertSame( '', $data['user_email'] );
		$this->assertSame( '', $data['role'] );
		$this->assertSame( 64, strlen( $data['user_pass'] ) );
		$this->assertSame( $id, $GLOBALS['test_options'][ Fresh_Eyes_User::OPTION ] );
		$this->assertSame( '1', $GLOBALS['test_user_meta'][ $id ][ Fresh_Eyes_User::MARKER_META ] );
		$this->assertTrue( Fresh_Eyes_User::is_user( $id ) );
	}

	public function test_reuses_the_account_it_created(): void {
		$id = $this->create_user();
		Fresh_Eyes_User::reset_cache();

		$this->assertSame( $id, Fresh_Eyes_User::ensure() );
		$this->assertCount( 1, $GLOBALS['test_inserted_users'] );
	}

	public function test_a_taken_login_is_left_alone_and_another_is_picked(): void {
		$this->add_person( 5, 'fresh-eyes' );
		$this->add_person( 6, 'fresh-eyes-2' );

		$id = $this->create_user();

		$this->assertSame( 'fresh-eyes-3', $GLOBALS['test_inserted_users'][ $id ]['user_login'] );
		$this->assertSame( 'Fresh Eyes', $GLOBALS['test_inserted_users'][ $id ]['display_name'] );
		$this->assertArrayNotHasKey( Fresh_Eyes_User::MARKER_META, $GLOBALS['test_user_meta'][5] );
		$this->assertSame( 'fresh-eyes@example.com', $GLOBALS['test_user_objects'][5]->user_email );
		$this->assertFalse( Fresh_Eyes_User::is_user( 5 ) );
	}

	public function test_an_option_pointing_at_someone_else_is_not_trusted(): void {
		$this->add_person( 5, 'fresh-eyes' );
		$GLOBALS['test_options'][ Fresh_Eyes_User::OPTION ] = 5;

		$id = $this->create_user();

		$this->assertNotSame( 5, $id );
		$this->assertSame( $id, $GLOBALS['test_options'][ Fresh_Eyes_User::OPTION ] );
		$this->assertArrayNotHasKey( Fresh_Eyes_User::MARKER_META, $GLOBALS['test_user_meta'][5] );
	}

	public function test_creation_waits_for_another_request_holding_the_lock(): void {
		$GLOBALS['test_options']['ai_feedback_user_lock'] = (string) time();

		$this->assertSame( 0, Fresh_Eyes_User::ensure() );
		$this->assertSame( array(), $GLOBALS['test_inserted_users'] );
		$this->assertArrayNotHasKey( Fresh_Eyes_User::OPTION, $GLOBALS['test_options'] );
	}

	public function test_an_abandoned_lock_is_taken_over_and_released(): void {
		$GLOBALS['test_options']['ai_feedback_user_lock'] = (string) ( time() - 120 );

		$id = $this->create_user();

		$this->assertCount( 1, $GLOBALS['test_inserted_users'] );
		$this->assertSame( $id, $GLOBALS['test_options'][ Fresh_Eyes_User::OPTION ] );
		$this->assertArrayNotHasKey( 'ai_feedback_user_lock', $GLOBALS['test_options'] );
	}

	public function test_an_account_another_request_created_is_adopted(): void {
		// Another request created and marked it, and this one read the option before it was stored.
		$this->add_person( 9, 'fresh-eyes' );
		$GLOBALS['test_user_meta'][9][ Fresh_Eyes_User::MARKER_META ] = '1';

		$this->assertSame( 9, Fresh_Eyes_User::ensure() );
		$this->assertSame( array(), $GLOBALS['test_inserted_users'] );
		$this->assertSame( 9, $GLOBALS['test_options'][ Fresh_Eyes_User::OPTION ] );
	}

	public function test_a_marked_account_with_another_login_is_not_adopted(): void {
		$this->add_person( 9, 'jane' );
		$GLOBALS['test_user_meta'][9][ Fresh_Eyes_User::MARKER_META ] = '1';

		$id = $this->create_user();

		$this->assertNotSame( 9, $id );
		$this->assertCount( 1, $GLOBALS['test_inserted_users'] );
	}

	public function test_a_failed_insert_releases_the_lock(): void {
		$GLOBALS['test_insert_user_error'] = new \WP_Error( 'existing_user_login', 'Taken.' );

		Fresh_Eyes_User::ensure();

		$this->assertArrayNotHasKey( 'ai_feedback_user_lock', $GLOBALS['test_options'] );
	}

	public function test_a_failed_insert_stores_nothing(): void {
		$GLOBALS['test_insert_user_error'] = new \WP_Error( 'existing_user_login', 'Taken.' );

		$this->assertSame( 0, Fresh_Eyes_User::ensure() );
		$this->assertArrayNotHasKey( Fresh_Eyes_User::OPTION, $GLOBALS['test_options'] );
	}

	public function test_upgrade_creates_the_user_once_per_version(): void {
		Fresh_Eyes_User::maybe_ensure();
		$this->assertCount( 1, $GLOBALS['test_inserted_users'] );
		$this->assertSame( AI_FEEDBACK_VERSION, $GLOBALS['test_options'][ Fresh_Eyes_User::CHECKED_OPTION ] );

		// Deleted behind the plugin's back; the same version does not look again.
		$GLOBALS['test_user_objects'] = array();
		Fresh_Eyes_User::maybe_ensure();
		$this->assertCount( 1, $GLOBALS['test_inserted_users'] );

		// A new version does.
		$GLOBALS['test_options'][ Fresh_Eyes_User::CHECKED_OPTION ] = '0.9';
		Fresh_Eyes_User::maybe_ensure();
		$this->assertCount( 2, $GLOBALS['test_inserted_users'] );
	}

	public function test_upgrade_waits_a_while_after_creation_did_not_work(): void {
		$GLOBALS['test_options']['ai_feedback_user_lock'] = (string) time();

		Fresh_Eyes_User::maybe_ensure();
		$this->assertSame( 1, $GLOBALS['test_transients']['ai_feedback_user_retry'] ?? null );
		$this->assertArrayNotHasKey( Fresh_Eyes_User::CHECKED_OPTION, $GLOBALS['test_options'] );

		// The lock is gone, but the next page load waits for the retry time.
		unset( $GLOBALS['test_options']['ai_feedback_user_lock'] );
		Fresh_Eyes_User::maybe_ensure();
		$this->assertSame( array(), $GLOBALS['test_inserted_users'] );

		unset( $GLOBALS['test_transients']['ai_feedback_user_retry'] );
		Fresh_Eyes_User::maybe_ensure();
		$this->assertCount( 1, $GLOBALS['test_inserted_users'] );
	}

	public function test_the_filter_stops_the_user_being_created(): void {
		$GLOBALS['test_filter_results'] = array( 'ai_feedback_create_mention_user' => false );

		$this->assertSame( 0, Fresh_Eyes_User::ensure() );
		Fresh_Eyes_User::maybe_ensure();

		$this->assertSame( array(), $GLOBALS['test_inserted_users'] );
		$this->assertSame( 0, Fresh_Eyes_User::get_id() );
		$this->assertSame( AI_FEEDBACK_VERSION . ':off', $GLOBALS['test_options'][ Fresh_Eyes_User::CHECKED_OPTION ] );
		$this->assertArrayNotHasKey( 'ai_feedback_user_retry', $GLOBALS['test_transients'] );

		// With no account, user queries get no extra SQL.
		$query = new \WP_User_Query();
		Fresh_Eyes_User::filter_user_query( $query );
		$this->assertSame( 'WHERE 1=1', $query->query_where );
	}

	public function test_turning_the_filter_off_removes_the_account_at_the_next_check(): void {
		Fresh_Eyes_User::maybe_ensure();
		$id = Fresh_Eyes_User::get_id();
		$this->assertGreaterThan( 0, $id );

		$GLOBALS['test_filter_results'] = array( 'ai_feedback_create_mention_user' => false );
		Fresh_Eyes_User::maybe_ensure();

		$this->assertSame( array( array( $id, null ) ), $GLOBALS['test_deleted_users'] );
		$this->assertArrayNotHasKey( Fresh_Eyes_User::OPTION, $GLOBALS['test_options'] );
		$this->assertSame( 0, Fresh_Eyes_User::get_id() );
		$query = new \WP_User_Query();
		Fresh_Eyes_User::filter_user_query( $query );
		$this->assertSame( 'WHERE 1=1', $query->query_where );

		// Deleting it does not bring it back while the filter says no.
		Fresh_Eyes_User::on_deleted_user( $id );
		$this->assertCount( 1, $GLOBALS['test_inserted_users'] );

		// A typed handle still asks Fresh Eyes; a chip for the old ID does not.
		$this->assertTrue( Mentions::mentions_fresh_eyes( '@fresh-eyes simplify this' ) );
		$this->assertFalse( Mentions::has_user_chip( '<span class="wp-note-mention user-' . $id . '">@Fresh Eyes</span>' ) );
	}

	public function test_turning_the_filter_back_on_creates_the_account_again(): void {
		$GLOBALS['test_filter_results'] = array( 'ai_feedback_create_mention_user' => false );
		Fresh_Eyes_User::maybe_ensure();

		unset( $GLOBALS['test_filter_results'] );
		Fresh_Eyes_User::maybe_ensure();

		$this->assertGreaterThan( 0, Fresh_Eyes_User::get_id() );
		$this->assertSame( AI_FEEDBACK_VERSION, $GLOBALS['test_options'][ Fresh_Eyes_User::CHECKED_OPTION ] );
	}

	public function test_the_filter_never_removes_an_account_it_did_not_create(): void {
		$this->add_person( 5, 'fresh-eyes' );
		$GLOBALS['test_options'][ Fresh_Eyes_User::OPTION ] = 5;
		$GLOBALS['test_filter_results']                     = array( 'ai_feedback_create_mention_user' => false );

		Fresh_Eyes_User::ensure();

		$this->assertSame( array(), $GLOBALS['test_deleted_users'] );
		$this->assertArrayHasKey( 5, $GLOBALS['test_user_objects'] );
	}

	public function test_a_deleted_user_is_recreated(): void {
		$id = $this->create_user();
		unset( $GLOBALS['test_user_objects'][ $id ] );

		Fresh_Eyes_User::on_deleted_user( $id );

		$new_id = Fresh_Eyes_User::get_id();
		$this->assertGreaterThan( $id, $new_id );
		$this->assertTrue( Fresh_Eyes_User::is_ours( $new_id ) );
	}

	public function test_deleting_someone_else_changes_nothing(): void {
		$id = $this->create_user();

		Fresh_Eyes_User::on_deleted_user( 5 );

		$this->assertSame( $id, Fresh_Eyes_User::get_id() );
		$this->assertCount( 1, $GLOBALS['test_inserted_users'] );
	}

	public function test_user_queries_leave_the_user_out(): void {
		$id    = $this->create_user();
		$query = new \WP_User_Query();

		Fresh_Eyes_User::filter_user_query( $query );

		$this->assertSame( "WHERE 1=1 AND wp_users.ID <> {$id}", $query->query_where );
		$this->assertSame( 'wp_users.ID', $query->query_fields );
	}

	public function test_user_queries_are_untouched_without_a_user(): void {
		$query = new \WP_User_Query();

		Fresh_Eyes_User::filter_user_query( $query );

		$this->assertSame( 'WHERE 1=1', $query->query_where );
	}

	public function test_the_completer_search_lists_the_user_past_other_conditions(): void {
		$id    = $this->create_user();
		$query = new \WP_User_Query();

		$query->query_fields = 'SQL_CALC_FOUND_ROWS wp_users.*';
		$query->query_where  = "WHERE 1=1 AND wp_users.ID IN ( SELECT DISTINCT post_author FROM wp_posts WHERE post_status = 'publish' ) AND (user_login LIKE '%fr%')";
		$query->query_vars   = Fresh_Eyes_User::flag_reveal( array(), $this->users_request( array( 'search' => 'fr' ) ) );

		Fresh_Eyes_User::filter_user_query( $query );

		$this->assertSame(
			"WHERE ( 1=1 AND wp_users.ID IN ( SELECT DISTINCT post_author FROM wp_posts WHERE post_status = 'publish' ) AND (user_login LIKE '%fr%') ) OR wp_users.ID = {$id}",
			$query->query_where
		);
		$this->assertSame( 'DISTINCT SQL_CALC_FOUND_ROWS wp_users.*', $query->query_fields );

		// Already distinct, eg. for a meta query with OR.
		$query->query_vars   = Fresh_Eyes_User::flag_reveal( array(), $this->users_request( array( 'search' => 'fr' ) ) );
		$query->query_fields = 'DISTINCT wp_users.ID';
		$query->query_where  = 'WHERE 1=1';
		Fresh_Eyes_User::filter_user_query( $query );
		$this->assertSame( 'DISTINCT wp_users.ID', $query->query_fields );
	}

	public function test_other_code_cannot_set_the_reveal_flag(): void {
		$id = $this->create_user();

		foreach ( array( true, 1, '1', 'guess' ) as $flag ) {
			$query             = new \WP_User_Query();
			$query->query_vars = array( Fresh_Eyes_User::REVEAL_VAR => $flag );

			Fresh_Eyes_User::filter_user_query( $query );

			$this->assertSame( "WHERE 1=1 AND wp_users.ID <> {$id}", $query->query_where, var_export( $flag, true ) );
		}
	}

	/**
	 * Requests the Notes completer sends while someone types Fresh Eyes.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function data_completer_searches(): array {
		return array(
			'fr'               => array( array( 'context' => 'view', 'search' => 'fr', '_locale' => 'user' ) ),
			'fresh'            => array( array( 'context' => 'view', 'search' => 'fresh', '_locale' => 'user' ) ),
			'fresh-e'          => array( array( 'context' => 'view', 'search' => 'fresh-e', '_locale' => 'user' ) ),
			'Fresh Ey'         => array( array( 'context' => 'view', 'search' => 'Fresh Ey', '_locale' => 'user' ) ),
			'FreshEy'          => array( array( 'context' => 'view', 'search' => 'FreshEy', '_locale' => 'user' ) ),
			'fresh_eyes'       => array( array( 'context' => 'view', 'search' => 'fresh_eyes', '_locale' => 'user' ) ),
			'plain permalinks' => array( array( 'rest_route' => '/wp/v2/users', 'context' => 'view', 'search' => 'fresh' ) ),
			'context default'  => array( array( 'search' => 'fresh' ) ),
		);
	}

	/**
	 * The completer's search for the name lists the user.
	 *
	 * @param array<string, mixed> $params Query parameters.
	 */
	#[DataProvider( 'data_completer_searches' )]
	public function test_the_completer_search_reveals_the_user( array $params ): void {
		$this->create_user();

		$this->assertTrue( Fresh_Eyes_User::is_reveal_request( $this->users_request( $params ) ) );

		$args = Fresh_Eyes_User::flag_reveal( array( 'number' => 10 ), $this->users_request( $params ) );
		$this->assertIsString( $args[ Fresh_Eyes_User::REVEAL_VAR ] );
		$this->assertSame( 32, strlen( $args[ Fresh_Eyes_User::REVEAL_VAR ] ) );
	}

	/**
	 * Other user requests never list the user.
	 *
	 * @return array<string, array{array<string, mixed>, 2?: string, 3?: string}>
	 */
	public static function data_other_requests(): array {
		$view = array(
			'context' => 'view',
			'_locale' => 'user',
		);
		return array(
			'one letter'           => array( $view + array( 'search' => 'f' ) ),
			'middle of the name'   => array( $view + array( 'search' => 'eyes' ) ),
			'someone else'         => array( $view + array( 'search' => 'fred' ) ),
			'longer than the name' => array( $view + array( 'search' => 'fresh eyes team' ) ),
			'no search'            => array( $view ),
			'empty search'         => array( $view + array( 'search' => '' ) ),
			'edit context'         => array( array( 'context' => 'edit', 'search' => 'fresh' ) ),
			'author picker'        => array( $view + array( 'search' => 'fresh', 'who' => 'authors' ) ),
			'search columns'       => array( $view + array( 'search' => 'fresh', 'search_columns' => array( 'name' ) ) ),
			'per page'             => array( $view + array( 'search' => 'fresh', 'per_page' => 100 ) ),
			'include'              => array( $view + array( 'search' => 'fresh', 'include' => array( 1 ) ) ),
			'capabilities'         => array( $view + array( 'search' => 'fresh', 'capabilities' => array( 'edit_posts' ) ) ),
			'roles'                => array( $view + array( 'search' => 'fresh', 'roles' => array( 'editor' ) ) ),
			'a post'               => array( $view + array( 'search' => 'fresh' ), 'POST' ),
			'a single user'        => array( $view + array( 'search' => 'fresh' ), 'GET', '/wp/v2/users/7' ),
			'another route'        => array( $view + array( 'search' => 'fresh' ), 'GET', '/wp/v2/posts' ),
		);
	}

	/**
	 * Any other request leaves the user hidden.
	 *
	 * @param array<string, mixed> $params Query parameters.
	 * @param string               $method Method.
	 * @param string               $route  Route.
	 */
	#[DataProvider( 'data_other_requests' )]
	public function test_other_requests_do_not_reveal_the_user( array $params, string $method = 'GET', string $route = '/wp/v2/users' ): void {
		$this->create_user();

		$this->assertFalse( Fresh_Eyes_User::is_reveal_request( $this->users_request( $params, $method, $route ) ) );

		$args = Fresh_Eyes_User::flag_reveal( array( Fresh_Eyes_User::REVEAL_VAR => true ), $this->users_request( $params, $method, $route ) );
		$this->assertArrayNotHasKey( Fresh_Eyes_User::REVEAL_VAR, $args, 'A caller cannot pass the reveal flag through.' );
	}

	public function test_only_people_who_can_run_a_review_see_the_user(): void {
		$this->create_user();
		$GLOBALS['test_current_user_can'] = static fn ( string $cap ): bool => 'edit_posts' !== $cap;

		$this->assertFalse( Fresh_Eyes_User::is_reveal_request( $this->users_request( array( 'search' => 'fresh' ) ) ) );
	}

	public function test_a_renamed_login_still_matches(): void {
		$this->add_person( 5, 'fresh-eyes' );
		$id = $this->create_user();
		$GLOBALS['test_user_objects'][ $id ]->display_name = 'Editorial Bot';

		$this->assertTrue( Fresh_Eyes_User::search_matches( 'editorial b' ) );
		$this->assertTrue( Fresh_Eyes_User::search_matches( 'fresh-eyes-2' ) );
		$this->assertTrue( Fresh_Eyes_User::search_matches( 'Fresh' ) );
		$this->assertFalse( Fresh_Eyes_User::search_matches( 'bot' ) );
	}

	public function test_counts_leave_the_user_out(): void {
		$this->create_user();
		$GLOBALS['test_count_users'] = array(
			'total_users' => 3,
			'avail_roles' => array(
				'administrator' => 2,
				'none'          => 1,
			),
		);

		$counts = Fresh_Eyes_User::filter_count_users( null, 'time', 1 );

		$this->assertSame(
			array(
				'total_users' => 2,
				'avail_roles' => array( 'administrator' => 2 ),
			),
			$counts
		);
	}

	public function test_counts_on_a_site_the_user_is_not_on_are_untouched(): void {
		$this->create_user();

		$this->assertNull( Fresh_Eyes_User::filter_count_users( null, 'time', 2 ) );

		$earlier = array(
			'total_users' => 9,
			'avail_roles' => array(),
		);
		$this->assertSame( $earlier, Fresh_Eyes_User::filter_count_users( $earlier, 'time', 1 ) );
	}

	public function test_rest_routes_for_the_user_are_not_found(): void {
		$id = $this->create_user();

		foreach ( array( "/wp/v2/users/{$id}", "/wp/v2/users/{$id}/application-passwords", "/wp/v2/users/{$id}/application-passwords/introspect" ) as $route ) {
			foreach ( array( 'GET', 'POST', 'DELETE' ) as $method ) {
				$result = Fresh_Eyes_User::hide_rest_user( null, array(), new \WP_REST_Request( $method, $route ) );
				$this->assertInstanceOf( \WP_Error::class, $result, "{$method} {$route}" );
				$this->assertSame( 'rest_user_invalid_id', $result->get_error_code() );
				$this->assertSame( array( 'status' => 404 ), $result->get_error_data() );
			}
		}

		foreach ( array( '/wp/v2/users', '/wp/v2/users/me', "/wp/v2/users/{$id}0", '/wp/v2/users/1' ) as $route ) {
			$this->assertNull( Fresh_Eyes_User::hide_rest_user( null, array(), new \WP_REST_Request( 'GET', $route ) ), $route );
		}
	}

	public function test_the_author_archive_is_not_found(): void {
		$id                          = $this->create_user();
		$GLOBALS['test_is_author']   = true;
		$GLOBALS['test_queried_id']  = 1;

		Fresh_Eyes_User::hide_author_archive();
		$this->assertFalse( $GLOBALS['wp_query']->is_404 );

		$GLOBALS['test_queried_id'] = $id;
		Fresh_Eyes_User::hide_author_archive();
		$this->assertTrue( $GLOBALS['wp_query']->is_404 );
		$this->assertSame( 404, $GLOBALS['test_status_header'] );
	}

	public function test_the_user_cannot_log_in(): void {
		$id   = $this->create_user();
		$user = $GLOBALS['test_user_objects'][ $id ];

		$result = Fresh_Eyes_User::block_login( $user );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_feedback_user_login', $result->get_error_code() );

		$this->add_person( 5, 'jane' );
		$this->assertSame( $GLOBALS['test_user_objects'][5], Fresh_Eyes_User::block_login( $GLOBALS['test_user_objects'][5] ) );
		$this->assertNull( Fresh_Eyes_User::block_login( null ) );

		$this->assertFalse( Fresh_Eyes_User::block_current_user( $id ) );
		$this->assertFalse( Fresh_Eyes_User::block_current_user( (string) $id ) );
		$this->assertSame( 5, Fresh_Eyes_User::block_current_user( 5 ) );
		$this->assertFalse( Fresh_Eyes_User::block_current_user( false ) );
	}

	public function test_no_application_passwords_password_resets_or_account_emails(): void {
		$id = $this->create_user();
		$this->add_person( 5, 'jane' );

		$this->assertFalse( Fresh_Eyes_User::allow_for_user( true, $GLOBALS['test_user_objects'][ $id ] ) );
		$this->assertTrue( Fresh_Eyes_User::allow_for_user( true, $GLOBALS['test_user_objects'][5] ) );
		$this->assertFalse( Fresh_Eyes_User::allow_for_user( false, $GLOBALS['test_user_objects'][5] ) );

		$this->assertFalse( Fresh_Eyes_User::allow_password_reset( true, $id ) );
		$this->assertTrue( Fresh_Eyes_User::allow_password_reset( true, 5 ) );
		// An error an earlier filter returned for someone else is kept.
		$error = new \WP_Error( 'no_reset', 'No.' );
		$this->assertSame( $error, Fresh_Eyes_User::allow_password_reset( $error, 5 ) );
		$this->assertFalse( Fresh_Eyes_User::allow_password_reset( $error, $id ) );

		$this->assertFalse( Fresh_Eyes_User::allow_email_for_user( true, array( 'ID' => $id ) ) );
		$this->assertTrue( Fresh_Eyes_User::allow_email_for_user( true, array( 'ID' => 5 ) ) );
	}

	public function test_nobody_can_edit_delete_or_promote_the_user(): void {
		$id = $this->create_user();

		foreach ( array( 'edit_user', 'delete_user', 'promote_user', 'remove_user' ) as $cap ) {
			$this->assertSame( array( 'do_not_allow' ), Fresh_Eyes_User::protect_caps( array( 'edit_users' ), $cap, 1, array( $id ) ), $cap );
			$this->assertSame( array( 'edit_users' ), Fresh_Eyes_User::protect_caps( array( 'edit_users' ), $cap, 1, array( 5 ) ), $cap );
		}
		$this->assertSame( array( 'edit_posts' ), Fresh_Eyes_User::protect_caps( array( 'edit_posts' ), 'edit_post', 1, array( $id ) ) );
	}

	public function test_a_role_or_email_given_to_the_user_is_taken_back(): void {
		$id = $this->create_user();
		$GLOBALS['test_user_meta'][ $id ]['wp_capabilities']   = array( 'administrator' => true );
		$GLOBALS['test_user_meta'][ $id ]['wp_3_capabilities'] = array( 'editor' => true );
		$GLOBALS['test_user_objects'][ $id ]->user_email       = 'someone@example.com';

		Fresh_Eyes_User::enforce( $id );

		$this->assertSame( array(), $GLOBALS['test_user_meta'][ $id ]['wp_capabilities'] );
		$this->assertSame( 0, $GLOBALS['test_user_meta'][ $id ]['wp_user_level'] );
		$this->assertSame( array(), $GLOBALS['test_user_meta'][ $id ]['wp_3_capabilities'] );
		$this->assertSame( 0, $GLOBALS['test_user_meta'][ $id ]['wp_3_user_level'] );
		$this->assertSame( '', $GLOBALS['test_user_objects'][ $id ]->user_email );
	}

	public function test_a_capability_written_to_meta_is_taken_back(): void {
		$id = $this->create_user();
		// What WP_User::add_cap() leaves behind, with no role or profile action.
		$GLOBALS['test_user_meta'][ $id ]['wp_capabilities'] = array( 'edit_posts' => true );

		Fresh_Eyes_User::on_user_meta( 1, $id, 'wp_capabilities' );

		$this->assertSame( array(), $GLOBALS['test_user_meta'][ $id ]['wp_capabilities'] );

		// Other meta and other users are left alone.
		$this->add_person( 5, 'jane' );
		Fresh_Eyes_User::on_user_meta( 2, 5, 'wp_capabilities' );
		Fresh_Eyes_User::on_user_meta( 3, $id, 'nickname' );
		$this->assertSame( array( 'editor' => true ), $GLOBALS['test_user_meta'][5]['wp_capabilities'] );
	}

	public function test_a_search_without_a_user_matches_nothing(): void {
		$this->assertFalse( Fresh_Eyes_User::search_matches( 'fresh' ) );
	}

	public function test_other_users_are_not_enforced(): void {
		$this->create_user();
		$this->add_person( 5, 'jane' );

		Fresh_Eyes_User::enforce( 5 );

		$this->assertSame( array( 'editor' => true ), $GLOBALS['test_user_meta'][5]['wp_capabilities'] );
		$this->assertSame( 'jane@example.com', $GLOBALS['test_user_objects'][5]->user_email );
		$this->assertSame( array(), $GLOBALS['test_updated_users'] );
	}

	public function test_uninstall_removes_the_user_and_its_option(): void {
		$id = $this->create_user();
		$GLOBALS['test_options'][ Fresh_Eyes_User::CHECKED_OPTION ] = AI_FEEDBACK_VERSION;

		Fresh_Eyes_User::uninstall();

		$this->assertSame( array( array( $id, null ) ), $GLOBALS['test_deleted_users'] );
		$this->assertArrayNotHasKey( Fresh_Eyes_User::OPTION, $GLOBALS['test_options'] );
		$this->assertArrayNotHasKey( Fresh_Eyes_User::CHECKED_OPTION, $GLOBALS['test_options'] );
	}

	public function test_uninstall_never_deletes_an_account_it_did_not_create(): void {
		$this->add_person( 5, 'fresh-eyes' );
		$GLOBALS['test_options'][ Fresh_Eyes_User::OPTION ] = 5;

		Fresh_Eyes_User::uninstall();

		$this->assertSame( array(), $GLOBALS['test_deleted_users'] );
		$this->assertArrayHasKey( 5, $GLOBALS['test_user_objects'] );
		$this->assertArrayNotHasKey( Fresh_Eyes_User::OPTION, $GLOBALS['test_options'] );
	}
}
