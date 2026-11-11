<?php
/**
 * Tests for Reply_Service::call_ai() against a fake core AI Client.
 *
 * Runs in separate processes because the fake client defines global
 * wp_ai_client_prompt(), add_filter() and remove_filter() functions.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use AI_Feedback\Reply_Service;
use WP_Error;

require_once dirname( __DIR__, 2 ) . '/includes/class-reply-service.php';

/**
 * Exposes call_ai() without the parent constructor's dependencies.
 */
class CallAiReplyService extends Reply_Service {

	/**
	 * Skip parent dependencies.
	 */
	public function __construct() {}

	/**
	 * Expose call_ai().
	 *
	 * @param  string $prompt             User prompt.
	 * @param  string $system_instruction System instruction.
	 * @param  string $model              Model to use.
	 * @return string|WP_Error Response.
	 */
	public function public_call_ai( string $prompt, string $system_instruction, string $model ): string|WP_Error {
		return $this->call_ai( $prompt, $system_instruction, $model );
	}
}

/**
 * Test cases for Reply_Service::call_ai().
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class ReplyServiceCallAiTest extends TestCase {

	/**
	 * Define the fake core AI Client and filter functions.
	 *
	 * @param mixed $result Value generate_text() returns, or a Throwable it throws.
	 */
	private function fake_ai_client( mixed $result ): void {
		$GLOBALS['fake_ai_result']  = $result;
		$GLOBALS['fake_filters']    = array();
		$GLOBALS['fake_ai_builder'] = null;

		if ( ! function_exists( 'add_filter' ) ) {
			require __DIR__ . '/fixtures/fake-ai-client.php';
		}
	}

	/**
	 * Generated text is returned and the reply options are applied.
	 */
	public function test_returns_generated_text_and_applies_options(): void {
		$this->fake_ai_client( 'Reply text' );

		$result = ( new CallAiReplyService() )->public_call_ai( 'Prompt', 'System', 'model-a' );

		$this->assertSame( 'Reply text', $result );
		$calls = $GLOBALS['fake_ai_builder']->calls;
		$this->assertSame( 'Prompt', $calls['prompt'] );
		$this->assertSame( 'System', $calls['using_system_instruction'] );
		$this->assertSame( array( 'model-a' ), $calls['using_model_preference'] );
		$this->assertSame( Reply_Service::AI_MAX_TOKENS, $calls['using_max_tokens'] );
		$this->assertSame( Reply_Service::AI_TEMPERATURE, $calls['using_temperature'] );
	}

	/**
	 * A WP_Error from the builder is returned unchanged.
	 */
	public function test_returns_builder_wp_error_unchanged(): void {
		$error = new WP_Error( 'rate_limit_exceeded', 'Slow down.' );
		$this->fake_ai_client( $error );

		$result = ( new CallAiReplyService() )->public_call_ai( 'Prompt', 'System', 'model-a' );

		$this->assertSame( $error, $result );
	}

	/**
	 * A throw becomes a generic ai_request_failed WP_Error, and the timeout
	 * filter is scoped to the call.
	 */
	public function test_converts_throwable_and_scopes_timeout(): void {
		$this->fake_ai_client( new \RuntimeException( 'Connection reset' ) );

		$result = ( new CallAiReplyService() )->public_call_ai( 'Prompt', 'System', 'model-a' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ai_request_failed', $result->get_error_code() );
		$this->assertStringNotContainsString( 'Connection reset', $result->get_error_message() );
		$this->assertSame( Reply_Service::AI_HTTP_TIMEOUT, $GLOBALS['fake_ai_builder']->timeout_during_call );
		$this->assertEmpty( $GLOBALS['fake_filters']['wp_ai_client_default_request_timeout'] ?? array() );
	}

	/**
	 * Mock mode returns the canned reply without touching the AI Client.
	 */
	public function test_mock_mode_returns_mock_reply_without_calling_ai(): void {
		$this->fake_ai_client( 'Real reply' );
		define( 'AI_FEEDBACK_MOCK_MODE', true );

		$result = ( new CallAiReplyService() )->public_call_ai( 'Prompt', 'System', 'model-a' );

		$this->assertSame( Reply_Service::MOCK_REPLY, $result );
		$this->assertNull( $GLOBALS['fake_ai_builder'] );
	}
}
