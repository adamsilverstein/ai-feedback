<?php
/**
 * Plugin Name: Copydesk AI
 * Plugin URI: https://github.com/adamsilverstein/copydesk-ai
 * Description: AI-powered editorial feedback in the Gutenberg editor using WordPress 7.0's core AI Client and Notes feature.
 * Version: 1.0.2
 * Requires at least: 7.0
 * Requires PHP: 8.1
 * Author: Adam Silverstein
 * Author URI: https://wordpress.org/profiles/adamsilverstein/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: copydesk-ai
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'AI_FEEDBACK_VERSION', '1.0.2' );
define( 'AI_FEEDBACK_PLUGIN_FILE', __FILE__ );
define( 'AI_FEEDBACK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AI_FEEDBACK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AI_FEEDBACK_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'AI_FEEDBACK_DEFAULT_MODEL', 'claude-sonnet-5-5' );

// Require Composer autoloader.
if ( file_exists( AI_FEEDBACK_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	include_once AI_FEEDBACK_PLUGIN_DIR . 'vendor/autoload.php';
}

// Simple autoloader for plugin classes.
spl_autoload_register(
	function ( $class ) {
		// Check if this is our namespace.
		if ( strpos( $class, 'AI_Feedback\\' ) !== 0 ) {
			return;
		}

		// Remove namespace prefix.
		$class = str_replace( 'AI_Feedback\\', '', $class );

		// Convert class name to file name.
		$file = 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';

		// Build full file path.
		$path = AI_FEEDBACK_PLUGIN_DIR . 'includes/' . $file;

		// Require the file if it exists.
		if ( file_exists( $path ) ) {
			include_once $path;
		}
	}
);

// Enable mock mode for testing without AI API calls.
// Set this to true in wp-config.php: define( 'AI_FEEDBACK_MOCK_MODE', true );
if ( ! defined( 'AI_FEEDBACK_MOCK_MODE' ) ) {
	define( 'AI_FEEDBACK_MOCK_MODE', false );
}

/**
 * Initialize the plugin.
 */
function init(): void {
	$unmet = Requirements_Notice::get_unmet_requirement(
		get_bloginfo( 'version' ),
		PHP_VERSION,
		function_exists( 'wp_ai_client_prompt' )
	);

	if ( '' !== $unmet ) {
		add_action(
			'admin_notices',
			static function () use ( $unmet ): void {
				Requirements_Notice::render( $unmet );
			}
		);
		return;
	}

	// Initialize the plugin.
	Plugin::get_instance();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\init' );

/**
 * Plugin activation hook.
 */
function activate() {
	// Set default options.
	add_option( 'ai_feedback_default_model', AI_FEEDBACK_DEFAULT_MODEL );
	add_option( 'ai_feedback_default_focus_areas', array( 'content', 'tone', 'flow' ) );
	add_option( 'ai_feedback_default_tone', 'professional' );
	add_option( 'ai_feedback_version', AI_FEEDBACK_VERSION );

	// Flush rewrite rules.
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );

/**
 * Plugin deactivation hook.
 */
function deactivate(): void {
	// Clear pending reply-generation cron events so WP-Cron does not fire a
	// hook with no listener once the plugin is disabled. The literal mirrors
	// Reply_Cron_Dispatcher::CRON_HOOK; the class may not be loaded here.
	wp_clear_scheduled_hook( 'ai_feedback_generate_reply' );

	// Flush rewrite rules.
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );

require_once plugin_dir_path( __FILE__ ) . 'includes/class-logger.php';
