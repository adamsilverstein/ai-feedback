<?php
/**
 * Requirements Notice
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Explains an unmet requirement on the Plugins and Updates screens.
 *
 * Keep this class and the main plugin file to syntax PHP 7.4 can parse,
 * otherwise the PHP version notice cannot load on the sites that need it.
 */
class Requirements_Notice {

	/**
	 * Minimum supported WordPress version.
	 *
	 * @var string
	 */
	const MIN_WP_VERSION = '7.0';

	/**
	 * Minimum supported PHP version.
	 *
	 * @var string
	 */
	const MIN_PHP_VERSION = '8.1';

	/**
	 * Screens the notice is limited to.
	 *
	 * @var string[]
	 */
	const SCREENS = array( 'plugins', 'plugins-network', 'update-core', 'update-core-network' );

	/**
	 * Find the first requirement the site does not meet.
	 *
	 * @param string $wp_version          Current WordPress version.
	 * @param string $php_version         Current PHP version.
	 * @param bool   $ai_client_available Whether the core AI Client is loaded.
	 * @return string 'wp', 'php', 'ai_client', or an empty string when all are met.
	 */
	public static function get_unmet_requirement( string $wp_version, string $php_version, bool $ai_client_available ): string {
		if ( version_compare( $wp_version, self::MIN_WP_VERSION, '<' ) ) {
			return 'wp';
		}

		if ( version_compare( $php_version, self::MIN_PHP_VERSION, '<' ) ) {
			return 'php';
		}

		return $ai_client_available ? '' : 'ai_client';
	}

	/**
	 * Show the notice for an unmet requirement.
	 *
	 * The network admin fires its own notices hook in place of `admin_notices`.
	 *
	 * @param string $requirement Requirement key from get_unmet_requirement().
	 */
	public static function register( string $requirement ): void {
		if ( '' === $requirement ) {
			return;
		}

		$render = static function () use ( $requirement ): void {
			self::render( $requirement );
		};

		add_action( 'admin_notices', $render );
		add_action( 'network_admin_notices', $render );
	}

	/**
	 * Whether the current user can resolve the requirement from this screen.
	 *
	 * @param string $requirement Requirement key from get_unmet_requirement().
	 * @return bool
	 */
	public static function should_display( string $requirement ): bool {
		if ( '' === $requirement ) {
			return false;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof \WP_Screen || ! in_array( $screen->id, self::SCREENS, true ) ) {
			return false;
		}

		return current_user_can( 'php' === $requirement ? 'update_php' : 'update_core' );
	}

	/**
	 * Build the notice message, including a link to the fix.
	 *
	 * @param string $requirement Requirement key from get_unmet_requirement().
	 * @return string Message HTML, or an empty string for an unknown key.
	 */
	public static function get_message( string $requirement ): string {
		switch ( $requirement ) {
			case 'wp':
				return sprintf(
					/* translators: 1: required WordPress version, 2: URL of the WordPress updates screen */
					__( 'Copydesk AI requires WordPress %1$s or higher. <a href="%2$s">Update WordPress</a> to use this plugin.', 'copydesk-ai' ),
					self::MIN_WP_VERSION,
					esc_url( network_admin_url( 'update-core.php' ) )
				);

			case 'php':
				return sprintf(
					/* translators: 1: required PHP version, 2: URL of the Update PHP help page */
					__( 'Copydesk AI requires PHP %1$s or higher. <a href="%2$s">Learn more about updating PHP</a>.', 'copydesk-ai' ),
					self::MIN_PHP_VERSION,
					esc_url( wp_get_update_php_url() )
				);

			case 'ai_client':
				return sprintf(
					/* translators: %s: URL of the WordPress updates screen */
					__( 'Copydesk AI requires the WordPress core AI Client (introduced in WordPress 7.0). <a href="%s">Update WordPress</a> to use this plugin.', 'copydesk-ai' ),
					esc_url( network_admin_url( 'update-core.php' ) )
				);
		}

		return '';
	}

	/**
	 * Print the notice.
	 *
	 * Prints the markup directly because wp_admin_notice() needs WordPress 6.4,
	 * and this can run on an older version.
	 *
	 * @param string $requirement Requirement key from get_unmet_requirement().
	 */
	public static function render( string $requirement ): void {
		if ( ! self::should_display( $requirement ) ) {
			return;
		}

		$message = self::get_message( $requirement );
		if ( '' === $message ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			wp_kses( $message, array( 'a' => array( 'href' => true ) ) )
		);
	}
}
