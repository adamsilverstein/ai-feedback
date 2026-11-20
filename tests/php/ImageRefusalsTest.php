<?php
/**
 * Tests for remembering a model's refusal of images (Image_Refusals).
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use AI_Feedback\Image_Refusals;
use WP_Error;

/**
 * Test cases for Image_Refusals.
 */
class ImageRefusalsTest extends TestCase {

	/**
	 * Start with no refusals and no filters.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['test_transients']     = array();
		$GLOBALS['test_filter_results'] = array();
	}

	/**
	 * Clean up.
	 */
	protected function tearDown(): void {
		$GLOBALS['test_transients']     = array();
		$GLOBALS['test_filter_results'] = array();
		parent::tearDown();
	}

	/**
	 * A refusal is kept per provider and model, for an hour by default.
	 */
	public function test_remembers_per_provider_and_model(): void {
		Image_Refusals::remember( 'openai', 'gpt-4o' );

		$this->assertTrue( Image_Refusals::is_refused( 'openai', 'gpt-4o' ) );
		$this->assertFalse( Image_Refusals::is_refused( 'openai', 'gpt-4.1' ) );
		$this->assertFalse( Image_Refusals::is_refused( 'azure', 'gpt-4o' ) );

		$expires = $GLOBALS['test_transients'][ Image_Refusals::TRANSIENT ]['openai|gpt-4o'];
		$this->assertEqualsWithDelta( time() + HOUR_IN_SECONDS, $expires, 2 );
	}

	/**
	 * An expired refusal is forgotten, even while the transient lives on
	 * for another one.
	 */
	public function test_expired_refusal_is_forgotten(): void {
		$GLOBALS['test_transients'][ Image_Refusals::TRANSIENT ] = array(
			'openai|gpt-4o'           => time() - 1,
			'google|gemini-2.0-flash' => time() + 60,
		);

		$this->assertFalse( Image_Refusals::is_refused( 'openai', 'gpt-4o' ) );
		$this->assertTrue( Image_Refusals::is_refused( 'google', 'gemini-2.0-flash' ) );
	}

	/**
	 * The filter sets the time, 0 turns remembering off, and it is never
	 * longer than a day.
	 */
	public function test_ttl_filter(): void {
		$GLOBALS['test_filter_results'] = array( 'ai_feedback_image_refusal_ttl' => 0 );
		Image_Refusals::remember( 'openai', 'gpt-4o' );
		$this->assertFalse( Image_Refusals::is_refused( 'openai', 'gpt-4o' ) );
		$this->assertArrayNotHasKey( Image_Refusals::TRANSIENT, $GLOBALS['test_transients'] );

		$GLOBALS['test_filter_results'] = array( 'ai_feedback_image_refusal_ttl' => 10 * DAY_IN_SECONDS );
		Image_Refusals::remember( 'openai', 'gpt-4o' );
		$expires = $GLOBALS['test_transients'][ Image_Refusals::TRANSIENT ]['openai|gpt-4o'];
		$this->assertEqualsWithDelta( time() + DAY_IN_SECONDS, $expires, 2 );
	}

	/**
	 * Changing the site's model, a user's model, or a connector's key
	 * clears every refusal; other settings don't.
	 */
	public function test_cleared_when_settings_change(): void {
		$refuse = static function (): void {
			Image_Refusals::remember( 'openai', 'gpt-4o' );
		};

		$refuse();
		Image_Refusals::clear_on_option_change( 'blogname' );
		Image_Refusals::clear_on_meta_change( 1, 1, 'ai_feedback_default_tone' );
		$this->assertTrue( Image_Refusals::is_refused( 'openai', 'gpt-4o' ) );

		Image_Refusals::clear_on_option_change( 'ai_feedback_default_model' );
		$this->assertFalse( Image_Refusals::is_refused( 'openai', 'gpt-4o' ) );

		$refuse();
		Image_Refusals::clear_on_option_change( 'connectors_ai_openai_api_key' );
		$this->assertFalse( Image_Refusals::is_refused( 'openai', 'gpt-4o' ) );

		$refuse();
		Image_Refusals::clear_on_meta_change( array( 3 ), 1, 'ai_feedback_default_model' );
		$this->assertFalse( Image_Refusals::is_refused( 'openai', 'gpt-4o' ) );
	}

	/**
	 * Errors from a request with images, and whether each is remembered.
	 *
	 * @return array<string, array{0: WP_Error, 1: bool}>
	 */
	public static function errors(): array {
		$error = static fn ( string $code, string $message, int $status ): WP_Error => new WP_Error( $code, $message, array( 'status' => $status ) );
		return array(
			'core finds no model for the images' => array( new WP_Error( 'prompt_invalid_argument', 'No models found that support text_generation for this prompt.', array( 'status' => 400 ) ), true ),
			'415 media type'                     => array( $error( 'prompt_client_error', 'Unsupported Media Type', 415 ), true ),
			'400 naming images'                  => array( $error( 'prompt_client_error', 'This model does not support image input.', 400 ), true ),
			'400 naming image_url'               => array( $error( 'prompt_client_error', 'Invalid content type. image_url is only supported by certain models.', 400 ), true ),
			'422 naming modalities'              => array( $error( 'prompt_client_error', 'Input modality not supported.', 422 ), true ),
			'400 naming the media type'          => array( $error( 'prompt_client_error', 'Image does not match the provided media type.', 400 ), true ),
			'400 with an underscored code'       => array( $error( 'invalid_input_image', 'Bad request.', 400 ), true ),
			'400 underscored size code'          => array( $error( 'image_too_large', 'Bad request.', 400 ), false ),
			'400 underscored count code'         => array( $error( 'max_images_exceeded', 'Bad request.', 400 ), false ),
			'400 image too large'                => array( $error( 'prompt_client_error', 'image exceeds 5 MB maximum', 400 ), false ),
			'400 too many images'                => array( $error( 'prompt_client_error', 'Too many images in the request.', 400 ), false ),
			'422 image count limit'              => array( $error( 'prompt_client_error', 'Image count over the limit.', 422 ), false ),
			'413 too large'                      => array( $error( 'prompt_client_error', 'Request too large: images exceed the limit.', 413 ), false ),
			'unclear 400'                        => array( $error( 'prompt_client_error', 'Invalid request.', 400 ), false ),
			'other invalid argument'             => array( $error( 'prompt_invalid_argument', 'Temperature must be between 0 and 2.', 400 ), false ),
			'auth failure naming images'         => array( $error( 'prompt_client_error', 'Your key cannot send images.', 401 ), false ),
			'rate limit naming images'           => array( $error( 'prompt_client_error', 'Too many image requests.', 429 ), false ),
			'outage naming images'               => array( $error( 'prompt_upstream_server_error', 'Image service overloaded.', 503 ), false ),
			'network failure'                    => array( $error( 'prompt_network_error', 'cURL error 28: timed out', 503 ), false ),
			'no status'                          => array( new WP_Error( 'invalid_request', 'This model does not support image input.' ), false ),
		);
	}

	/**
	 * Only errors that say the model or request shape doesn't take images
	 * are remembered.
	 *
	 * @param WP_Error $error    Error from the request with images.
	 * @param bool     $expected Whether it is remembered.
	 */
	#[DataProvider( 'errors' )]
	public function test_which_errors_are_remembered( WP_Error $error, bool $expected ): void {
		$this->assertSame( $expected, Image_Refusals::is_lasting_refusal( $error ) );
	}

	/**
	 * A site can keep a refusal from being remembered.
	 */
	public function test_remember_filter(): void {
		$GLOBALS['test_filter_results'] = array( 'ai_feedback_remember_image_refusal' => 'yes' );
		$this->assertFalse( Image_Refusals::is_lasting_refusal( new WP_Error( 'x', 'Unsupported Media Type', array( 'status' => 415 ) ) ) );
	}
}
