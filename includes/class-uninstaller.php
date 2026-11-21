<?php
/**
 * Uninstaller
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

/**
 * Removes the plugin's settings and personal data when it is deleted.
 *
 * Notes are kept, with their meta: they are the author's editorial record
 * and live in core's Notes, and a reinstall picks up where it left off.
 *
 * Every key the plugin stores is listed here, as removed or kept, and a
 * test fails when the code writes a key that is on neither list.
 */
class Uninstaller {

	/**
	 * Options removed on every site.
	 */
	public const OPTIONS = array(
		'ai_feedback_default_model',
		'ai_feedback_default_focus_areas',
		'ai_feedback_default_tone',
		'ai_feedback_feedback_locale',
		'ai_feedback_default_persona',
		'ai_feedback_review_audience',
		'ai_feedback_version',
		House_Style::SITE_OPTION,
		Analytics::OPTION_KEY,
		Fresh_Eyes_User::CHECKED_OPTION,
		'ai_feedback_user_lock',
		// A network option is a plain option on a single site, so a site
		// converted to multisite can still hold one. Removed after the user.
		Fresh_Eyes_User::OPTION,
	);

	/**
	 * Option name prefixes removed on every site, for options named per user.
	 */
	public const OPTION_PREFIXES = array(
		Review_Chunks::LOCK_PREFIX,
	);

	/**
	 * Transients removed on every site.
	 */
	public const TRANSIENTS = array(
		'ai_feedback_user_retry',
		// Remembered image refusals, from #329.
		'ai_feedback_image_refusals',
	);

	/**
	 * Transient name prefixes removed on every site: chunked review
	 * sessions per user, and the model list cache under any version.
	 */
	public const TRANSIENT_PREFIXES = array(
		Review_Chunks::TRANSIENT_PREFIX,
		'ai_feedback_available_models',
	);

	/**
	 * Network options. Fresh_Eyes_User::uninstall() removes them with the
	 * user, so nothing here reads this list: it records them for the test
	 * that every stored key is listed.
	 */
	public const NETWORK_OPTIONS = array(
		Fresh_Eyes_User::OPTION,
	);

	/**
	 * User meta removed for every user. The review preferences use the
	 * names of the options that hold their site defaults.
	 */
	public const USER_META = array(
		'ai_feedback_default_model',
		'ai_feedback_default_focus_areas',
		'ai_feedback_default_tone',
		'ai_feedback_feedback_locale',
		'ai_feedback_default_persona',
		'ai_feedback_review_audience',
		'ai_feedback_reviews',
		'ai_feedback_refunds',
	);

	/**
	 * User meta kept: the Fresh Eyes user's marker, which stays on an
	 * account that is kept because it owns content, so it is never mistaken
	 * for someone else's account.
	 */
	public const KEPT_USER_META = array(
		Fresh_Eyes_User::MARKER_META,
	);

	/**
	 * Scope of the plugin's editor preferences (tips seen, welcome shown)
	 * inside core's per-site `persisted_preferences` user meta.
	 */
	public const PREFERENCES_SCOPE = 'ai-feedback';

	/**
	 * Post meta removed on every site.
	 */
	public const POST_META = array(
		Review_History::META_KEY,
		// The review's open note IDs, kept next to its history, from #309.
		'_ai_feedback_open_notes',
	);

	/**
	 * Comment meta removed on every site: bookkeeping nothing reads any more.
	 * `feedback_revision` numbered thread edits until #317.
	 */
	public const COMMENT_META = array(
		'feedback_revision',
	);

	/**
	 * Comment meta kept, with the notes it belongs to.
	 */
	public const KEPT_COMMENT_META = array(
		'ai_feedback',
		'ai_feedback_resolved',
		Mentions::META_KEY,
		Personas::META_KEY,
		House_Style::PROPOSAL_META_KEY,
		House_Style::PROPOSAL_STATUS_META_KEY,
		Notes_Manager::REPLY_EDIT_META_KEY,
		Notes_Manager::BLOCK_ACTION_META_KEY,
		Notes_Manager::BLOCK_CHANGE_META_KEY,
		Reply_Cron_Dispatcher::STATUS_META_KEY,
		Reply_Cron_Dispatcher::REPLY_COMMENT_ID_META_KEY,
		Reply_Cron_Dispatcher::REPLY_ERROR_META_KEY,
		Reply_Cron_Dispatcher::PENDING_SINCE_META_KEY,
		Reply_Cron_Dispatcher::JOB_META_KEY,
		Reply_Cron_Dispatcher::CLAIMED_AT_META_KEY,
		Reply_Cron_Dispatcher::CLAIM_TOKEN_META_KEY,
		// Which model answered a note, from #323.
		'ai_answered_by',
	);

	/**
	 * Cron hooks unscheduled on every site, whatever their arguments.
	 */
	public const CRON_HOOKS = array(
		Reply_Cron_Dispatcher::CRON_HOOK,
	);

	/**
	 * Sites handled per batch on multisite.
	 */
	private const SITE_BATCH = 100;

	/**
	 * Users handled per batch when clearing editor preferences.
	 */
	private const USER_BATCH = 500;

	/**
	 * Remove the plugin's settings and personal data.
	 *
	 * The Fresh Eyes user goes first, since removing it needs the network
	 * option that holds its ID. User meta is shared by the whole network, so
	 * it is cleared once, after the sites.
	 */
	public static function uninstall(): void {
		Fresh_Eyes_User::uninstall();

		if ( is_multisite() ) {
			self::each_site( array( self::class, 'clean_site' ) );
		} else {
			self::clean_site();
		}

		self::clean_users();
	}

	/**
	 * Run a callback on every site of every network, a batch of sites at a time.
	 *
	 * A site that fails is logged and skipped, so the rest are still cleaned.
	 *
	 * @param callable $callback Called with no arguments while switched to each site.
	 */
	public static function each_site( callable $callback ): void {
		$offset = 0;
		do {
			$site_ids = get_sites(
				array(
					'fields'                 => 'ids',
					'number'                 => self::SITE_BATCH,
					'offset'                 => $offset,
					'orderby'                => 'id',
					'update_site_meta_cache' => false,
				)
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				try {
					$callback();
				} catch ( \Throwable $error ) {
					Logger::debug( sprintf( 'Uninstaller: could not clean site %d: %s', (int) $site_id, $error->getMessage() ) );
				} finally {
					restore_current_blog();
				}
			}
			$offset += self::SITE_BATCH;
			$is_full = count( $site_ids ) === self::SITE_BATCH;
		} while ( $is_full );
	}

	/**
	 * Remove the current site's options, transients, post meta, stale
	 * comment meta and scheduled events.
	 */
	public static function clean_site(): void {
		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}
		foreach ( self::OPTION_PREFIXES as $prefix ) {
			foreach ( self::options_starting_with( $prefix ) as $option ) {
				delete_option( $option );
			}
		}

		foreach ( self::TRANSIENTS as $transient ) {
			delete_transient( $transient );
		}
		// Only transients stored in the database can be found by prefix. With
		// a persistent object cache they live there instead, and expire.
		foreach ( self::TRANSIENT_PREFIXES as $prefix ) {
			foreach ( self::options_starting_with( '_transient_' . $prefix ) as $option ) {
				delete_transient( substr( $option, strlen( '_transient_' ) ) );
			}
		}

		foreach ( self::POST_META as $key ) {
			delete_post_meta_by_key( $key );
		}
		foreach ( self::COMMENT_META as $key ) {
			delete_metadata( 'comment', 0, $key, '', true );
		}

		foreach ( self::CRON_HOOKS as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}

	/**
	 * Remove the plugin's user meta for every user: review preferences,
	 * rate limit timestamps, personal house style rules on every site, and
	 * the plugin's scope of the editor preferences.
	 */
	public static function clean_users(): void {
		foreach ( self::USER_META as $key ) {
			delete_metadata( 'user', 0, $key, '', true );
		}

		// Per-site keys, found from the stored keys themselves so sites a
		// user has left, or that were deleted, are covered too.
		foreach ( self::user_meta_keys_ending_with( House_Style::USER_META_KEY ) as $key ) {
			if ( 1 === preg_match( House_Style::user_meta_key_pattern(), $key ) ) {
				delete_metadata( 'user', 0, $key, '', true );
			}
		}

		self::clean_editor_preferences();
	}

	/**
	 * Take the plugin's scope out of core's `persisted_preferences` user
	 * meta, leaving every other scope, a batch of users at a time.
	 */
	private static function clean_editor_preferences(): void {
		$db = self::db();
		if ( null === $db ) {
			return;
		}

		$pattern = '/^' . preg_quote( $db->base_prefix, '/' ) . '(?:\d+_)?persisted_preferences$/';
		$after   = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No API finds meta by part of its key and value; runs once, on uninstall.
			$rows = $db->get_results(
				$db->prepare(
					'SELECT umeta_id, user_id, meta_key FROM %i WHERE umeta_id > %d AND meta_key LIKE %s AND meta_value LIKE %s ORDER BY umeta_id LIMIT %d',
					$db->usermeta,
					$after,
					'%' . $db->esc_like( 'persisted_preferences' ),
					'%' . $db->esc_like( '"' . self::PREFERENCES_SCOPE . '"' ) . '%',
					self::USER_BATCH
				)
			);
			$rows = is_array( $rows ) ? $rows : array();
			foreach ( $rows as $row ) {
				$row     = get_object_vars( $row );
				$after   = is_numeric( $row['umeta_id'] ?? null ) ? (int) $row['umeta_id'] : $after;
				$user_id = is_numeric( $row['user_id'] ?? null ) ? (int) $row['user_id'] : 0;
				$key     = is_string( $row['meta_key'] ?? null ) ? $row['meta_key'] : '';
				if ( $user_id <= 0 || 1 !== preg_match( $pattern, $key ) ) {
					continue;
				}
				$preferences = get_user_meta( $user_id, $key, true );
				if ( is_array( $preferences ) && array_key_exists( self::PREFERENCES_SCOPE, $preferences ) ) {
					unset( $preferences[ self::PREFERENCES_SCOPE ] );
					update_user_meta( $user_id, $key, wp_slash( $preferences ) );
				}
			}
			$is_full = count( $rows ) === self::USER_BATCH;
		} while ( $is_full );
	}

	/**
	 * Names of the current site's options that start with a prefix.
	 *
	 * @param  string $prefix Option name prefix.
	 * @return list<string> Option names.
	 */
	private static function options_starting_with( string $prefix ): array {
		$db = self::db();
		if ( null === $db ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No API lists options by prefix; runs once, on uninstall.
		$names = $db->get_col( $db->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $db->options, $db->esc_like( $prefix ) . '%' ) );
		return array_values( array_filter( $names, 'is_string' ) );
	}

	/**
	 * Distinct user meta keys that end with a suffix.
	 *
	 * @param  string $suffix Key suffix.
	 * @return list<string> Meta keys.
	 */
	private static function user_meta_keys_ending_with( string $suffix ): array {
		$db = self::db();
		if ( null === $db ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No API lists meta keys; runs once, on uninstall.
		$keys = $db->get_col( $db->prepare( 'SELECT DISTINCT meta_key FROM %i WHERE meta_key LIKE %s', $db->usermeta, '%' . $db->esc_like( $suffix ) ) );
		return array_values( array_filter( $keys, 'is_string' ) );
	}

	/**
	 * The database object.
	 *
	 * @return \wpdb|null Database, or null when there is none.
	 */
	private static function db(): ?\wpdb {
		global $wpdb;
		return $wpdb instanceof \wpdb ? $wpdb : null;
	}
}
