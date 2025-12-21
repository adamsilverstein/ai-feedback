<?php
/**
 * Plugin Name: AI Feedback
 * Plugin URI: https://github.com/yourusername/ai-feedback
 * Description: AI-powered editorial feedback in the Gutenberg editor using WordPress 6.9's Notes feature.
 * Version: 0.1.0
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Author: Adam Silverstein
 * Author URI: https://wordpress.org/profiles/adamsilverstein/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-feedback
 * Domain Path: /languages
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'AI_FEEDBACK_VERSION', '0.1.0' );
define( 'AI_FEEDBACK_PLUGIN_FILE', __FILE__ );
define( 'AI_FEEDBACK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AI_FEEDBACK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AI_FEEDBACK_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Require Composer autoloader.
if ( file_exists( AI_FEEDBACK_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once AI_FEEDBACK_PLUGIN_DIR . 'vendor/autoload.php';
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
			require_once $path;
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
function init() {
	// Check WordPress version.
	if ( version_compare( get_bloginfo( 'version' ), '6.9', '<' ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\display_version_notice' );
		return;
	}

	// Check PHP version.
	if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\display_php_version_notice' );
		return;
	}

	// Initialize the plugin.
	Plugin::get_instance();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\init' );

/**
 * Display WordPress version notice.
 */
function display_version_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				/* translators: %s: required WordPress version */
				esc_html__( 'AI Feedback requires WordPress %s or higher.', 'ai-feedback' ),
				'6.9'
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Display PHP version notice.
 */
function display_php_version_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				/* translators: %s: required PHP version */
				esc_html__( 'AI Feedback requires PHP %s or higher.', 'ai-feedback' ),
				'8.0'
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Plugin activation hook.
 */
function activate() {
	// Set default options.
	add_option( 'ai_feedback_default_model', 'claude-sonnet-4' );
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
function deactivate() {
	// Flush rewrite rules.
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );

require_once plugin_dir_path( __FILE__ ) . 'includes/class-logger.php';
