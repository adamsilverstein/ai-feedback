<?php
/**
 * Tests that reviews follow the reviewing user's house style rules.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\House_Style;
use AI_Feedback\Review_Service;
use WP_Error;

/**
 * Captures the review prompt and stops the review there.
 */
class HouseStyleReviewService extends Review_Service {

	/**
	 * Prompt sent to the AI.
	 *
	 * @var string
	 */
	public string $prompt = '';

	/**
	 * Record the prompt and fail the call.
	 *
	 * @param  string $prompt             User prompt.
	 * @param  string $system_instruction System instruction.
	 * @param  string $model              Model to use.
	 * @param  int    $max_retries        Maximum number of retry attempts.
	 * @param  array  $images             Images to attach.
	 * @return string|WP_Error Always an error.
	 */
	protected function call_ai_with_retry(
		string $prompt,
		string $system_instruction,
		string $model,
		int $max_retries = 3,
		array $images = array()
	): string|WP_Error {
		$this->prompt = $prompt;
		return new WP_Error( 'invalid_request', 'Bad request.' );
	}
}

/**
 * House style in review prompts.
 */
class ReviewServiceHouseStyleTest extends TestCase {

	/**
	 * Clean up globals.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['test_options'], $GLOBALS['test_user_meta'], $GLOBALS['test_current_user_id'] );
	}

	/**
	 * The prompt carries the site rules and the reviewing user's own.
	 */
	public function test_review_prompt_includes_site_and_personal_rules(): void {
		if ( defined( 'AI_FEEDBACK_MOCK_MODE' ) && AI_FEEDBACK_MOCK_MODE ) {
			$this->markTestSkipped( 'Mock mode never sends a prompt.' );
		}

		$GLOBALS['test_current_user_id'] = 7;
		$GLOBALS['test_options']         = array(
			House_Style::SITE_OPTION => array(
				array(
					'id'   => 's1',
					'text' => 'Use the Oxford comma',
				),
			),
		);
		$GLOBALS['test_user_meta']       = array(
			7 => array(
				House_Style::user_meta_key() => array(
					array(
						'id'   => 'u1',
						'text' => 'Headings use sentence case',
					),
				),
			),
			8 => array(
				House_Style::user_meta_key() => array(
					array(
						'id'   => 'u2',
						'text' => 'Someone else rule',
					),
				),
			),
		);

		$service = new HouseStyleReviewService();
		$service->review_document(
			1,
			array(
				'post_title' => 'House style',
				'blocks'     => array(
					array(
						'clientId' => 'block-1',
						'name'     => 'core/heading',
						'content'  => 'How We Ship Releases',
					),
				),
			)
		);

		$this->assertStringContainsString( '- "Use the Oxford comma"', $service->prompt );
		$this->assertStringContainsString( '- "Headings use sentence case"', $service->prompt );
		$this->assertStringNotContainsString( 'Someone else rule', $service->prompt );
	}
}
