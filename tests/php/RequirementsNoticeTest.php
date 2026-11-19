<?php
/**
 * Tests for Requirements_Notice.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Requirements_Notice;

require_once __DIR__ . '/requirements-notice-mocks.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-requirements-notice.php';

/**
 * Test cases for Requirements_Notice.
 */
class RequirementsNoticeTest extends TestCase {

	/**
	 * Capabilities checked during the test.
	 *
	 * @var string[]
	 */
	private array $checked_caps = array();

	/**
	 * Default to an administrator on the Plugins screen.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->checked_caps                = array();
		$GLOBALS['test_added_actions']     = array();
		$GLOBALS['test_current_screen_id'] = 'plugins';
		$GLOBALS['test_current_user_can']  = function ( string $capability ): bool {
			$this->checked_caps[] = $capability;
			return true;
		};
	}

	/**
	 * Reset the globals the mocks read.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['test_current_screen_id'], $GLOBALS['test_current_user_can'], $GLOBALS['test_added_actions'] );
		parent::tearDown();
	}

	/**
	 * Capture what render() prints.
	 *
	 * @param string $requirement Requirement key.
	 * @return string Printed markup.
	 */
	private function render( string $requirement ): string {
		ob_start();
		Requirements_Notice::render( $requirement );
		return (string) ob_get_clean();
	}

	/**
	 * The first unmet requirement wins, and a met set reports nothing.
	 *
	 * @dataProvider data_unmet_requirement
	 *
	 * @param string $wp_version  WordPress version.
	 * @param string $php_version PHP version.
	 * @param bool   $ai_client   Whether the AI Client is available.
	 * @param string $expected    Expected requirement key.
	 */
	public function test_get_unmet_requirement( string $wp_version, string $php_version, bool $ai_client, string $expected ): void {
		$this->assertSame( $expected, Requirements_Notice::get_unmet_requirement( $wp_version, $php_version, $ai_client ) );
	}

	/**
	 * Data provider for test_get_unmet_requirement.
	 *
	 * @return array<string, array{string, string, bool, string}>
	 */
	public static function data_unmet_requirement(): array {
		return array(
			'all met'           => array( '7.0', '8.1.0', true, '' ),
			'newer versions'    => array( '7.1-beta1', '8.4.2', true, '' ),
			'old WordPress'     => array( '6.9.1', '8.3.0', true, 'wp' ),
			'old PHP'           => array( '7.0', '8.0.30', true, 'php' ),
			'missing AI Client' => array( '7.0', '8.1.0', false, 'ai_client' ),
			'WordPress first'   => array( '6.8', '7.4.33', false, 'wp' ),
		);
	}

	/**
	 * Each notice links to where the requirement gets fixed.
	 *
	 * @dataProvider data_messages
	 *
	 * @param string $requirement Requirement key.
	 * @param string $text        Text the notice must contain.
	 * @param string $href        Link the notice must contain.
	 */
	public function test_notice_says_how_to_resolve( string $requirement, string $text, string $href ): void {
		$output = $this->render( $requirement );

		$this->assertStringContainsString( 'class="notice notice-error"', $output );
		$this->assertStringContainsString( $text, $output );
		$this->assertStringContainsString( 'href="' . $href . '"', $output );
	}

	/**
	 * Data provider for test_notice_says_how_to_resolve.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function data_messages(): array {
		return array(
			'wp'        => array( 'wp', 'requires WordPress 7.0 or higher', 'http://example.test/wp-admin/update-core.php' ),
			'php'       => array( 'php', 'requires PHP 8.1 or higher', 'https://wordpress.org/support/update-php/' ),
			'ai_client' => array( 'ai_client', 'requires the WordPress core AI Client', 'http://example.test/wp-admin/update-core.php' ),
		);
	}

	/**
	 * The notice stays off screens where the requirement cannot be fixed.
	 *
	 * @dataProvider data_screens
	 *
	 * @param string|null $screen_id Current screen ID, or null for no screen.
	 * @param bool        $expected  Whether the notice shows.
	 */
	public function test_notice_is_limited_to_plugins_and_updates_screens( ?string $screen_id, bool $expected ): void {
		$GLOBALS['test_current_screen_id'] = $screen_id;

		$this->assertSame( $expected, Requirements_Notice::should_display( 'wp' ) );
		$this->assertSame( $expected, '' !== $this->render( 'wp' ) );
	}

	/**
	 * Data provider for test_notice_is_limited_to_plugins_and_updates_screens.
	 *
	 * @return array<string, array{string|null, bool}>
	 */
	public static function data_screens(): array {
		return array(
			'plugins'         => array( 'plugins', true ),
			'network plugins' => array( 'plugins-network', true ),
			'updates'         => array( 'update-core', true ),
			'network updates' => array( 'update-core-network', true ),
			'dashboard'       => array( 'dashboard', false ),
			'post editor'     => array( 'post', false ),
			'general options' => array( 'options-general', false ),
			'no screen'       => array( null, false ),
		);
	}

	/**
	 * Users who cannot fix the requirement do not see the notice.
	 */
	public function test_notice_is_hidden_without_capability(): void {
		$GLOBALS['test_current_user_can'] = false;

		$this->assertFalse( Requirements_Notice::should_display( 'wp' ) );
		$this->assertSame( '', $this->render( 'php' ) );
	}

	/**
	 * The capability matches the fix the notice links to.
	 *
	 * @dataProvider data_capabilities
	 *
	 * @param string $requirement Requirement key.
	 * @param string $capability  Capability expected to be checked.
	 */
	public function test_notice_checks_matching_capability( string $requirement, string $capability ): void {
		$this->assertTrue( Requirements_Notice::should_display( $requirement ) );
		$this->assertSame( array( $capability ), $this->checked_caps );
	}

	/**
	 * Data provider for test_notice_checks_matching_capability.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function data_capabilities(): array {
		return array(
			'wp'        => array( 'wp', 'update_core' ),
			'php'       => array( 'php', 'update_php' ),
			'ai_client' => array( 'ai_client', 'update_core' ),
		);
	}

	/**
	 * Nothing prints when every requirement is met or the key is unknown.
	 */
	public function test_no_notice_without_a_known_requirement(): void {
		$this->assertSame( '', $this->render( '' ) );
		$this->assertSame( '', $this->render( 'unknown' ) );
	}

	/**
	 * Hooks recorded by the add_action mock.
	 *
	 * @return array<int, array{hook: string, callback: callable}>
	 */
	private function added_actions(): array {
		$actions = array();
		foreach ( (array) ( $GLOBALS['test_added_actions'] ?? array() ) as $action ) {
			if ( is_array( $action ) && is_string( $action['hook'] ?? null ) && is_callable( $action['callback'] ?? null ) ) {
				$actions[] = array(
					'hook'     => $action['hook'],
					'callback' => $action['callback'],
				);
			}
		}
		return $actions;
	}

	/**
	 * The network admin fires its own hook, so the notice is registered on both.
	 */
	public function test_notice_is_registered_for_site_and_network_admin(): void {
		Requirements_Notice::register( 'wp' );

		$this->assertSame(
			array( 'admin_notices', 'network_admin_notices' ),
			array_column( $this->added_actions(), 'hook' )
		);
	}

	/**
	 * The registered callbacks print the notice for the requirement they were given.
	 */
	public function test_registered_callbacks_print_the_notice(): void {
		$GLOBALS['test_current_screen_id'] = 'plugins-network';
		Requirements_Notice::register( 'php' );

		$actions = $this->added_actions();
		$this->assertCount( 2, $actions );

		foreach ( $actions as $action ) {
			ob_start();
			$action['callback']();
			$this->assertStringContainsString( 'requires PHP 8.1 or higher', (string) ob_get_clean(), $action['hook'] );
		}
	}

	/**
	 * Nothing is hooked when every requirement is met.
	 */
	public function test_nothing_is_registered_when_requirements_are_met(): void {
		Requirements_Notice::register( '' );

		$this->assertSame( array(), $this->added_actions() );
	}
}
