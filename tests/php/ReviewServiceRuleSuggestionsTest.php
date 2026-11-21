<?php
/**
 * Tests that rule suggestions go through the same AI call as reviews.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\House_Style;
use AI_Feedback\Review_Service;
use WP_Error;

require_once __DIR__ . '/house-style-mocks.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-house-style-suggestions.php';

/**
 * Records the AI call and answers with a set response.
 */
class RuleSuggestionsReviewService extends Review_Service {

	/**
	 * Arguments of each AI call.
	 *
	 * @var list<array{prompt: string, system: string, model: string, images: array<mixed>}>
	 */
	public array $calls = array();

	/**
	 * What the AI answers.
	 *
	 * @var string|WP_Error
	 */
	public string|WP_Error $response = '';

	/**
	 * Record the call and return the set response.
	 *
	 * @param  string       $prompt             User prompt.
	 * @param  string       $system_instruction System instruction.
	 * @param  string       $model              Model to use.
	 * @param  int          $max_retries        Maximum number of retry attempts.
	 * @param  array<mixed> $images             Images to attach.
	 * @return string|WP_Error The set response.
	 */
	protected function call_ai_with_retry(
		string $prompt,
		string $system_instruction,
		string $model,
		int $max_retries = 3,
		array $images = array()
	): string|WP_Error {
		$this->calls[] = array(
			'prompt' => $prompt,
			'system' => $system_instruction,
			'model'  => $model,
			'images' => $images,
		);
		return $this->response;
	}
}

/**
 * Review_Service::suggest_house_rules().
 */
class ReviewServiceRuleSuggestionsTest extends TestCase {

	/**
	 * ID of the acting user.
	 */
	private const USER_ID = 7;

	/**
	 * Service under test.
	 *
	 * @var RuleSuggestionsReviewService
	 */
	private RuleSuggestionsReviewService $service;

	/**
	 * Seed a site rule and a personal rule.
	 */
	protected function setUp(): void {
		if ( defined( 'AI_FEEDBACK_MOCK_MODE' ) && AI_FEEDBACK_MOCK_MODE ) {
			$this->markTestSkipped( 'Mock mode never sends a prompt.' );
		}

		$GLOBALS['test_current_user_id'] = self::USER_ID;
		$GLOBALS['test_options']         = array(
			House_Style::SITE_OPTION => array(
				array(
					'id'   => 's1',
					'text' => 'Use the Oxford comma',
				),
			),
		);
		$GLOBALS['test_user_meta']       = array(
			self::USER_ID => array(
				House_Style::user_meta_key() => array(
					array(
						'id'   => 'u1',
						'text' => 'Headings use sentence case',
					),
				),
			),
		);
		$this->service                   = new RuleSuggestionsReviewService();
	}

	/**
	 * Clean up globals.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['test_options'], $GLOBALS['test_user_meta'], $GLOBALS['test_current_user_id'] );
	}

	/**
	 * Posts to suggest from.
	 *
	 * @return list<array{title: string, text: string}>
	 */
	private static function posts(): array {
		return array(
			array(
				'title' => 'How we ship',
				'text'  => 'We ship every week.',
			),
		);
	}

	/**
	 * One request goes out through the call reviews use, with the chosen
	 * model and the posts, and the rules read from it come back.
	 */
	public function test_asks_the_chosen_model_once_and_returns_the_rules(): void {
		$this->service->response = '{"rules": ["Spell out numbers under ten", "Write WordPress with a capital P"]}';

		$rules = $this->service->suggest_house_rules( self::posts(), 'some-model' );

		$this->assertSame( array( 'Spell out numbers under ten', 'Write WordPress with a capital P' ), $rules );
		$this->assertCount( 1, $this->service->calls );
		$this->assertSame( 'some-model', $this->service->calls[0]['model'] );
		$this->assertSame( array(), $this->service->calls[0]['images'] );
		$this->assertStringContainsString( "POST 1: How we ship\nWe ship every week.", $this->service->calls[0]['prompt'] );
		$this->assertStringContainsString( '- "Use the Oxford comma"', $this->service->calls[0]['prompt'] );
		$this->assertStringContainsString( '- "Headings use sentence case"', $this->service->calls[0]['prompt'] );
		$this->assertNotSame( '', $this->service->calls[0]['system'] );
	}

	/**
	 * Rules the site or the user already has are dropped, and nothing is saved.
	 */
	public function test_drops_existing_rules_and_saves_nothing(): void {
		$before                  = array( $GLOBALS['test_options'], $GLOBALS['test_user_meta'] );
		$this->service->response = '{"rules": ["use the oxford comma.", "Headings use sentence case", "Spell out numbers under ten"]}';

		$rules = $this->service->suggest_house_rules( self::posts(), 'some-model' );

		$this->assertSame( array( 'Spell out numbers under ten' ), $rules );
		$this->assertSame( $before, array( $GLOBALS['test_options'], $GLOBALS['test_user_meta'] ) );
	}

	/**
	 * A response that can't be read is an error, not an empty list.
	 */
	public function test_unreadable_response_is_an_error(): void {
		$this->service->response = 'Your posts look consistent.';

		$result = $this->service->suggest_house_rules( self::posts(), 'some-model' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_rule_suggestions', $result->get_error_code() );
	}

	/**
	 * An outage comes back as the error reviews use for one.
	 */
	public function test_outage_is_reported_as_service_unavailable(): void {
		$this->service->response = new WP_Error( 'prompt_network_error', 'cURL error 28', array( 'status' => 503 ) );

		$result = $this->service->suggest_house_rules( self::posts(), 'some-model' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ai_service_unavailable', $result->get_error_code() );
	}

	/**
	 * Any other AI error is returned as it is.
	 */
	public function test_other_errors_pass_through(): void {
		$this->service->response = new WP_Error( 'prompt_client_error', 'Invalid API key', array( 'status' => 401 ) );

		$result = $this->service->suggest_house_rules( self::posts(), 'some-model' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'prompt_client_error', $result->get_error_code() );
	}

	/**
	 * Without posts no request is made.
	 */
	public function test_no_posts_makes_no_request(): void {
		$result = $this->service->suggest_house_rules( array(), 'some-model' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'house_style_no_posts', $result->get_error_code() );
		$this->assertSame( array(), $this->service->calls );
	}
}
