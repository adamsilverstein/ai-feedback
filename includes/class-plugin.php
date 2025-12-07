<?php
/**
 * Main Plugin Class
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

/**
 * Main plugin bootstrap class.
 */
class Plugin {

	/**
	 * Plugin instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Settings controller instance.
	 *
	 * @var Settings_Controller|null
	 */
	private ?Settings_Controller $settings_controller = null;

	/**
	 * Get plugin instance.
	 *
	 * @return Plugin
	 */
	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
	}

	/**
	 * Load plugin textdomain.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'ai-feedback',
			false,
			dirname( AI_FEEDBACK_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Register REST API routes.
	 */
	public function register_rest_routes(): void {
		$this->settings_controller = new Settings_Controller();
		$this->settings_controller->register_routes();
	}

	/**
	 * Enqueue editor assets.
	 */
	public function enqueue_editor_assets(): void {
		// Only enqueue in the block editor.
		if ( ! $this->is_block_editor() ) {
			return;
		}

		$asset_file = AI_FEEDBACK_PLUGIN_DIR . 'build/index.asset.php';

		// Check if asset file exists.
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		// Enqueue the main script.
		wp_enqueue_script(
			'ai-feedback-editor',
			AI_FEEDBACK_PLUGIN_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// Enqueue styles.
		wp_enqueue_style(
			'ai-feedback-editor',
			AI_FEEDBACK_PLUGIN_URL . 'build/index.css',
			array( 'wp-components' ),
			$asset['version']
		);

		// Localize script with data.
		wp_localize_script(
			'ai-feedback-editor',
			'aiFeedbackData',
			array(
				'restUrl'   => rest_url( 'ai-feedback/v1' ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'version'   => AI_FEEDBACK_VERSION,
				'pluginUrl' => AI_FEEDBACK_PLUGIN_URL,
			)
		);

		// Add inline script to set the store name.
		wp_add_inline_script(
			'ai-feedback-editor',
			'window.AI_FEEDBACK_STORE = "ai-feedback/store";',
			'before'
		);
	}

	/**
	 * Check if we're in the block editor.
	 *
	 * @return bool
	 */
	private function is_block_editor(): bool {
		// Check if we're on an editor screen.
		$current_screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $current_screen ) {
			return false;
		}

		// Check if the block editor is being used.
		return $current_screen->is_block_editor();
	}
}
