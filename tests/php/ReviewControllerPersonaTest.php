<?php
/**
 * Tests for the persona and audience arguments of the REST endpoints.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Personas;
use AI_Feedback\Review_Controller;
use AI_Feedback\Settings_Controller;
use ReflectionMethod;
use WP_REST_Request;

require_once dirname( __DIR__, 2 ) . '/includes/class-review-controller.php';

/**
 * Test cases for the REST arguments.
 */
class ReviewControllerPersonaTest extends TestCase {

	/**
	 * Get a controller's argument definitions.
	 *
	 * @param  object $controller Controller.
	 * @param  string $method     Private method that returns the arguments.
	 * @return array<string, array<string, mixed>> Arguments.
	 */
	private function args( object $controller, string $method ): array {
		/** @var array<string, array<string, mixed>> $args */
		$args = ( new ReflectionMethod( $controller, $method ) )->invoke( $controller );
		return $args;
	}

	/**
	 * Run an argument's validate callback the way the REST server does. A
	 * false result is answered with a 400 `rest_invalid_param`.
	 *
	 * @param  array<string, mixed> $arg   Argument definition.
	 * @param  mixed                $value Value sent.
	 * @param  string               $key   Argument name.
	 * @return mixed Callback result.
	 */
	private function validate( array $arg, mixed $value, string $key ): mixed {
		$this->assertArrayHasKey( 'validate_callback', $arg, $key . ' must be validated' );
		$this->assertIsCallable( $arg['validate_callback'] );
		return call_user_func( $arg['validate_callback'], $value, new WP_REST_Request(), $key );
	}

	/**
	 * A review request with an unknown persona fails validation (400).
	 */
	public function test_review_rejects_unknown_persona(): void {
		$arg = $this->args( new Review_Controller(), 'get_create_review_args' )['persona'];

		$this->assertFalse( $this->validate( $arg, 'nobody', 'persona' ) );
		$this->assertFalse( $this->validate( $arg, array( 'busy_skimmer' ), 'persona' ) );
		$this->assertTrue( $this->validate( $arg, 'busy_skimmer', 'persona' ) );
		$this->assertTrue( $this->validate( $arg, Personas::DEFAULT_PERSONA, 'persona' ) );
		$this->assertSame( Personas::DEFAULT_PERSONA, $arg['default'] );
	}

	/**
	 * The audience is capped by the schema and sanitized before use.
	 */
	public function test_review_audience_is_capped_and_sanitized(): void {
		$arg = $this->args( new Review_Controller(), 'get_create_review_args' )['audience'];

		$this->assertSame( Personas::MAX_AUDIENCE_LENGTH, $arg['maxLength'] );
		$this->assertIsCallable( $arg['sanitize_callback'] );
		$this->assertSame( 'plugin developers', call_user_func( $arg['sanitize_callback'], "<b>plugin</b>\ndevelopers" ) );
	}

	/**
	 * Saving an unknown persona as a preference fails validation (400).
	 */
	public function test_settings_reject_unknown_persona(): void {
		$args = $this->args( new Settings_Controller(), 'get_update_settings_args' );

		$this->assertFalse( $this->validate( $args['default_persona'], 'nobody', 'default_persona' ) );
		$this->assertTrue( $this->validate( $args['default_persona'], 'first_time_visitor', 'default_persona' ) );
		$this->assertSame( Personas::MAX_AUDIENCE_LENGTH, $args['review_audience']['maxLength'] );
	}
}
