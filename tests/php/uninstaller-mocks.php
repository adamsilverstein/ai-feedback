<?php
/**
 * WordPress stand-ins for the uninstall tests.
 *
 * Each site's options, transients, post meta, comment meta and cron events
 * live in the usual globals while that site is current; switch_to_blog()
 * swaps them for another site's. User meta is shared by the network.
 *
 * @package AI_Feedback\Tests
 */

// phpcs:disable

require_once __DIR__ . '/fresh-eyes-user-mocks.php';

/**
 * Globals that hold one site's data.
 */
const AI_FEEDBACK_TEST_SITE_GLOBALS = array( 'test_options', 'test_transients', 'test_post_meta', 'test_comment_meta', 'test_cron' );

/**
 * Database stand-in that answers the uninstaller's lookups from the globals.
 */
class Uninstall_Test_WPDB extends wpdb {
	public $usermeta = 'wp_usermeta';

	public $commentmeta = 'wp_commentmeta';

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	/**
	 * Whether a value matches a SQL LIKE pattern.
	 *
	 * @param string $value   Value.
	 * @param string $pattern LIKE pattern.
	 * @return bool
	 */
	private static function like( string $value, string $pattern ): bool {
		$regex = '';
		$len   = strlen( $pattern );
		for ( $i = 0; $i < $len; $i++ ) {
			$char = $pattern[ $i ];
			if ( '\\' === $char && $i + 1 < $len ) {
				$regex .= preg_quote( $pattern[ ++$i ], '/' );
			} elseif ( '%' === $char ) {
				$regex .= '.*';
			} elseif ( '_' === $char ) {
				$regex .= '.';
			} else {
				$regex .= preg_quote( $char, '/' );
			}
		}
		return 1 === preg_match( '/^' . $regex . '$/s', $value );
	}

	public function get_col( $prepared ) {
		$query = $prepared['query'];
		if ( str_contains( $query, 'option_name' ) ) {
			$names = array_keys( $GLOBALS['test_options'] ?? array() );
			foreach ( array_keys( $GLOBALS['test_transients'] ?? array() ) as $transient ) {
				$names[] = '_transient_' . $transient;
			}
			return array_values( array_filter( $names, fn ( $name ) => self::like( (string) $name, $prepared['args'][1] ) ) );
		}
		if ( str_contains( $query, 'DISTINCT comment_id' ) ) {
			$key    = $prepared['args'][1];
			$values = array_slice( $prepared['args'], 2 );
			$ids = array();
			foreach ( $GLOBALS['test_comment_meta'] ?? array() as $comment_id => $meta ) {
				if ( in_array( $meta[ $key ] ?? null, $values, true ) ) {
					$ids[] = (string) $comment_id;
				}
			}
			return $ids;
		}
		if ( str_contains( $query, 'DISTINCT meta_key' ) ) {
			$keys = array();
			foreach ( $GLOBALS['test_user_meta'] ?? array() as $meta ) {
				foreach ( array_keys( $meta ) as $key ) {
					if ( self::like( (string) $key, $prepared['args'][1] ) ) {
						$keys[ $key ] = true;
					}
				}
			}
			return array_keys( $keys );
		}
		return array();
	}

	public function get_results( $prepared ) {
		list( , $after, $key_like, $value_like, $limit ) = $prepared['args'];
		$rows = array();
		$id   = 0;
		foreach ( $GLOBALS['test_user_meta'] ?? array() as $user_id => $meta ) {
			foreach ( $meta as $key => $value ) {
				++$id;
				if ( $id <= $after || ! self::like( (string) $key, $key_like ) || ! self::like( serialize( $value ), $value_like ) ) {
					continue;
				}
				$rows[] = (object) array(
					'umeta_id' => $id,
					'user_id'  => $user_id,
					'meta_key' => $key,
				);
			}
		}
		return array_slice( $rows, 0, $limit );
	}
}

if ( ! function_exists( 'delete_post_meta_by_key' ) ) {
	function delete_post_meta_by_key( $key ) {
		foreach ( array_keys( $GLOBALS['test_post_meta'] ?? array() ) as $post_id ) {
			unset( $GLOBALS['test_post_meta'][ $post_id ][ $key ] );
		}
		return true;
	}
}

if ( ! function_exists( 'delete_metadata' ) ) {
	function delete_metadata( $type, $object_id, $key, $value = '', $delete_all = false ) {
		$global = 'user' === $type ? 'test_user_meta' : 'test_' . $type . '_meta';
		foreach ( array_keys( $GLOBALS[ $global ] ?? array() ) as $id ) {
			if ( $delete_all || (int) $id === (int) $object_id ) {
				unset( $GLOBALS[ $global ][ $id ][ $key ] );
			}
		}
		return true;
	}
}

if ( ! function_exists( 'wp_unschedule_hook' ) ) {
	function wp_unschedule_hook( $hook ) {
		$GLOBALS['test_cron'] = array_values(
			array_filter( $GLOBALS['test_cron'] ?? array(), static fn ( $event ) => $event['hook'] !== $hook )
		);
		return 1;
	}
}

if ( ! function_exists( 'get_sites' ) ) {
	function get_sites( $args = array() ) {
		$GLOBALS['test_get_sites_calls'][] = $args;
		return array_slice( $GLOBALS['test_site_ids'] ?? array(), $args['offset'] ?? 0, $args['number'] ?: null );
	}
}

if ( ! function_exists( 'switch_to_blog' ) ) {
	function switch_to_blog( $site_id ) {
		$current = $GLOBALS['test_blog_id'] ?? 1;
		foreach ( AI_FEEDBACK_TEST_SITE_GLOBALS as $global ) {
			$GLOBALS['test_sites'][ $current ][ $global ] = $GLOBALS[ $global ] ?? array();
			$GLOBALS[ $global ]                          = $GLOBALS['test_sites'][ $site_id ][ $global ] ?? array();
		}
		$GLOBALS['test_blog_stack'][] = $current;
		$GLOBALS['test_blog_id']      = (int) $site_id;
		$GLOBALS['test_switched_to'][] = (int) $site_id;
		return true;
	}
}

if ( ! function_exists( 'restore_current_blog' ) ) {
	function restore_current_blog() {
		$previous = array_pop( $GLOBALS['test_blog_stack'] );
		switch_to_blog( $previous );
		array_pop( $GLOBALS['test_blog_stack'] );
		array_pop( $GLOBALS['test_switched_to'] );
		return true;
	}
}

if ( ! function_exists( 'get_networks' ) ) {
	function get_networks( $args = array() ) {
		return $GLOBALS['test_network_ids'] ?? array( 1 );
	}
}

if ( ! function_exists( 'delete_blog_option' ) ) {
	function delete_blog_option( $site_id, $name ) {
		if ( (int) $site_id === (int) ( $GLOBALS['test_blog_id'] ?? 1 ) ) {
			unset( $GLOBALS['test_options'][ $name ] );
		} else {
			unset( $GLOBALS['test_sites'][ $site_id ]['test_options'][ $name ] );
		}
		return true;
	}
}

if ( ! function_exists( 'get_blogs_of_user' ) ) {
	function get_blogs_of_user( $user_id, $all = false ) {
		return array( 1 => (object) array( 'userblog_id' => 1 ) );
	}
}

if ( ! function_exists( 'wpmu_delete_user' ) ) {
	function wpmu_delete_user( $id ) {
		$GLOBALS['test_deleted_users'][] = array( $id, null );
		unset( $GLOBALS['test_user_objects'][ $id ] );
		\AI_Feedback\Fresh_Eyes_User::on_deleted_user( (int) $id );
		return true;
	}
}
