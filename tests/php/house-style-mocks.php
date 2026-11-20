<?php
/**
 * WordPress stand-ins for the house style tests.
 *
 * Comment helpers come from the shared reply mocks, so every test sees the
 * same versions whichever file loads first.
 *
 * @package AI_Feedback\Tests
 */

// phpcs:disable

require_once __DIR__ . '/reply-cron-mocks.php';
require_once __DIR__ . '/reply-status-controller-bootstrap.php';

if ( ! function_exists( 'delete_user_meta' ) ) {
	function delete_user_meta( $user_id, $key, $value = '' ) {
		unset( $GLOBALS['test_user_meta'][ $user_id ][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id() {
		return $GLOBALS['test_blog_id'] ?? 1;
	}
}

if ( ! function_exists( 'get_blogs_of_user' ) ) {
	function get_blogs_of_user( $user_id, $all = false ) {
		return $GLOBALS['test_user_blogs'][ $user_id ] ?? array();
	}
}

/**
 * wpdb stand-in that gives each site its own table prefix, like core:
 * `wp_` for the main site and `wp_{id}_` for the others.
 */
class House_Style_Test_WPDB {
	public function get_blog_prefix( $blog_id = null ) {
		$blog_id = $blog_id ?? ( $GLOBALS['test_blog_id'] ?? 1 );
		return 1 === (int) $blog_id ? 'wp_' : 'wp_' . (int) $blog_id . '_';
	}
}
