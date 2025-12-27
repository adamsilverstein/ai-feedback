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
	 * AI Feedback comment author name.
	 *
	 * @var string
	 */
	private const AI_FEEDBACK_AUTHOR = 'AI Feedback';

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
	 * Review controller instance.
	 *
	 * @var Review_Controller|null
	 */
	private ?Review_Controller $review_controller = null;

	/**
	 * Notes controller instance.
	 *
	 * @var Notes_Controller|null
	 */
	private ?Notes_Controller $notes_controller = null;

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
	 * Get AI Feedback author name.
	 *
	 * @return string The AI Feedback comment author name.
	 */
	public static function get_ai_feedback_author(): string {
		return self::AI_FEEDBACK_AUTHOR;
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

		// Add custom avatar for AI Feedback comments/notes.
		add_filter( 'pre_get_avatar_data', array( $this, 'filter_ai_feedback_avatar' ), 10, 2 );
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

		$this->review_controller = new Review_Controller();
		$this->review_controller->register_routes();

		$this->notes_controller = new Notes_Controller();
		$this->notes_controller->register_routes();
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

		$asset = include $asset_file;

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

	/**
	 * Filter avatar data to show custom avatar for AI Feedback comments/notes.
	 *
	 * @param array $args        Arguments for getting avatar data.
	 * @param mixed $id_or_email The Gravatar to retrieve. Accepts a user ID, Gravatar MD5 hash,
	 *                           user email, WP_User object, WP_Post object, or WP_Comment object.
	 * @return array Modified avatar args with custom URL for AI Feedback.
	 */
	public function filter_ai_feedback_avatar( array $args, $id_or_email ): array {
		// Check if this is a comment object.
		if ( ! $id_or_email instanceof \WP_Comment ) {
			return $args;
		}

		$comment = $id_or_email;

		// Check if this is an AI Feedback comment.
		// Method 1: Check the comment author name.
		$is_ai_feedback = ( self::AI_FEEDBACK_AUTHOR === $comment->comment_author );

		// Method 2: Check comment meta for ai_feedback flag.
		if ( ! $is_ai_feedback && $comment->comment_ID ) {
			$ai_feedback_meta = get_comment_meta( (int) $comment->comment_ID, 'ai_feedback', true );
			$is_ai_feedback   = ( '1' === $ai_feedback_meta );
		}

		// If this is an AI Feedback comment, use our custom avatar.
		if ( $is_ai_feedback ) {
			$avatar_path = AI_FEEDBACK_PLUGIN_DIR . 'assets/ai-feedback-avatar.svg';
			if ( file_exists( $avatar_path ) ) {
				$avatar_url = AI_FEEDBACK_PLUGIN_URL . 'assets/ai-feedback-avatar.svg';

				$args['url']          = $avatar_url;
				$args['found_avatar'] = true;
			}
		}

		return $args;
	}
}
