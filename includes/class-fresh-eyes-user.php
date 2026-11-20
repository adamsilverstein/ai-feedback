<?php
/**
 * Fresh Eyes user
 *
 * A hidden site user named Fresh Eyes, so the Notes `@` completer can offer
 * it like any person on the site.
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

use WP_Error;
use WP_REST_Request;
use WP_User;
use WP_User_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates, hides and guards the Fresh Eyes user.
 *
 * The account exists only so the Notes `@` completer lists it, which saves
 * a `<span class="wp-note-mention user-N">` chip in the note. It has no
 * role, no email address and cannot log in. User queries never return it,
 * except for the completer's own search when the typed text starts its name.
 */
class Fresh_Eyes_User {

	/**
	 * Network option holding the user's ID. A site option on single sites.
	 */
	public const OPTION = 'ai_feedback_user_id';

	/**
	 * User meta flagging the account as the one this plugin created.
	 */
	public const MARKER_META = 'ai_feedback_user';

	/**
	 * Site option holding the plugin version that last checked the user.
	 */
	public const CHECKED_OPTION = 'ai_feedback_user_checked';

	/**
	 * Transient set after creating the user did not work, to wait before trying again.
	 */
	private const RETRY_TRANSIENT = 'ai_feedback_user_retry';

	/**
	 * Option used as the lock while the user is created.
	 */
	private const LOCK_OPTION = 'ai_feedback_user_lock';

	/**
	 * Seconds after which a creation lock is treated as abandoned.
	 */
	private const LOCK_TTL = 60;

	/**
	 * Preferred login.
	 */
	public const LOGIN = 'fresh-eyes';

	/**
	 * WP_User_Query var set on the completer's search to list the user.
	 */
	public const REVEAL_VAR = 'ai_feedback_reveal';

	/**
	 * Fewest typed characters that list the user.
	 */
	public const MIN_SEARCH_LENGTH = 2;

	/**
	 * Query parameters the Notes completer sends: `context=view&search=...`,
	 * plus the `_locale` apiFetch adds. A request with any other parameter
	 * is a different screen and never lists the user.
	 */
	private const REVEAL_PARAMS = array( 'context', 'search', '_locale', 'rest_route' );

	/**
	 * Secret the reveal flag must carry, so only flag_reveal() can set it.
	 *
	 * @var string|null
	 */
	private static ?string $reveal_token = null;

	/**
	 * Cached user ID.
	 *
	 * @var int|null
	 */
	private static ?int $id = null;

	/**
	 * Set while this class changes the account, so its own guards stay out of the way.
	 *
	 * @var bool
	 */
	private static bool $updating = false;

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'maybe_ensure' ) );
		add_action( 'deleted_user', array( self::class, 'on_deleted_user' ) );

		// Hidden.
		add_action( 'pre_user_query', array( self::class, 'filter_user_query' ) );
		add_filter( 'rest_user_query', array( self::class, 'flag_reveal' ), 10, 2 );
		add_filter( 'pre_count_users', array( self::class, 'filter_count_users' ), PHP_INT_MAX, 3 );
		add_filter( 'rest_request_before_callbacks', array( self::class, 'hide_rest_user' ), 10, 3 );
		add_action( 'wp', array( self::class, 'hide_author_archive' ) );

		// Inert.
		add_filter( 'authenticate', array( self::class, 'block_login' ), 100 );
		add_filter( 'determine_current_user', array( self::class, 'block_current_user' ), 100 );
		add_filter( 'wp_is_application_passwords_available_for_user', array( self::class, 'allow_for_user' ), 10, 2 );
		add_filter( 'allow_password_reset', array( self::class, 'allow_password_reset' ), 10, 2 );
		add_filter( 'send_password_change_email', array( self::class, 'allow_email_for_user' ), 10, 2 );
		add_filter( 'send_email_change_email', array( self::class, 'allow_email_for_user' ), 10, 2 );
		add_filter( 'map_meta_cap', array( self::class, 'protect_caps' ), 10, 4 );
		add_action( 'set_user_role', array( self::class, 'enforce' ) );
		add_action( 'add_user_role', array( self::class, 'enforce' ) );
		add_action( 'profile_update', array( self::class, 'enforce' ) );
		add_action( 'granted_super_admin', array( self::class, 'enforce' ) );
		add_action( 'add_user_to_blog', array( self::class, 'enforce' ) );
		add_action( 'added_user_meta', array( self::class, 'on_user_meta' ), 10, 3 );
		add_action( 'updated_user_meta', array( self::class, 'on_user_meta' ), 10, 3 );
	}

	/**
	 * Get the user's ID.
	 *
	 * @return int User ID, or 0 when there is none.
	 */
	public static function get_id(): int {
		if ( null === self::$id ) {
			$value    = get_network_option( null, self::OPTION, 0 );
			self::$id = is_numeric( $value ) ? max( 0, (int) $value ) : 0;
		}
		return self::$id;
	}

	/**
	 * Forget the cached ID.
	 */
	public static function reset_cache(): void {
		self::$id = null;
	}

	/**
	 * Whether a user is Fresh Eyes.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_user( int $user_id ): bool {
		$id = self::get_id();
		return $id > 0 && $user_id === $id;
	}

	/**
	 * Create the user on upgrade, once per plugin version and site.
	 */
	public static function maybe_ensure(): void {
		// The filter's answer is part of what was checked, so changing it is acted on at the next admin page load.
		$checked = AI_FEEDBACK_VERSION . ( self::should_exist() ? '' : ':off' );
		if ( get_option( self::CHECKED_OPTION ) === $checked || false !== get_transient( self::RETRY_TRANSIENT ) ) {
			return;
		}
		if ( self::ensure() > 0 || ! self::should_exist() ) {
			update_option( self::CHECKED_OPTION, $checked );
			return;
		}
		// Another request holds the lock, or creating failed: try again in a few minutes, not on every page.
		set_transient( self::RETRY_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Whether the site wants the Fresh Eyes user.
	 *
	 * @return bool
	 */
	public static function should_exist(): bool {
		/**
		 * Filters whether to create the hidden Fresh Eyes user the Notes `@`
		 * completer offers. Return false to go without it: an existing
		 * account is removed at the next activation or admin page load, and
		 * typing `@fresh-eyes` still asks Fresh Eyes.
		 *
		 * @param bool $create Whether to create the user. Default true.
		 */
		return false !== apply_filters( 'ai_feedback_create_mention_user', true );
	}

	/**
	 * Make sure the user exists, creating it when it does not.
	 *
	 * The stored ID is only trusted while that account still carries the
	 * plugin's marker. Otherwise an account that carries it is adopted, or a
	 * new one is created, and an unmarked account is left alone. A lock keeps
	 * two requests after an update from each creating one.
	 *
	 * @return int User ID, or 0 when it could not be created or another request is creating it.
	 */
	public static function ensure(): int {
		if ( ! self::should_exist() ) {
			self::remove();
			return 0;
		}

		$id = self::get_id();
		if ( $id > 0 && self::is_ours( $id ) ) {
			// Undo anything it picked up while the plugin was inactive and not guarding it.
			self::enforce( $id );
			return $id;
		}

		if ( ! self::acquire_lock() ) {
			return 0;
		}

		try {
			$user_id = self::find_marked();
			if ( 0 === $user_id ) {
				$created = self::create();
				if ( is_wp_error( $created ) ) {
					Logger::debug( 'Fresh_Eyes_User: could not create the user: ' . $created->get_error_message() );
					return 0;
				}
				$user_id = $created;
			}

			update_network_option( null, self::OPTION, $user_id );
			self::$id = $user_id;
			self::enforce( $user_id );
			return $user_id;
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Find an account carrying the plugin's marker, such as one another
	 * request created, straight from the database.
	 *
	 * @return int User ID, or 0.
	 */
	private static function find_marked(): int {
		$ids = get_users(
			array(
				'blog_id'    => 0,
				'meta_key'   => self::MARKER_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Runs once, when the user is missing.
				'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Runs once, when the user is missing.
				'fields'     => 'ID',
				'orderby'    => 'ID',
				'number'     => 1,
			)
		);
		$id  = $ids[0] ?? 0;
		if ( ! is_numeric( $id ) ) {
			return 0;
		}

		// The marker is not a secret, so also want a login this plugin would pick.
		$user = get_userdata( (int) $id );
		return $user instanceof WP_User && str_starts_with( $user->user_login, self::LOGIN ) ? (int) $id : 0;
	}

	/**
	 * Take the creation lock.
	 *
	 * Uses an INSERT IGNORE on the options table, like WP_Upgrader::create_lock(),
	 * because add_option() checks and inserts in separate queries. A lock
	 * older than LOCK_TTL is treated as abandoned.
	 *
	 * @return bool True when the lock was acquired.
	 */
	private static function acquire_lock(): bool {
		$db = self::db();
		if ( null === $db ) {
			return false;
		}

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$insert = $db->prepare( "INSERT IGNORE INTO %i ( `option_name`, `option_value`, `autoload` ) VALUES (%s, %s, 'off') /* LOCK */", self::lock_table( $db ), self::LOCK_OPTION, (string) time() );
			if ( null === $insert ) {
				return false;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Atomic lock, must hit the database; prepared above.
			if ( $db->query( $insert ) ) {
				return true;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the lock without a stale option cache.
			$locked_at = $db->get_var( $db->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', self::lock_table( $db ), self::LOCK_OPTION ) );
			if ( ! is_numeric( $locked_at ) || (int) $locked_at > time() - self::LOCK_TTL ) {
				return false;
			}

			// Delete only the stale lock seen, so two requests taking it over can't both win.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Paired with the INSERT IGNORE lock.
			$db->delete(
				self::lock_table( $db ),
				array(
					'option_name'  => self::LOCK_OPTION,
					'option_value' => $locked_at,
				)
			);
		}

		return false;
	}

	/**
	 * The table the creation lock lives in: the main site's options on
	 * multisite, since the user is shared by the network.
	 *
	 * @param \wpdb $db Database.
	 * @return string Table name.
	 */
	private static function lock_table( \wpdb $db ): string {
		return is_multisite() ? $db->base_prefix . 'options' : $db->options;
	}

	/**
	 * Release the creation lock.
	 */
	private static function release_lock(): void {
		$db = self::db();
		if ( null !== $db ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Paired with the INSERT IGNORE lock.
			$db->delete( self::lock_table( $db ), array( 'option_name' => self::LOCK_OPTION ) );
		}
	}

	/**
	 * Whether an account is the one this plugin created.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_ours( int $user_id ): bool {
		return $user_id > 0
			&& false !== get_userdata( $user_id )
			&& '1' === get_user_meta( $user_id, self::MARKER_META, true );
	}

	/**
	 * Create the account: no role, no email address, a password nobody is given.
	 *
	 * Inserting a user sends no email; only registration and the Add New
	 * User screen do.
	 *
	 * @return int|WP_Error User ID, or error.
	 */
	public static function create(): int|WP_Error {
		$name = __( 'Fresh Eyes', 'fresh-eyes' );

		self::$updating = true;
		try {
			$user_id = wp_insert_user(
				array(
					'user_login'           => self::available_login(),
					'user_pass'            => wp_generate_password( 64, true, true ),
					'user_email'           => '',
					'display_name'         => $name,
					'nickname'             => $name,
					'role'                 => '',
					'show_admin_bar_front' => 'false',
				)
			);
		} finally {
			self::$updating = false;
		}

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		update_user_meta( $user_id, self::MARKER_META, '1' );
		return $user_id;
	}

	/**
	 * Pick a login nobody has: `fresh-eyes`, then `fresh-eyes-2` and so on.
	 *
	 * @return string Login.
	 */
	public static function available_login(): string {
		for ( $i = 1; $i <= 20; $i++ ) {
			$login = 1 === $i ? self::LOGIN : self::LOGIN . '-' . $i;
			if ( ! username_exists( $login ) ) {
				return $login;
			}
		}
		return self::LOGIN . '-' . strtolower( wp_generate_password( 8, false ) );
	}

	/**
	 * Recreate the account when it is deleted while the plugin runs.
	 *
	 * Uninstall removes the option before the user, so it is not recreated then.
	 *
	 * @param int $user_id Deleted user ID.
	 */
	public static function on_deleted_user( int $user_id ): void {
		if ( ! self::is_user( $user_id ) ) {
			return;
		}
		delete_network_option( null, self::OPTION );
		self::$id = null;
		self::ensure();
	}

	/**
	 * Remove the account and its option. Used on uninstall.
	 *
	 * Only an account carrying the plugin's marker is deleted. It authors
	 * nothing, so nothing is reassigned.
	 */
	public static function uninstall(): void {
		$id = self::get_id();
		delete_network_option( null, self::OPTION );
		delete_option( self::CHECKED_OPTION );
		delete_option( self::LOCK_OPTION );
		delete_transient( self::RETRY_TRANSIENT );
		if ( is_multisite() ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				delete_blog_option( (int) $site_id, self::CHECKED_OPTION );
				delete_blog_option( (int) $site_id, self::LOCK_OPTION );
			}
		}
		self::$id = null;

		self::delete_account( $id );
	}

	/**
	 * Remove the account and forget its ID, when the filter says the site goes without it.
	 */
	public static function remove(): void {
		$id = self::get_id();
		// Forget the ID first, so deleting the user does not recreate it.
		delete_network_option( null, self::OPTION );
		self::$id = null;
		self::delete_account( $id );
	}

	/**
	 * Delete an account, only when it carries the plugin's marker. It
	 * authors nothing, so nothing is reassigned.
	 *
	 * @param int $id User ID.
	 */
	private static function delete_account( int $id ): void {
		if ( ! self::is_ours( $id ) ) {
			return;
		}

		if ( is_multisite() ) {
			if ( ! function_exists( 'wpmu_delete_user' ) ) {
				require_once ABSPATH . 'wp-admin/includes/ms.php';
			}
			wpmu_delete_user( $id );
			return;
		}
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		wp_delete_user( $id );
	}

	/**
	 * Keep the user out of user queries, unless the query is the Notes
	 * completer's search for its name.
	 *
	 * Every user list goes through WP_User_Query: the Users screen, author
	 * dropdowns, `wp_list_authors()`, sitemaps and `/wp/v2/users`.
	 *
	 * @param WP_User_Query $query User query.
	 */
	public static function filter_user_query( WP_User_Query $query ): void {
		$id = self::get_id();
		if ( $id <= 0 ) {
			return;
		}

		$db     = self::db();
		$where  = $query->query_where;
		$fields = $query->query_fields;
		if ( null === $db || ! is_string( $where ) || ! is_string( $fields ) ) {
			return;
		}
		$column = $db->users . '.ID';

		$flag = $query->query_vars[ self::REVEAL_VAR ] ?? null;
		if ( ! is_string( $flag ) || ! hash_equals( self::reveal_token(), $flag ) ) {
			$query->query_where = $where . " AND {$column} <> {$id}";
			return;
		}

		// The completer's search: list the user whatever else the query
		// requires, such as having published posts. A join, such as site
		// membership on multisite, could repeat its row, so rows are distinct.
		// The OR also skips include, exclude, roles and the like; only
		// is_reveal_request() allowing no such parameters keeps that safe.
		$conditions = preg_replace( '/^\s*WHERE\s+/i', '', $where, 1 );
		if ( null === $conditions ) {
			return;
		}
		$query->query_where = "WHERE ( {$conditions} ) OR {$column} = {$id}";
		if ( ! str_starts_with( ltrim( $fields ), 'DISTINCT' ) ) {
			$query->query_fields = 'DISTINCT ' . $fields;
		}
	}

	/**
	 * Flag the Notes completer's search for the user's name, so the user query lists it.
	 *
	 * @param array<string, mixed> $args    WP_User_Query arguments.
	 * @param WP_REST_Request      $request Users request.
	 * @return array<string, mixed> Arguments.
	 */
	public static function flag_reveal( array $args, WP_REST_Request $request ): array {
		unset( $args[ self::REVEAL_VAR ] );
		if ( self::is_reveal_request( $request ) ) {
			$args[ self::REVEAL_VAR ] = self::reveal_token();
		}
		return $args;
	}

	/**
	 * Get this request's reveal secret, so other code that builds a
	 * WP_User_Query cannot set the reveal flag.
	 *
	 * @return string
	 */
	private static function reveal_token(): string {
		if ( null === self::$reveal_token ) {
			self::$reveal_token = bin2hex( random_bytes( 16 ) );
		}
		return self::$reveal_token;
	}

	/**
	 * Whether a users request is the Notes completer searching for Fresh Eyes.
	 *
	 * The completer asks `GET /wp/v2/users?context=view&search=<typed text>`
	 * on each keystroke. Only that exact shape, from someone who can edit
	 * posts, with typed text that starts the user's name, login or a
	 * mention handle, lists the user. REVEAL_PARAMS is what keeps the
	 * query's `OR ID = N` from widening any other request.
	 *
	 * @param WP_REST_Request $request Users request.
	 * @return bool
	 */
	public static function is_reveal_request( WP_REST_Request $request ): bool {
		if ( 'GET' !== $request->get_method() || '/wp/v2/users' !== $request->get_route() ) {
			return false;
		}
		if ( array() !== array_diff( array_keys( $request->get_query_params() ), self::REVEAL_PARAMS ) ) {
			return false;
		}
		$context = $request->get_param( 'context' );
		if ( null !== $context && 'view' !== $context ) {
			return false;
		}
		$search = $request->get_param( 'search' );
		return is_string( $search ) && current_user_can( 'edit_posts' ) && self::search_matches( $search );
	}

	/**
	 * Whether typed text starts the user's name, login or a mention handle.
	 *
	 * Case, and hyphens, underscores or spaces between words, do not
	 * matter: `fr`, `fresh-e`, `Fresh Ey` and `FreshEy` all match.
	 *
	 * @param string $search Typed text.
	 * @return bool
	 */
	public static function search_matches( string $search ): bool {
		$typed = self::normalize_name( $search );
		if ( mb_strlen( str_replace( ' ', '', $typed ) ) < self::MIN_SEARCH_LENGTH ) {
			return false;
		}

		$id = self::get_id();
		if ( $id <= 0 ) {
			return false;
		}

		$names = Mentions::get_handles();
		$user  = get_userdata( $id );
		if ( $user instanceof WP_User ) {
			$names[] = $user->display_name;
			$names[] = $user->user_login;
		}

		foreach ( $names as $name ) {
			$name = self::normalize_name( (string) $name );
			if ( str_starts_with( $name, $typed ) || str_starts_with( str_replace( ' ', '', $name ), str_replace( ' ', '', $typed ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Lowercase a name and make every run of spaces, hyphens or underscores one space.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private static function normalize_name( string $name ): string {
		return trim( (string) preg_replace( '/[\s_-]+/u', ' ', mb_strtolower( $name ) ) );
	}

	/**
	 * Leave the user out of the Users screen's counts.
	 *
	 * @param array<string, mixed>|null $result   Counts from an earlier filter, or null.
	 * @param string                    $strategy Counting strategy.
	 * @param int                       $site_id  Site ID.
	 * @return array<string, mixed>|null Counts.
	 */
	public static function filter_count_users( ?array $result, string $strategy, int $site_id ): ?array {
		static $counting = false;
		$id              = self::get_id();
		if ( null !== $result || $counting || $id <= 0 ) {
			return $result;
		}

		$db = self::db();
		if ( null === $db ) {
			return null;
		}
		$caps = get_user_meta( $id, $db->get_blog_prefix( $site_id ) . 'capabilities', true );
		if ( ! is_array( $caps ) ) {
			// Not a member of this site, so not counted.
			return null;
		}

		$counting = true;
		try {
			$counts = count_users( 'memory' === $strategy ? 'memory' : 'time', $site_id );
		} finally {
			$counting = false;
		}

		$roles = array_keys( array_filter( $caps ) );
		$roles = array() === $roles ? array( 'none' ) : $roles;
		foreach ( $roles as $role ) {
			if ( isset( $counts['avail_roles'][ $role ] ) ) {
				--$counts['avail_roles'][ $role ];
				if ( $counts['avail_roles'][ $role ] <= 0 ) {
					unset( $counts['avail_roles'][ $role ] );
				}
			}
		}
		$counts['total_users'] = max( 0, (int) $counts['total_users'] - 1 );

		return $counts;
	}

	/**
	 * Answer requests for the user on `/wp/v2/users/<id>` routes as if it did not exist.
	 *
	 * Covers reading, editing and deleting it, and its application passwords.
	 * Nothing looks the user up by ID: a mention chip holds its name.
	 *
	 * @param mixed           $response Response so far.
	 * @param mixed           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed Response, or a 404 error for the user.
	 */
	public static function hide_rest_user( mixed $response, mixed $handler, WP_REST_Request $request ): mixed {
		if ( ! preg_match( '#^/wp/v2/users/(\d+)(?:/|$)#', $request->get_route(), $matches ) || ! self::is_user( (int) $matches[1] ) ) {
			return $response;
		}
		return new WP_Error( 'rest_user_invalid_id', __( 'Invalid user ID.', 'fresh-eyes' ), array( 'status' => 404 ) );
	}

	/**
	 * Answer the user's author archive, also reached through `?author=<id>`, with a 404.
	 */
	public static function hide_author_archive(): void {
		$wp_query = $GLOBALS['wp_query'] ?? null;
		if ( ! $wp_query instanceof \WP_Query || ! is_author() || ! self::is_user( get_queried_object_id() ) ) {
			return;
		}
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Refuse a login as the user, after any password or cookie check.
	 *
	 * @param mixed $user WP_User, WP_Error or null.
	 * @return mixed The same, or an error for the user.
	 */
	public static function block_login( mixed $user ): mixed {
		if ( $user instanceof WP_User && self::is_user( (int) $user->ID ) ) {
			return new WP_Error( 'ai_feedback_user_login', __( 'This account cannot log in.', 'fresh-eyes' ) );
		}
		return $user;
	}

	/**
	 * Never let the user be the current user, whatever authenticated the request.
	 *
	 * @param mixed $user_id User ID, or false.
	 * @return mixed The same, or false for the user.
	 */
	public static function block_current_user( mixed $user_id ): mixed {
		return is_numeric( $user_id ) && self::is_user( (int) $user_id ) ? false : $user_id;
	}

	/**
	 * Refuse application passwords for the user.
	 *
	 * @param bool    $allow Whether they are available.
	 * @param WP_User $user  User.
	 * @return bool
	 */
	public static function allow_for_user( bool $allow, WP_User $user ): bool {
		return $allow && ! self::is_user( (int) $user->ID );
	}

	/**
	 * Refuse a password reset for the user. Anything an earlier filter
	 * returned for someone else, such as an error, is kept.
	 *
	 * @param mixed $allow   Whether a reset is allowed, or an error.
	 * @param mixed $user_id User ID.
	 * @return mixed
	 */
	public static function allow_password_reset( mixed $allow, mixed $user_id ): mixed {
		return is_numeric( $user_id ) && self::is_user( (int) $user_id ) ? false : $allow;
	}

	/**
	 * Take back a capability written straight to the user's meta, eg. by
	 * `WP_User::add_cap()`, which fires no role or profile action.
	 *
	 * @param mixed $meta_id  Meta ID.
	 * @param mixed $user_id  User ID.
	 * @param mixed $meta_key Meta key.
	 */
	public static function on_user_meta( mixed $meta_id, mixed $user_id, mixed $meta_key ): void {
		if ( is_numeric( $user_id ) && is_string( $meta_key ) && str_ends_with( $meta_key, 'capabilities' ) ) {
			self::enforce( (int) $user_id );
		}
	}

	/**
	 * Send no password or email change email about the user.
	 *
	 * @param bool                 $send Whether to send.
	 * @param array<string, mixed> $user User data before the change.
	 * @return bool
	 */
	public static function allow_email_for_user( bool $send, array $user ): bool {
		$user_id = $user['ID'] ?? null;
		return $send && ! ( is_numeric( $user_id ) && self::is_user( (int) $user_id ) );
	}

	/**
	 * Nobody may edit, delete, promote or remove the user.
	 *
	 * @param string[] $caps    Primitive capabilities required.
	 * @param string   $cap     Meta capability checked.
	 * @param int      $user_id User being checked.
	 * @param mixed[]  $args    Arguments, the first the user acted on.
	 * @return string[] Capabilities.
	 */
	public static function protect_caps( array $caps, string $cap, int $user_id, array $args ): array {
		if ( in_array( $cap, array( 'edit_user', 'delete_user', 'promote_user', 'remove_user' ), true )
			&& isset( $args[0] ) && is_numeric( $args[0] ) && self::is_user( (int) $args[0] ) ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	}

	/**
	 * Put the account back the way it was created after anything changes
	 * it: no role or capabilities on any site, not a super admin, no email.
	 *
	 * The admin screens and REST API cannot change it; this covers code and
	 * WP-CLI, which skip capability checks.
	 *
	 * @param int $user_id Changed user ID.
	 */
	public static function enforce( int $user_id ): void {
		if ( self::$updating || ! self::is_user( $user_id ) ) {
			return;
		}

		$db = self::db();
		if ( null === $db ) {
			return;
		}
		self::$updating = true;
		try {
			$meta = get_user_meta( $user_id );
			foreach ( is_array( $meta ) ? $meta : array() as $key => $values ) {
				$key = (string) $key;
				if ( 1 === preg_match( '/^' . preg_quote( $db->base_prefix, '/' ) . '(?:\d+_)?capabilities$/', $key ) ) {
					$raw   = is_array( $values ) ? ( $values[0] ?? '' ) : $values;
					$value = is_string( $raw ) ? maybe_unserialize( $raw ) : $raw;
					if ( array() !== $value ) {
						update_user_meta( $user_id, $key, array() );
						update_user_meta( $user_id, substr( $key, 0, -strlen( 'capabilities' ) ) . 'user_level', 0 );
					}
				}
			}

			if ( is_multisite() && is_super_admin( $user_id ) ) {
				revoke_super_admin( $user_id );
			}

			$user = get_userdata( $user_id );
			if ( $user instanceof WP_User && '' !== $user->user_email ) {
				$updated = wp_update_user(
					array(
						'ID'         => $user_id,
						'user_email' => '',
					)
				);
				if ( is_wp_error( $updated ) ) {
					Logger::debug( 'Fresh_Eyes_User: could not clear the email address: ' . $updated->get_error_message() );
				}
			}

			clean_user_cache( $user_id );
		} finally {
			self::$updating = false;
		}
	}

	/**
	 * Get the database object.
	 *
	 * @return \wpdb|null
	 */
	private static function db(): ?\wpdb {
		$wpdb = $GLOBALS['wpdb'] ?? null;
		return $wpdb instanceof \wpdb ? $wpdb : null;
	}
}
