<?php
/**
 * WordPress stand-ins for the Fresh Eyes user tests.
 *
 * Options live in $GLOBALS['test_options'], users in
 * $GLOBALS['test_user_objects'] and their meta in $GLOBALS['test_user_meta'].
 *
 * @package AI_Feedback\Tests
 */

if ( ! function_exists( 'register_meta' ) ) {
	function register_meta( $object_type, $meta_key, $args ) {
		$GLOBALS['test_registered_meta'][ $object_type ][ $meta_key ] = $args;
		return true;
	}
}

if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( $path = '', $plugin = '' ) {
		return 'http://example.test/wp-content/plugins/' . basename( dirname( $plugin ) ) . '/' . ltrim( $path, '/' );
	}
}

if ( ! defined( 'AI_FEEDBACK_VERSION' ) ) {
	define( 'AI_FEEDBACK_VERSION', '1.0.3' );
}

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public $ID           = 0;
		public $display_name = '';
		public $user_login   = '';
		public $user_email   = '';
	}
}

/**
 * A user with every field the Fresh Eyes user code reads.
 */
class Test_Fresh_Eyes_WP_User extends WP_User {
	public $user_login = '';
	public $user_email = '';
}

if ( ! class_exists( 'WP_User_Query' ) ) {
	class WP_User_Query {
		public $query_fields = 'wp_users.ID';
		public $query_where  = 'WHERE 1=1';
		public $query_vars   = array();
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	// The bootstrap's database stand-in, with the INSERT IGNORE lock, as the real class name.
	class wpdb extends Test_WPDB {
		public $prefix = 'wp_';

		/**
		 * Answer COUNT queries from rows in $GLOBALS['test_posts'] and
		 * $GLOBALS['test_links'], each with its own status and type, the way
		 * the database would: only the columns named in the WHERE clause filter.
		 */
		public function get_var( $prepared ) {
			$query = $prepared['query'];
			if ( ! str_starts_with( $query, 'SELECT COUNT(*)' ) ) {
				return parent::get_var( $prepared );
			}
			$rows   = str_contains( $query, 'link_owner' ) ? ( $GLOBALS['test_links'] ?? array() ) : ( $GLOBALS['test_posts'] ?? array() );
			$column = str_contains( $query, 'link_owner' ) ? 'link_owner' : 'post_author';
			$count  = 0;
			foreach ( $rows as $row ) {
				if ( (int) $row[ $column ] !== (int) $prepared['args'][1] ) {
					continue;
				}
				if ( str_contains( $query, 'post_status' ) && ! in_array( $row['post_status'], array( 'publish', 'private' ), true ) ) {
					continue;
				}
				++$count;
			}
			return (string) $count;
		}
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public bool $is_404 = false;
		public function set_404(): void {
			$this->is_404 = true;
		}
	}
}

/**
 * Reset the state these stand-ins read.
 */
function ai_feedback_test_reset_users(): void {
	$GLOBALS['test_options']           = array();
	$GLOBALS['test_transients']        = array();
	$GLOBALS['test_user_objects']      = array();
	$GLOBALS['test_user_meta']         = array();
	$GLOBALS['test_logins']            = array();
	$GLOBALS['test_inserted_users']    = array();
	$GLOBALS['test_insert_user_error'] = null;
	$GLOBALS['test_next_user_id']      = 100;
	$GLOBALS['test_deleted_users']     = array();
	$GLOBALS['test_posts']             = array();
	$GLOBALS['test_links']             = array();
	$GLOBALS['test_updated_users']     = array();
	$GLOBALS['test_count_users']       = array();
	$GLOBALS['test_is_author']         = false;
	$GLOBALS['test_queried_id']        = 0;
	$GLOBALS['test_status_header']     = null;
	$GLOBALS['wp_query']               = new WP_Query();
	unset( $GLOBALS['test_current_user_can'], $GLOBALS['test_filter_results'] );
	\AI_Feedback\Fresh_Eyes_User::reset_cache();
}

if ( ! function_exists( 'update_network_option' ) ) {
	function update_network_option( $network_id, $name, $value ) {
		$GLOBALS['test_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_network_option' ) ) {
	function delete_network_option( $network_id, $name ) {
		unset( $GLOBALS['test_options'][ $name ] );
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		unset( $GLOBALS['test_options'][ $name ] );
		return true;
	}
}

if ( ! function_exists( 'username_exists' ) ) {
	function username_exists( $login ) {
		return in_array( $login, $GLOBALS['test_logins'] ?? array(), true ) ? 1 : false;
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
		return substr( str_repeat( 'Ab3', (int) ceil( $length / 3 ) ), 0, $length );
	}
}

if ( ! function_exists( 'wp_insert_user' ) ) {
	function wp_insert_user( $userdata ) {
		if ( $GLOBALS['test_insert_user_error'] ?? null ) {
			return $GLOBALS['test_insert_user_error'];
		}
		$id   = $GLOBALS['test_next_user_id']++;
		$user = new Test_Fresh_Eyes_WP_User();

		$user->ID           = $id;
		$user->user_login   = $userdata['user_login'];
		$user->user_email   = $userdata['user_email'];
		$user->display_name = $userdata['display_name'];

		$GLOBALS['test_user_objects'][ $id ]             = $user;
		$GLOBALS['test_user_meta'][ $id ]['wp_capabilities'] = array();
		$GLOBALS['test_inserted_users'][ $id ]           = $userdata;
		$GLOBALS['test_logins'][]                        = $userdata['user_login'];
		return $id;
	}
}

if ( ! function_exists( 'wp_update_user' ) ) {
	function wp_update_user( $userdata ) {
		$GLOBALS['test_updated_users'][] = $userdata;
		$user                            = $GLOBALS['test_user_objects'][ $userdata['ID'] ] ?? null;
		if ( $user && array_key_exists( 'user_email', $userdata ) ) {
			$user->user_email = $userdata['user_email'];
		}
		return $userdata['ID'];
	}
}

if ( ! function_exists( 'wp_delete_user' ) ) {
	function wp_delete_user( $id, $reassign = null ) {
		$GLOBALS['test_deleted_users'][] = array( $id, $reassign );
		unset( $GLOBALS['test_user_objects'][ $id ] );
		// Like core's deleted_user action, which the plugin hooks.
		\AI_Feedback\Fresh_Eyes_User::on_deleted_user( (int) $id );
		return true;
	}
}

if ( ! function_exists( 'get_post_types' ) ) {
	function get_post_types( $args = array(), $output = 'names', $operator = 'and' ) {
		return array(
			'post' => 'post',
			'page' => 'page',
		);
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite() {
		return false;
	}
}

if ( ! function_exists( 'get_users' ) ) {
	// Only the marker lookup is needed: IDs of users carrying the given meta.
	function get_users( $args = array() ) {
		$ids = array();
		foreach ( $GLOBALS['test_user_meta'] ?? array() as $id => $meta ) {
			if ( isset( $args['meta_key'] ) && ( $meta[ $args['meta_key'] ] ?? null ) === $args['meta_value'] && isset( $GLOBALS['test_user_objects'][ $id ] ) ) {
				$ids[] = (string) $id;
			}
		}
		sort( $ids );
		return array_slice( $ids, 0, $args['number'] ?? 10 );
	}
}

if ( ! function_exists( 'count_users' ) ) {
	function count_users( $strategy = 'time', $site_id = null ) {
		return $GLOBALS['test_count_users'];
	}
}

if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( $data ) {
		return $data;
	}
}

if ( ! function_exists( 'clean_user_cache' ) ) {
	function clean_user_cache( $user ) {
	}
}

if ( ! function_exists( 'is_author' ) ) {
	function is_author( $author = '' ) {
		return $GLOBALS['test_is_author'];
	}
}

if ( ! function_exists( 'get_queried_object_id' ) ) {
	function get_queried_object_id() {
		return $GLOBALS['test_queried_id'];
	}
}

if ( ! function_exists( 'status_header' ) ) {
	function status_header( $code, $description = '' ) {
		$GLOBALS['test_status_header'] = $code;
	}
}

if ( ! function_exists( 'nocache_headers' ) ) {
	function nocache_headers() {
	}
}
