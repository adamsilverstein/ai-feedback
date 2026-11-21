<?php
/**
 * Tests for who can update settings and where the values are saved.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Review_Images;
use AI_Feedback\Settings_Controller;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Test cases for per-user preferences and admin-only site defaults.
 */
class SettingsControllerUpdateTest extends TestCase {

	/**
	 * ID of the user making the requests.
	 */
	private const USER_ID = 7;

	/**
	 * Reset stored options, user meta and the acting user between tests.
	 */
	protected function setUp(): void {
		$GLOBALS['test_options']         = array();
		$GLOBALS['test_transients']      = array();
		$GLOBALS['test_user_meta']       = array();
		$GLOBALS['test_current_user_id'] = self::USER_ID;
	}

	/**
	 * Clean up globals.
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['test_options'],
			$GLOBALS['test_transients'],
			$GLOBALS['test_user_meta'],
			$GLOBALS['test_current_user_id'],
			$GLOBALS['test_current_user_can']
		);
	}

	/**
	 * Act as a user with only the given capabilities.
	 *
	 * @param string[] $capabilities Capabilities the user has.
	 */
	private function act_as( array $capabilities ): void {
		$GLOBALS['test_current_user_can'] = static fn ( string $cap ): bool => in_array( $cap, $capabilities, true );
	}

	/**
	 * Build an update request.
	 *
	 * @param  array<string, mixed> $params Request parameters.
	 * @return WP_REST_Request
	 */
	private function request( array $params ): WP_REST_Request {
		$request = new WP_REST_Request();
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * Read the settings the current user sees.
	 *
	 * @return array<mixed>
	 */
	private function get_settings(): array {
		$response = ( new Settings_Controller() )->get_settings( new WP_REST_Request() );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		return $data;
	}

	/**
	 * An author may save their own preferences.
	 */
	public function test_non_admin_may_update_own_preferences(): void {
		$this->act_as( array( 'edit_posts' ) );

		$result = ( new Settings_Controller() )->update_settings_permissions_check( $this->request( array( 'default_tone' => 'casual' ) ) );

		$this->assertTrue( $result );
	}

	/**
	 * An author may not change the site defaults.
	 */
	public function test_non_admin_may_not_update_site_defaults(): void {
		$this->act_as( array( 'edit_posts' ) );

		$result = ( new Settings_Controller() )->update_settings_permissions_check(
			$this->request(
				array(
					'scope'        => 'site',
					'default_tone' => 'casual',
				)
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * An administrator may change the site defaults.
	 */
	public function test_admin_may_update_site_defaults(): void {
		$this->act_as( array( 'edit_posts', 'manage_options' ) );

		$result = ( new Settings_Controller() )->update_settings_permissions_check( $this->request( array( 'scope' => 'site' ) ) );

		$this->assertTrue( $result );
	}

	/**
	 * A user who cannot edit posts may not update anything.
	 */
	public function test_subscriber_may_not_update_preferences(): void {
		$this->act_as( array() );

		$result = ( new Settings_Controller() )->update_settings_permissions_check( $this->request( array() ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * An update saves to the user's meta and leaves the site options alone.
	 */
	public function test_update_saves_user_preferences_not_site_options(): void {
		$GLOBALS['test_options'] = array( 'ai_feedback_default_tone' => 'professional' );

		$response = ( new Settings_Controller() )->update_settings(
			$this->request(
				array(
					'default_model'       => 'gpt-4o',
					'default_focus_areas' => array( 'design' ),
					'default_tone'        => 'casual',
					'feedback_locale'     => 'fr_FR',
				)
			)
		);

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( array( 'ai_feedback_default_tone' => 'professional' ), $GLOBALS['test_options'] );
		$this->assertSame(
			array(
				self::USER_ID => array(
					'ai_feedback_default_model'       => 'gpt-4o',
					'ai_feedback_default_focus_areas' => array( 'design' ),
					'ai_feedback_default_tone'        => 'casual',
					'ai_feedback_feedback_locale'     => 'fr_FR',
				),
			),
			$GLOBALS['test_user_meta']
		);

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'gpt-4o', $data['default_model'] );
		$this->assertSame( array( 'design' ), $data['default_focus_areas'] );
		$this->assertSame( 'casual', $data['default_tone'] );
		$this->assertSame( 'fr_FR', $data['feedback_locale'] );
	}

	/**
	 * A site scoped update saves to the site options and no user meta.
	 */
	public function test_site_scope_saves_site_options(): void {
		( new Settings_Controller() )->update_settings(
			$this->request(
				array(
					'scope'        => 'site',
					'default_tone' => 'academic',
				)
			)
		);

		$this->assertSame( array( 'ai_feedback_default_tone' => 'academic' ), $GLOBALS['test_options'] );
		$this->assertSame( array(), $GLOBALS['test_user_meta'] );
	}

	/**
	 * One user's preferences do not change what another user sees.
	 */
	public function test_preferences_are_per_user(): void {
		$GLOBALS['test_options'] = array( 'ai_feedback_default_tone' => 'academic' );

		( new Settings_Controller() )->update_settings( $this->request( array( 'default_tone' => 'casual' ) ) );
		$this->assertSame( 'casual', $this->get_settings()['default_tone'] );

		// Another user still gets the site default.
		$GLOBALS['test_current_user_id'] = 8;
		$this->assertSame( 'academic', $this->get_settings()['default_tone'] );
	}

	/**
	 * Without a preference or a site option, the plugin defaults are used.
	 */
	public function test_falls_back_to_plugin_defaults(): void {
		$settings = $this->get_settings();

		$this->assertSame( AI_FEEDBACK_DEFAULT_MODEL, $settings['default_model'] );
		$this->assertSame( array( 'content', 'tone', 'flow' ), $settings['default_focus_areas'] );
		$this->assertSame( 'professional', $settings['default_tone'] );
		$this->assertSame( 'auto', $settings['feedback_locale'] );
	}

	/**
	 * A retired model in the user's preferences falls back to the site default.
	 */
	public function test_retired_user_model_falls_back_to_site_default(): void {
		$GLOBALS['test_options']   = array( 'ai_feedback_default_model' => 'gpt-4o' );
		$GLOBALS['test_user_meta'] = array( self::USER_ID => array( 'ai_feedback_default_model' => 'claude-sonnet-4' ) );

		$this->assertSame( 'gpt-4o', $this->get_settings()['default_model'] );
	}

	/**
	 * A site that never saved the image setting reports it as on.
	 */
	public function test_send_images_is_on_by_default(): void {
		$this->assertTrue( $this->get_settings()['send_images'] );
	}

	/**
	 * Settings say whether the user may change the site's settings.
	 */
	public function test_settings_report_who_can_manage_the_site(): void {
		$this->act_as( array( 'edit_posts' ) );
		$this->assertFalse( $this->get_settings()['can_manage_site'] );

		$this->act_as( array( 'edit_posts', 'manage_options' ) );
		$this->assertTrue( $this->get_settings()['can_manage_site'] );
	}

	/**
	 * An author may not change the image setting, with or without a scope.
	 */
	public function test_non_admin_may_not_update_send_images(): void {
		$this->act_as( array( 'edit_posts' ) );

		$result = ( new Settings_Controller() )->update_settings_permissions_check( $this->request( array( 'send_images' => false ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * An administrator may change the image setting.
	 */
	public function test_admin_may_update_send_images(): void {
		$this->act_as( array( 'edit_posts', 'manage_options' ) );

		$result = ( new Settings_Controller() )->update_settings_permissions_check( $this->request( array( 'send_images' => false ) ) );

		$this->assertTrue( $result );
	}

	/**
	 * The image setting is saved for the site, never as a user preference,
	 * and reads back as saved.
	 */
	public function test_send_images_round_trip(): void {
		$response = ( new Settings_Controller() )->update_settings( $this->request( array( 'send_images' => false ) ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertFalse( $data['send_images'] );
		$this->assertSame( array( Review_Images::OPTION => '0' ), $GLOBALS['test_options'] );
		$this->assertSame( array(), $GLOBALS['test_user_meta'] );
		$this->assertFalse( Review_Images::is_enabled() );
		$this->assertFalse( $this->get_settings()['send_images'] );

		( new Settings_Controller() )->update_settings( $this->request( array( 'send_images' => true ) ) );

		$this->assertSame( array( Review_Images::OPTION => '1' ), $GLOBALS['test_options'] );
		$this->assertTrue( Review_Images::is_enabled() );
		$this->assertTrue( $this->get_settings()['send_images'] );
	}

	/**
	 * A value from a form or query string is read as a boolean.
	 */
	public function test_send_images_reads_string_values(): void {
		( new Settings_Controller() )->update_settings( $this->request( array( 'send_images' => 'false' ) ) );

		$this->assertSame( array( Review_Images::OPTION => '0' ), $GLOBALS['test_options'] );
	}

	/**
	 * An update without the image setting leaves it alone.
	 */
	public function test_other_updates_leave_send_images_alone(): void {
		$GLOBALS['test_options'] = array( Review_Images::OPTION => '0' );

		( new Settings_Controller() )->update_settings( $this->request( array( 'default_tone' => 'casual' ) ) );

		$this->assertSame( array( Review_Images::OPTION => '0' ), $GLOBALS['test_options'] );
	}
}
