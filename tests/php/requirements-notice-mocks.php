<?php
/**
 * WordPress function mocks for RequirementsNoticeTest.
 *
 * @package AI_Feedback\Tests
 */

if ( ! class_exists( 'WP_Screen' ) ) {
	/**
	 * Minimal WP_Screen stand-in.
	 */
	class WP_Screen {

		/**
		 * Screen ID.
		 *
		 * @var string
		 */
		public string $id = '';
	}
}

if ( ! function_exists( 'get_current_screen' ) ) {
	/**
	 * Mock get_current_screen; set $GLOBALS['test_current_screen_id'] to choose the screen.
	 *
	 * @return WP_Screen|null Screen stand-in, or null when no screen is set.
	 */
	function get_current_screen(): ?WP_Screen {
		$id = $GLOBALS['test_current_screen_id'] ?? null;
		if ( ! is_string( $id ) ) {
			return null;
		}

		$screen     = new WP_Screen();
		$screen->id = $id;
		return $screen;
	}
}

if ( ! function_exists( 'network_admin_url' ) ) {
	/**
	 * Mock network_admin_url.
	 *
	 * @param string $path Path relative to the admin URL.
	 * @return string Admin URL.
	 */
	function network_admin_url( string $path = '' ): string {
		return 'http://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'wp_get_update_php_url' ) ) {
	/**
	 * Mock wp_get_update_php_url.
	 *
	 * @return string Update PHP help URL.
	 */
	function wp_get_update_php_url(): string {
		return 'https://wordpress.org/support/update-php/';
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Mock esc_url.
	 *
	 * @param string $url URL to escape.
	 * @return string Escaped URL.
	 */
	function esc_url( string $url ): string {
		return htmlspecialchars( $url, ENT_QUOTES );
	}
}
