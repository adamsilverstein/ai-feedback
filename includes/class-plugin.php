<?php
/**
 * Main Plugin Class
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


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
	 * Health controller instance.
	 *
	 * @var Health_Controller|null
	 */
	private ?Health_Controller $health_controller = null;

	/**
	 * History controller instance.
	 *
	 * @var History_Controller|null
	 */
	private ?History_Controller $history_controller = null;

	/**
	 * Analytics controller instance.
	 *
	 * @var Analytics_Controller|null
	 */
	private ?Analytics_Controller $analytics_controller = null;

	/**
	 * Reply detector instance.
	 *
	 * @var Reply_Detector|null
	 */
	private ?Reply_Detector $reply_detector = null;

	/**
	 * Reply service instance.
	 *
	 * @var Reply_Service|null
	 */
	private ?Reply_Service $reply_service = null;

	/**
	 * Reply cron dispatcher instance.
	 *
	 * @var Reply_Cron_Dispatcher|null
	 */
	private ?Reply_Cron_Dispatcher $reply_cron_dispatcher = null;

	/**
	 * Reply status controller instance.
	 *
	 * @var Reply_Status_Controller|null
	 */
	private ?Reply_Status_Controller $reply_status_controller = null;

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
		return __( 'Fresh Eyes', 'fresh-eyes' );
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
		add_action( 'init', array( Review_History::class, 'register_meta' ) );
		add_action( 'init', array( Notes_Manager::class, 'register_meta' ) );
		Personas::register();
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'ai_feedback_after_review', array( Review_History::class, 'record' ), 10, 2 );
		add_action( 'ai_feedback_after_review', array( Analytics::class, 'track_review' ), 10, 2 );
		add_action( 'ai_feedback_after_mention', array( Analytics::class, 'track_mention' ), 10, 2 );
		add_action( 'deleted_user', array( Analytics::class, 'on_deleted_user' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( Analytics::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( Analytics::class, 'register_eraser' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( House_Style::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( House_Style::class, 'register_eraser' ) );
		Image_Refusals::register();
		Analytics_Page::init();
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );

		// Show note badges from meta, before wpautop wraps them in a paragraph.
		add_filter( 'comment_text', array( Notes_Manager::class, 'add_badges_to_comment_text' ), 1, 2 );

		// Fresh Eyes' avatar for AI notes and for its user.
		add_filter( 'pre_get_avatar_data', array( Fresh_Eyes_User::class, 'filter_avatar' ), 10, 2 );

		Fresh_Eyes_User::register();

		$this->reply_detector = new Reply_Detector();
		$this->reply_detector->register();

		$this->reply_cron_dispatcher = new Reply_Cron_Dispatcher();
		$this->reply_cron_dispatcher->register();

		$this->reply_service = new Reply_Service();
		$this->reply_service->register();
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

		$this->health_controller = new Health_Controller();
		$this->health_controller->register_routes();

		$this->history_controller = new History_Controller();
		$this->history_controller->register_routes();

		$this->analytics_controller = new Analytics_Controller();
		$this->analytics_controller->register_routes();

		$this->reply_status_controller = new Reply_Status_Controller( $this->reply_service );
		$this->reply_status_controller->register_routes();

		( new House_Style_Controller() )->register_routes();
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

		wp_set_script_translations( 'ai-feedback-editor', 'fresh-eyes' );

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
				'restUrl'               => rest_url( 'ai-feedback/v1' ),
				'nonce'                 => wp_create_nonce( 'wp_rest' ),
				'version'               => AI_FEEDBACK_VERSION,
				'pluginUrl'             => AI_FEEDBACK_PLUGIN_URL,
				'connectorsUrl'         => admin_url( 'options-general.php?page=connectors' ),
				'suggestions'           => Suggestions::is_available(),
				'structuralSuggestions' => Suggestions::is_structural_available(),
				'mentionHandles'        => Mentions::get_handles(),
				'mentionUserId'         => Fresh_Eyes_User::get_id(),
				'maxStrengths'          => Response_Parser::get_max_strengths(),
				'priorityThresholds'    => array(
					'high'   => Response_Parser::PRIORITY_HIGH_THRESHOLD,
					'medium' => Response_Parser::PRIORITY_MEDIUM_THRESHOLD,
				),
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
