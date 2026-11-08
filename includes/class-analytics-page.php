<?php
/**
 * Analytics Admin Page
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

/**
 * Registers the Settings > AI Feedback Analytics screen.
 */
class Analytics_Page {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	const SLUG = 'ai-feedback-analytics';

	/**
	 * Hook suffix returned by add_options_page().
	 *
	 * @var string
	 */
	private static string $hook_suffix = '';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
	}

	/**
	 * Add the page under Settings.
	 */
	public static function add_page(): void {
		$hook = add_options_page(
			__( 'AI Feedback Analytics', 'ai-feedback' ),
			__( 'AI Feedback Analytics', 'ai-feedback' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);

		self::$hook_suffix = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Render the page container; the dashboard is a React app.
	 */
	public static function render(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Feedback Analytics', 'ai-feedback' ); ?></h1>
			<div id="ai-feedback-analytics-root"></div>
		</div>
		<?php
	}

	/**
	 * Enqueue the dashboard script on this page only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( '' === self::$hook_suffix || $hook_suffix !== self::$hook_suffix ) {
			return;
		}

		$plugin_file = dirname( __DIR__ ) . '/ai-feedback.php';
		$asset_file  = dirname( __DIR__ ) . '/build/analytics.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = include $asset_file;
		if ( ! is_array( $asset ) ) {
			return;
		}

		$dependencies = is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array();
		$version      = is_string( $asset['version'] ?? null ) ? $asset['version'] : false;

		wp_enqueue_script(
			'ai-feedback-analytics',
			plugins_url( 'build/analytics.js', $plugin_file ),
			array_filter( $dependencies, 'is_string' ),
			$version,
			true
		);
		wp_set_script_translations( 'ai-feedback-analytics', 'ai-feedback' );

		wp_enqueue_style(
			'ai-feedback-analytics',
			plugins_url( 'build/analytics.css', $plugin_file ),
			array( 'wp-components' ),
			$version
		);
	}
}
