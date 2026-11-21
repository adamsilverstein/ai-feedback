<?php
/**
 * Removes the plugin's settings and personal data when the plugin is deleted.
 *
 * Notes and their meta are kept. See AI_Feedback\Uninstaller.
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// The plugin itself is not loaded on uninstall, so load its classes here.
spl_autoload_register(
	static function ( string $class_name ): void {
		if ( ! str_starts_with( $class_name, __NAMESPACE__ . '\\' ) ) {
			return;
		}
		$file = __DIR__ . '/includes/class-' . strtolower( str_replace( '_', '-', substr( $class_name, strlen( __NAMESPACE__ ) + 1 ) ) ) . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

Uninstaller::uninstall();
