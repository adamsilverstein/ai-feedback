<?php
/**
 * Tests for the reader persona and audience preferences.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Personas;
use AI_Feedback\Settings_Controller;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Test cases for persona settings.
 */
class SettingsControllerPersonaTest extends TestCase {

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
			$GLOBALS['test_filter_results']
		);
	}

	/**
	 * Save settings and return the response data.
	 *
	 * @param  array<string, mixed> $params Request parameters.
	 * @return mixed Response data, or the WP_Error.
	 */
	private function update( array $params ): mixed {
		$request = new WP_REST_Request();
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = ( new Settings_Controller() )->update_settings( $request );
		return $response instanceof WP_REST_Response ? $response->get_data() : $response;
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
	 * Settings default to the copy editor with no audience and list the personas.
	 */
	public function test_defaults(): void {
		$settings = $this->get_settings();

		$this->assertSame( 'copy_editor', $settings['default_persona'] );
		$this->assertSame( '', $settings['review_audience'] );
		$this->assertSame( Personas::get_for_rest(), $settings['available_personas'] );
	}

	/**
	 * The persona and audience persist per user.
	 */
	public function test_persona_and_audience_persist_per_user(): void {
		$data = $this->update(
			array(
				'default_persona' => 'busy_skimmer',
				'review_audience' => 'plugin developers',
			)
		);

		$this->assertIsArray( $data );
		$this->assertSame( 'busy_skimmer', $data['default_persona'] );
		$this->assertSame( 'plugin developers', $data['review_audience'] );
		$this->assertSame( 'busy_skimmer', $GLOBALS['test_user_meta'][ self::USER_ID ]['ai_feedback_default_persona'] );
		$this->assertArrayNotHasKey( 'ai_feedback_default_persona', $GLOBALS['test_options'] );

		// Another user still gets the default.
		$GLOBALS['test_current_user_id'] = 8;
		$this->assertSame( 'copy_editor', $this->get_settings()['default_persona'] );
	}

	/**
	 * An unknown persona is rejected.
	 */
	public function test_unknown_persona_is_rejected(): void {
		$result = $this->update( array( 'default_persona' => 'nobody' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_persona', $result->get_error_code() );
		$this->assertArrayNotHasKey( self::USER_ID, $GLOBALS['test_user_meta'] );
	}

	/**
	 * A saved persona a filter later removed reads as the default.
	 */
	public function test_removed_persona_reads_as_default(): void {
		$GLOBALS['test_user_meta'][ self::USER_ID ]['ai_feedback_default_persona'] = 'legal_reviewer';

		$this->assertSame( 'copy_editor', $this->get_settings()['default_persona'] );
	}

	/**
	 * The audience is capped before it is saved.
	 */
	public function test_audience_is_capped(): void {
		$this->update( array( 'review_audience' => str_repeat( 'a', 300 ) ) );

		$this->assertSame(
			str_repeat( 'a', Personas::MAX_AUDIENCE_LENGTH ),
			$GLOBALS['test_user_meta'][ self::USER_ID ]['ai_feedback_review_audience']
		);
	}
}
