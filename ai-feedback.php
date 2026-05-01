<?php
/**
 * Plugin Name: AI Feedback
 * Plugin URI: https://github.com/adamsilverstein/ai-feedback
 * Description: AI-powered editorial feedback in the Gutenberg editor using WordPress 7.0's core AI Client and Notes feature.
 * Version: 0.2.0
 * Requires at least: 7.0
 * Requires PHP: 8.1
 * Author: Adam Silverstein
 * Author URI: https://wordpress.org/profiles/adamsilverstein/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-feedback
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

// Exit if accessed directly.
if (! defined('ABSPATH') ) {
    exit;
}

// Define plugin constants.
define('AI_FEEDBACK_VERSION', '0.2.0');
define('AI_FEEDBACK_PLUGIN_FILE', __FILE__);
define('AI_FEEDBACK_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AI_FEEDBACK_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AI_FEEDBACK_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Require Composer autoloader.
if (file_exists(AI_FEEDBACK_PLUGIN_DIR . 'vendor/autoload.php') ) {
    include_once AI_FEEDBACK_PLUGIN_DIR . 'vendor/autoload.php';
}

// Simple autoloader for plugin classes.
spl_autoload_register(
    function ( $class ) {
        // Check if this is our namespace.
        if (strpos($class, 'AI_Feedback\\') !== 0 ) {
            return;
        }

        // Remove namespace prefix.
        $class = str_replace('AI_Feedback\\', '', $class);

        // Convert class name to file name.
        $file = 'class-' . strtolower(str_replace('_', '-', $class)) . '.php';

        // Build full file path.
        $path = AI_FEEDBACK_PLUGIN_DIR . 'includes/' . $file;

        // Require the file if it exists.
        if (file_exists($path) ) {
            include_once $path;
        }
    }
);

// Enable mock mode for testing without AI API calls.
// Set this to true in wp-config.php: define( 'AI_FEEDBACK_MOCK_MODE', true );
if (! defined('AI_FEEDBACK_MOCK_MODE') ) {
    define('AI_FEEDBACK_MOCK_MODE', false);
}

/**
 * Initialize the plugin.
 */
function init()
{
    // Check WordPress version.
    if (version_compare(get_bloginfo('version'), '7.0', '<') ) {
        add_action('admin_notices', __NAMESPACE__ . '\\display_version_notice');
        return;
    }

    // Check PHP version.
    if (version_compare(PHP_VERSION, '8.1', '<') ) {
        add_action('admin_notices', __NAMESPACE__ . '\\display_php_version_notice');
        return;
    }

    // Verify the core AI Client is available (introduced in WordPress 7.0).
    if (! function_exists('wp_ai_client_prompt') ) {
        add_action('admin_notices', __NAMESPACE__ . '\\display_ai_client_notice');
        return;
    }

    // Initialize the plugin.
    Plugin::get_instance();
}
add_action('plugins_loaded', __NAMESPACE__ . '\\init');

/**
 * Display WordPress version notice.
 */
function display_version_notice()
{
    ?>
    <div class="notice notice-error">
        <p>
    <?php
    printf(
                /* translators: %s: required WordPress version */
        esc_html__('AI Feedback requires WordPress %s or higher.', 'ai-feedback'),
        '7.0'
    );
    ?>
        </p>
    </div>
    <?php
}

/**
 * Display PHP version notice.
 */
function display_php_version_notice()
{
    ?>
    <div class="notice notice-error">
        <p>
    <?php
    printf(
                /* translators: %s: required PHP version */
        esc_html__('AI Feedback requires PHP %s or higher.', 'ai-feedback'),
        '8.1'
    );
    ?>
        </p>
    </div>
    <?php
}

/**
 * Display core AI Client unavailable notice.
 */
function display_ai_client_notice()
{
    ?>
    <div class="notice notice-error">
        <p>
    <?php
    printf(
        /* translators: %s: URL of the WordPress updates screen */
        wp_kses(
            __( 'AI Feedback requires the WordPress core AI Client (introduced in WordPress 7.0). <a href="%s">Update WordPress</a> to use this plugin.', 'ai-feedback' ),
            array( 'a' => array( 'href' => array() ) )
        ),
        esc_url(admin_url('update-core.php'))
    );
    ?>
        </p>
    </div>
    <?php
}

/**
 * Plugin activation hook.
 */
function activate()
{
    // Set default options.
    add_option('ai_feedback_default_model', 'claude-sonnet-4');
    add_option('ai_feedback_default_focus_areas', array( 'content', 'tone', 'flow' ));
    add_option('ai_feedback_default_tone', 'professional');
    add_option('ai_feedback_version', AI_FEEDBACK_VERSION);

    // Flush rewrite rules.
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, __NAMESPACE__ . '\\activate');

/**
 * Plugin deactivation hook.
 */
function deactivate()
{
    // Flush rewrite rules.
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\deactivate');

require_once plugin_dir_path(__FILE__) . 'includes/class-logger.php';
