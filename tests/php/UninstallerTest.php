<?php
/**
 * Tests for what uninstalling the plugin removes and keeps.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Fresh_Eyes_User;
use AI_Feedback\House_Style;
use AI_Feedback\Uninstaller;

require_once __DIR__ . '/uninstaller-mocks.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-uninstaller.php';

/**
 * Uninstaller tests.
 */
class UninstallerTest extends TestCase {

	/**
	 * A person's user ID.
	 */
	private const USER_ID = 7;

	/**
	 * A note's comment ID.
	 */
	private const NOTE_ID = 300;

	/**
	 * A post's ID.
	 */
	private const POST_ID = 42;

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
		$this->wpdb      = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new \Uninstall_Test_WPDB();
		foreach ( array( 'test_post_meta', 'test_comment_meta', 'test_cron', 'test_sites', 'test_site_ids', 'test_blog_stack', 'test_switched_to', 'test_get_sites_calls', 'test_posts', 'test_links' ) as $global ) {
			$GLOBALS[ $global ] = array();
		}
		$GLOBALS['test_blog_id'] = 1;
	}

	/**
	 * Clean up.
	 */
	protected function tearDown(): void {
		ai_feedback_test_reset_users();
		$GLOBALS['wpdb'] = $this->wpdb;
		foreach ( array( 'test_post_meta', 'test_comment_meta', 'test_cron', 'test_sites', 'test_site_ids', 'test_blog_stack', 'test_switched_to', 'test_get_sites_calls', 'test_blog_id', 'test_posts', 'test_links' ) as $global ) {
			unset( $GLOBALS[ $global ] );
		}
		parent::tearDown();
	}

	/**
	 * Store everything the plugin writes on the current site, plus data
	 * that isn't the plugin's.
	 */
	private function seed_site(): void {
		foreach ( Uninstaller::OPTIONS as $option ) {
			$GLOBALS['test_options'][ $option ] = 'stored';
		}
		$GLOBALS['test_options']['ai_feedback_chunk_lock_7'] = (string) time();
		$GLOBALS['test_options']['blogname']                 = 'Kept';
		$GLOBALS['test_options']['other_plugin_setting']     = 'Kept';

		$GLOBALS['test_transients']['ai_feedback_user_retry']           = 1;
		$GLOBALS['test_transients']['ai_feedback_chunked_review_7']     = array( 'review_id' => 'r1' );
		$GLOBALS['test_transients']['ai_feedback_available_models_v4']  = array( 'models' );
		$GLOBALS['test_transients']['ai_feedback_available_models_v3']  = array( 'old models' );
		$GLOBALS['test_transients']['other_plugin_cache']               = 'Kept';

		$GLOBALS['test_post_meta'][ self::POST_ID ] = array(
			'_ai_feedback_history' => array( array( 'review_id' => 'r1' ) ),
			'_edit_lock'           => 'Kept',
		);

		$note_meta = array_fill_keys( Uninstaller::KEPT_COMMENT_META, 'Kept' );
		$GLOBALS['test_comment_meta'][ self::NOTE_ID ] = $note_meta + array( 'feedback_revision' => 2 );

		$GLOBALS['test_cron'] = array(
			array(
				'hook' => 'ai_feedback_generate_reply',
				'args' => array( 301 ),
			),
			array(
				'hook' => 'ai_feedback_generate_reply',
				'args' => array( 302 ),
			),
			array(
				'hook' => 'wp_version_check',
				'args' => array(),
			),
		);
	}

	/**
	 * Store the plugin's user meta, and meta that isn't the plugin's.
	 */
	private function seed_users(): void {
		$rules = array(
			array(
				'id'   => 'r1',
				'text' => 'Use sentence case',
			),
		);

		$meta = array_fill_keys( Uninstaller::USER_META, 'stored' );

		$GLOBALS['test_user_meta'][ self::USER_ID ] = $meta + array(
			'wp_ai_feedback_personal_house_style'         => $rules,
			'wp_3_ai_feedback_personal_house_style'       => $rules,
			'ai_feedback_personal_house_style'            => $rules,
			'wp_other_ai_feedback_personal_house_style'   => 'Kept',
			'myplugin_ai_feedback_personal_house_style'   => 'Kept',
			'wp_ai_feedback_personal_house_style_backup'  => 'Kept',
			'wp_persisted_preferences'                    => array(
				'core/edit-post' => array( 'welcomeGuide' => false ),
				'ai-feedback'    => array( 'welcomed' => true ),
				'_modified'      => '2026-11-20T00:00:00Z',
			),
			'wp_2_persisted_preferences'                  => array( 'ai-feedback' => array( 'tipsSeen' => array( 'review' ) ) ),
			'other_persisted_preferences'                 => array( 'ai-feedback' => 'Kept' ),
			'wp_capabilities'                             => array( 'editor' => true ),
			'nickname'                                    => 'Kept',
		);
	}

	/**
	 * Settings, personal data, caches and scheduled events go; notes and
	 * everything that isn't the plugin's stay.
	 */
	public function test_uninstall_removes_settings_and_personal_data_and_keeps_notes(): void {
		// Created first: the seeded lock option would keep it from being made.
		$fresh_eyes = Fresh_Eyes_User::ensure();
		$this->assertGreaterThan( 0, $fresh_eyes );
		$this->seed_site();
		$this->seed_users();

		Uninstaller::uninstall();

		$this->assertSame(
			array(
				'blogname'             => 'Kept',
				'other_plugin_setting' => 'Kept',
			),
			$GLOBALS['test_options']
		);
		$this->assertSame( array( 'other_plugin_cache' => 'Kept' ), $GLOBALS['test_transients'] );
		$this->assertSame( array( self::POST_ID => array( '_edit_lock' => 'Kept' ) ), $GLOBALS['test_post_meta'] );
		$this->assertSame( array( 'wp_version_check' ), array_column( $GLOBALS['test_cron'], 'hook' ) );

		// Notes keep all their meta; only the unread revision number goes.
		$this->assertSame(
			array_fill_keys( Uninstaller::KEPT_COMMENT_META, 'Kept' ),
			$GLOBALS['test_comment_meta'][ self::NOTE_ID ]
		);

		$this->assertSame(
			array(
				'wp_other_ai_feedback_personal_house_style'  => 'Kept',
				'myplugin_ai_feedback_personal_house_style'  => 'Kept',
				'wp_ai_feedback_personal_house_style_backup' => 'Kept',
				'wp_persisted_preferences'                   => array(
					'core/edit-post' => array( 'welcomeGuide' => false ),
					'_modified'      => '2026-11-20T00:00:00Z',
				),
				'wp_2_persisted_preferences'                 => array(),
				'other_persisted_preferences'                => array( 'ai-feedback' => 'Kept' ),
				'wp_capabilities'                            => array( 'editor' => true ),
				'nickname'                                   => 'Kept',
			),
			$GLOBALS['test_user_meta'][ self::USER_ID ]
		);

		// The Fresh Eyes user is deleted, through Fresh_Eyes_User.
		$this->assertSame( array( array( $fresh_eyes, null ) ), $GLOBALS['test_deleted_users'] );
	}

	/**
	 * The user is removed before its stored ID is, so it can be found.
	 * An account without the plugin's marker is never deleted.
	 */
	public function test_uninstall_never_deletes_an_account_it_did_not_create(): void {
		$user               = new \Test_Fresh_Eyes_WP_User();
		$user->ID           = 5;
		$user->user_login   = 'fresh-eyes';
		$user->display_name = 'Fresh Eyes';

		$GLOBALS['test_user_objects'][5]                  = $user;
		$GLOBALS['test_options'][ Fresh_Eyes_User::OPTION ] = 5;

		Uninstaller::uninstall();

		$this->assertSame( array(), $GLOBALS['test_deleted_users'] );
		$this->assertArrayHasKey( 5, $GLOBALS['test_user_objects'] );
		$this->assertArrayNotHasKey( Fresh_Eyes_User::OPTION, $GLOBALS['test_options'] );
	}

	/**
	 * Uninstall deletes the user through Fresh_Eyes_User, so an account that
	 * owns a post is kept rather than deleted with its content, and keeps its
	 * marker so it is still recognized.
	 */
	public function test_uninstall_keeps_a_fresh_eyes_user_that_owns_content(): void {
		$fresh_eyes = Fresh_Eyes_User::ensure();
		$this->assertGreaterThan( 0, $fresh_eyes );
		$GLOBALS['test_posts'] = array(
			array(
				'post_author' => $fresh_eyes,
				'post_type'   => 'post',
				'post_status' => 'draft',
			),
		);

		Uninstaller::uninstall();

		$users = $GLOBALS['test_user_objects'];
		$meta  = $GLOBALS['test_user_meta'];
		$this->assertIsArray( $users );
		$this->assertIsArray( $meta );
		$this->assertIsArray( $meta[ $fresh_eyes ] ?? null );

		$this->assertSame( array(), $GLOBALS['test_deleted_users'] );
		$this->assertArrayHasKey( $fresh_eyes, $users );
		$this->assertSame( '1', $meta[ $fresh_eyes ][ Fresh_Eyes_User::MARKER_META ] ?? null );
	}

	/**
	 * On multisite every site is cleaned, a batch at a time, and the
	 * original site is current again afterwards.
	 */
	public function test_each_site_cleans_every_site_in_batches(): void {
		$GLOBALS['test_site_ids'] = range( 1, 150 );
		foreach ( array( 2, 120 ) as $site_id ) {
			switch_to_blog( $site_id );
			$this->seed_site();
			restore_current_blog();
		}
		$this->seed_site();

		Uninstaller::each_site( array( Uninstaller::class, 'clean_site' ) );

		$this->assertSame( 1, $GLOBALS['test_blog_id'] );
		$this->assertSame( array(), $GLOBALS['test_blog_stack'] );
		$this->assertSame( array( 0, 100 ), array_column( $GLOBALS['test_get_sites_calls'], 'offset' ) );
		$this->assertSame( array( 'ids', 'ids' ), array_column( $GLOBALS['test_get_sites_calls'], 'fields' ) );

		foreach ( array( 1, 2, 120 ) as $site_id ) {
			$data = 1 === $site_id ? array(
				'test_options'      => $GLOBALS['test_options'],
				'test_comment_meta' => $GLOBALS['test_comment_meta'],
				'test_cron'         => $GLOBALS['test_cron'],
			) : $GLOBALS['test_sites'][ $site_id ];
			$this->assertSame(
				array(
					'blogname'             => 'Kept',
					'other_plugin_setting' => 'Kept',
				),
				$data['test_options'],
				'Site ' . $site_id
			);
			$this->assertArrayNotHasKey( 'feedback_revision', $data['test_comment_meta'][ self::NOTE_ID ] );
			$this->assertSame( array( 'wp_version_check' ), array_column( $data['test_cron'], 'hook' ) );
		}
	}

	/**
	 * A site that fails is skipped, and the other sites are still cleaned.
	 */
	public function test_a_failing_site_does_not_stop_the_others(): void {
		$GLOBALS['test_site_ids'] = array( 1, 2, 3 );
		$cleaned                  = array();

		Uninstaller::each_site(
			static function () use ( &$cleaned ): void {
				if ( 2 === $GLOBALS['test_blog_id'] ) {
					throw new \RuntimeException( 'Table missing' );
				}
				$cleaned[] = $GLOBALS['test_blog_id'];
			}
		);

		$this->assertSame( array( 1, 3 ), $cleaned );
		$this->assertSame( 1, $GLOBALS['test_blog_id'] );
	}

	/**
	 * Every key the plugin writes is on the uninstaller's lists, as removed
	 * or kept, so a new setting can't be left behind by mistake.
	 *
	 * Reads each storage call in the plugin's code and resolves its key
	 * argument: a string, a class constant, or a constant prefix followed by
	 * a user ID. Keys built at run time are listed in DYNAMIC_KEYS with
	 * where their value comes from.
	 */
	public function test_every_stored_key_is_listed(): void {
		$removed_options = array_merge( Uninstaller::OPTIONS, Uninstaller::NETWORK_OPTIONS );
		$lists           = array(
			'option'       => array( $removed_options, Uninstaller::OPTION_PREFIXES ),
			'transient'    => array( Uninstaller::TRANSIENTS, Uninstaller::TRANSIENT_PREFIXES ),
			'user_meta'    => array( array_merge( Uninstaller::USER_META, Uninstaller::KEPT_USER_META, array( House_Style::USER_META_KEY ) ), array() ),
			'post_meta'    => array( Uninstaller::POST_META, array() ),
			'comment_meta' => array( array_merge( Uninstaller::COMMENT_META, Uninstaller::KEPT_COMMENT_META ), array() ),
		);

		$found = 0;
		foreach ( self::storage_calls() as $call ) {
			list( $kind, $key, $is_prefix, $where ) = $call;
			if ( null === $key ) {
				$this->assertContains( $where, self::DYNAMIC_KEYS, 'Unlisted key built at run time: ' . $where );
				continue;
			}
			++$found;
			list( $exact, $prefixes ) = $lists[ $kind ];
			$listed = $is_prefix
				? in_array( $key, $prefixes, true )
				: in_array( $key, $exact, true ) || array() !== array_filter( $prefixes, static fn ( string $prefix ): bool => str_starts_with( $key, $prefix ) );
			$this->assertTrue( $listed, sprintf( '%s %s "%s" (%s) is not on the uninstaller lists.', $kind, $is_prefix ? 'prefix' : 'key', $key, $where ) );
		}

		// The scan has to find the calls for the test to mean anything.
		$this->assertGreaterThan( 25, $found );
	}

	/**
	 * Storage calls whose key is built at run time, as "file:line function",
	 * with where the key comes from.
	 */
	private const DYNAMIC_KEYS = array(
		// Settings_Controller saves each setting through $save( 'ai_feedback_...' ), read separately.
		'class-user-preferences.php update_user_meta',
		// $rate_limit_key is 'ai_feedback_reviews'.
		'class-review-service.php update_user_meta',
		// Per-site personal rules: House_Style::user_meta_key(), matched by its pattern.
		'class-house-style.php update_user_meta',
		// Core's capabilities and user_level keys, emptied on the Fresh Eyes user.
		'class-fresh-eyes-user.php update_user_meta',
		// The uninstaller rewriting core's persisted_preferences without the plugin's scope.
		'class-uninstaller.php update_user_meta',
		// $key is Review_Chunks::TRANSIENT_PREFIX . $user_id; the prefix is checked where it is set.
		'class-review-chunks.php set_transient',
	);

	/**
	 * Find the plugin's storage calls and their keys.
	 *
	 * @return list<array{string, string|null, bool, string}> Kind, key (null when built at run time), whether it is a prefix, and where.
	 */
	private static function storage_calls(): array {
		$functions = array(
			'add_option'            => array( 'option', 0 ),
			'update_option'         => array( 'option', 0 ),
			'add_site_option'       => array( 'option', 0 ),
			'update_site_option'    => array( 'option', 0 ),
			'add_network_option'    => array( 'option', 1 ),
			'update_network_option' => array( 'option', 1 ),
			'set_transient'         => array( 'transient', 0 ),
			'set_site_transient'    => array( 'transient', 0 ),
			'add_user_meta'         => array( 'user_meta', 1 ),
			'update_user_meta'      => array( 'user_meta', 1 ),
			'update_user_option'    => array( 'user_meta', 1 ),
			'add_post_meta'         => array( 'post_meta', 1 ),
			'update_post_meta'      => array( 'post_meta', 1 ),
			'register_post_meta'    => array( 'post_meta', 1 ),
			'add_comment_meta'      => array( 'comment_meta', 1 ),
			'update_comment_meta'   => array( 'comment_meta', 1 ),
		);

		$root  = dirname( __DIR__, 2 );
		$files = array_merge( glob( $root . '/includes/*.php' ) ?: array(), array( $root . '/fresh-eyes.php' ) );
		$calls = array();
		foreach ( $files as $file ) {
			$tokens = array_values(
				array_filter(
					token_get_all( (string) file_get_contents( $file ) ),
					static fn ( $token ): bool => ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true )
				)
			);
			$class  = self::class_in( $tokens );
			$name   = basename( $file );
			$count  = count( $tokens );
			for ( $i = 0; $i < $count; $i++ ) {
				$token = $tokens[ $i ];
				// A settings save: $save( 'key', ... ) is an option and a user preference.
				$is_save = is_array( $token ) && T_VARIABLE === $token[0] && '$save' === $token[1] && '(' === ( $tokens[ $i + 1 ] ?? null );
				if ( ! $is_save && ( ! is_array( $token ) || T_STRING !== $token[0] || ! isset( $functions[ $token[1] ] ) || '(' !== ( $tokens[ $i + 1 ] ?? null ) ) ) {
					continue;
				}
				$previous = $tokens[ $i - 1 ] ?? null;
				if ( is_array( $previous ) && in_array( $previous[0], array( T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON ), true ) ) {
					continue;
				}

				$kinds    = $is_save ? array( array( 'option', 0 ), array( 'user_meta', 0 ) ) : array( $functions[ $token[1] ] );
				$argument = self::argument( $tokens, $i + 1 );
				foreach ( $kinds as list( $kind, $index ) ) {
					list( $key, $is_prefix ) = self::resolve( $argument[ $index ] ?? array(), $class );
					$calls[] = array( $kind, $key, $is_prefix, $name . ' ' . ( $is_save ? '$save' : $token[1] ) );
				}
			}
		}
		return $calls;
	}

	/**
	 * The fully qualified class a file declares.
	 *
	 * @param  list<mixed> $tokens Tokens.
	 * @return string Class name, or an empty string.
	 */
	private static function class_in( array $tokens ): string {
		$namespace = '';
		foreach ( $tokens as $i => $token ) {
			if ( is_array( $token ) && T_NAMESPACE === $token[0] && is_array( $tokens[ $i + 1 ] ) ) {
				$namespace = $tokens[ $i + 1 ][1];
			}
			if ( is_array( $token ) && T_CLASS === $token[0] && is_array( $tokens[ $i + 1 ] ) ) {
				return $namespace . '\\' . $tokens[ $i + 1 ][1];
			}
		}
		return '';
	}

	/**
	 * Split a call's arguments into token lists.
	 *
	 * @param  list<mixed> $tokens Tokens.
	 * @param  int         $open   Index of the opening parenthesis.
	 * @return list<list<mixed>> Tokens of each argument.
	 */
	private static function argument( array $tokens, int $open ): array {
		$arguments = array( array() );
		$depth     = 0;
		$count     = count( $tokens );
		for ( $i = $open + 1; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( in_array( $token, array( '(', '[' ), true ) ) {
				++$depth;
			} elseif ( in_array( $token, array( ')', ']' ), true ) ) {
				if ( 0 === $depth ) {
					break;
				}
				--$depth;
			} elseif ( ',' === $token && 0 === $depth ) {
				$arguments[] = array();
				continue;
			}
			$arguments[ count( $arguments ) - 1 ][] = $token;
		}
		return $arguments;
	}

	/**
	 * Resolve a key argument to its value.
	 *
	 * @param  list<mixed> $tokens Argument tokens.
	 * @param  string      $class  Class the call is in, for `self::`.
	 * @return array{string|null, bool} Key, or null when built at run time, and whether it is a prefix.
	 */
	private static function resolve( array $tokens, string $class ): array {
		$first = $tokens[0] ?? null;
		$value = null;
		$used  = 1;
		if ( is_array( $first ) && T_CONSTANT_ENCAPSED_STRING === $first[0] ) {
			$value = substr( $first[1], 1, -1 );
		} elseif ( is_array( $first ) && in_array( $first[0], array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) && T_DOUBLE_COLON === ( $tokens[1][0] ?? null ) && is_array( $tokens[2] ?? null ) && '(' !== ( $tokens[3] ?? null ) ) {
			$owner = 'self' === $first[1] ? $class : 'AI_Feedback\\' . ltrim( $first[1], '\\' );
			$value = ( new \ReflectionClassConstant( $owner, $tokens[2][1] ) )->getValue();
			$used  = 3;
		}
		if ( ! is_string( $value ) ) {
			return array( null, false );
		}
		return array( $value, count( $tokens ) > $used && '.' === $tokens[ $used ] );
	}
}
