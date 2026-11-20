<?php
/**
 * Tests that every AI request runs on the chosen provider and model, or
 * fails, and never moves to another (Model_Pin).
 *
 * Two fake providers stand in for core's registry: "anthropic", which the
 * plugin's default model belongs to, and "fakeother". Runs in separate
 * processes because the fake client defines global functions and core's
 * AiClient class.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use AI_Feedback\Model_Pin;
use AI_Feedback\Notes_Manager;
use AI_Feedback\Reply_Service;
use AI_Feedback\Review_Service;
use ReflectionProperty;
use WP_Error;

require_once dirname( __DIR__, 2 ) . '/includes/class-reply-service.php';

/**
 * Review_Service with call_ai() and the answering model exposed.
 */
class Pin_Review_Service extends Review_Service {

	/**
	 * Skip parent dependencies.
	 */
	public function __construct() {}

	/**
	 * Expose call_ai().
	 *
	 * @param  string $model Model ID.
	 * @return string|WP_Error Response.
	 */
	public function ask( string $model ): string|WP_Error {
		return $this->call_ai( 'Prompt', 'System', $model );
	}

	/**
	 * The provider and model that answered the last call.
	 *
	 * @return array{provider: string, model: string}|null
	 */
	public function answered(): ?array {
		return $this->answered_by;
	}

	/**
	 * Expose is_non_retryable_error().
	 *
	 * @param  WP_Error $error Error.
	 * @return bool
	 */
	public function non_retryable( WP_Error $error ): bool {
		return $this->is_non_retryable_error( $error );
	}
}

/**
 * Reply_Service with call_ai() and the answering model exposed.
 */
class Pin_Reply_Service extends Reply_Service {

	/**
	 * Skip parent dependencies.
	 */
	public function __construct() {}

	/**
	 * Expose call_ai().
	 *
	 * @param  string $model Model ID.
	 * @return string|WP_Error Response.
	 */
	public function ask( string $model ): string|WP_Error {
		return $this->call_ai( 'Prompt', 'System', $model );
	}

	/**
	 * The provider and model that answered the last call.
	 *
	 * @return array{provider: string, model: string}|null
	 */
	public function answered(): ?array {
		return $this->answered_by;
	}
}

/**
 * Notes manager that records the review data instead of creating notes.
 */
class Pin_Notes_Manager extends Notes_Manager {

	/**
	 * Review data passed in, or null when no notes were made.
	 *
	 * @var array<mixed>|null
	 */
	public ?array $review_data = null;

	/**
	 * Record the review data and skip note creation.
	 *
	 * @param  array<int, array<string, mixed>> $feedback_items Feedback items.
	 * @param  int                              $post_id        Post ID.
	 * @param  array<string, mixed>             $review_data    Review data.
	 * @return array<string, mixed>|WP_Error Always an error.
	 */
	public function create_notes_from_feedback( array $feedback_items, int $post_id, array $review_data = array() ): array|WP_Error {
		$this->review_data = $review_data;
		return new WP_Error( 'skipped', 'Notes skipped.' );
	}
}

/**
 * Test cases for Model_Pin and the services that use it.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class ModelPinTest extends TestCase {

	/**
	 * Load the fake AI client with two providers in the given order.
	 *
	 * @param list<string> $anthropic_models Models the "anthropic" provider lists.
	 * @param list<string> $other_models     Models the "fakeother" provider lists.
	 * @param bool         $other_first      Whether "fakeother" is registered first.
	 */
	private function providers( array $anthropic_models, array $other_models = array( 'other-model-1' ), bool $other_first = true ): void {
		$anthropic = array(
			'name'       => 'Anthropic',
			'configured' => true,
			'models'     => $anthropic_models,
		);
		$other     = array(
			'name'       => 'Fake Other',
			'configured' => true,
			'models'     => $other_models,
		);

		$GLOBALS['fake_providers']  = $other_first
			? array(
				'fakeother' => $other,
				'anthropic' => $anthropic,
			)
			: array(
				'anthropic' => $anthropic,
				'fakeother' => $other,
			);
		$GLOBALS['fake_ai_result']  = 'Answer';
		$GLOBALS['fake_filters']    = array();
		$GLOBALS['fake_ai_builder'] = null;

		if ( ! function_exists( 'add_filter' ) ) {
			require __DIR__ . '/fixtures/fake-ai-client.php';
		}
	}

	/**
	 * Both registration orders, since core falls back to the first provider.
	 *
	 * @return array<string, array{bool}>
	 */
	public static function provider_orders(): array {
		return array(
			'other provider first'  => array( true ),
			'chosen provider first' => array( false ),
		);
	}

	/**
	 * Assert an error came back before any request was sent.
	 *
	 * @param mixed  $result Result of call_ai().
	 * @param string $code   Expected error code.
	 */
	private function assertNotSent( mixed $result, string $code ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertArrayNotHasKey( 'generate_text_result', $GLOBALS['fake_ai_builder']->calls ?? array() );
		$this->assertArrayNotHasKey( 'using_model', $GLOBALS['fake_ai_builder']->calls ?? array() );
	}

	/**
	 * When only the other provider lists the model, a review fails and
	 * names the model and provider, and nothing is sent anywhere.
	 *
	 * @param bool $other_first Whether the other provider is registered first.
	 */
	#[DataProvider( 'provider_orders' )]
	public function test_review_fails_when_only_another_provider_lists_the_model( bool $other_first ): void {
		$this->providers( array( 'claude-haiku-fake' ), array( 'claude-sonnet-5-5', 'other-model-1' ), $other_first );

		$service = new Pin_Review_Service();
		$result  = $service->ask( 'claude-sonnet-5-5' );

		$this->assertNotSent( $result, Model_Pin::ERROR_MODEL_UNAVAILABLE );
		$this->assertStringContainsString( 'Claude Sonnet 5.5 (claude-sonnet-5-5)', $result->get_error_message() );
		$this->assertStringContainsString( 'not available from Anthropic', $result->get_error_message() );
		$this->assertNull( $service->answered() );
		$this->assertTrue( $service->non_retryable( $result ) );
		$this->assertFalse( $service->is_service_unavailable_error( $result ) );
	}

	/**
	 * A thread reply or mention answer fails the same way.
	 *
	 * @param bool $other_first Whether the other provider is registered first.
	 */
	#[DataProvider( 'provider_orders' )]
	public function test_reply_fails_when_only_another_provider_lists_the_model( bool $other_first ): void {
		$this->providers( array( 'claude-haiku-fake' ), array( 'claude-sonnet-5-5' ), $other_first );

		$service = new Pin_Reply_Service();

		$this->assertNotSent( $service->ask( 'claude-sonnet-5-5' ), Model_Pin::ERROR_MODEL_UNAVAILABLE );
		$this->assertNull( $service->answered() );
	}

	/**
	 * A listed model is pinned, and the provider and model that answered
	 * are the ones requested.
	 *
	 * @param bool $other_first Whether the other provider is registered first.
	 */
	#[DataProvider( 'provider_orders' )]
	public function test_review_runs_on_the_chosen_model( bool $other_first ): void {
		$this->providers( array( 'claude-haiku-fake', 'claude-sonnet-5-5' ), array( 'claude-sonnet-5-5' ), $other_first );

		$service = new Pin_Review_Service();

		$this->assertSame( 'Answer', $service->ask( 'claude-sonnet-5-5' ) );
		$pinned = $GLOBALS['fake_ai_builder']->calls['using_model'];
		$this->assertSame( array( 'anthropic', 'claude-sonnet-5-5' ), array( $pinned->provider_id, $pinned->model_id ) );
		$this->assertSame(
			array(
				'provider' => 'anthropic',
				'model'    => 'claude-sonnet-5-5',
			),
			$service->answered()
		);
	}

	/**
	 * A thread reply or mention answer runs on the chosen model too.
	 */
	public function test_reply_runs_on_the_chosen_model(): void {
		$this->providers( array( 'claude-sonnet-5-5' ), array( 'claude-sonnet-5-5' ) );

		$service = new Pin_Reply_Service();

		$this->assertSame( 'Answer', $service->ask( 'claude-sonnet-5-5' ) );
		$this->assertSame(
			array(
				'provider' => 'anthropic',
				'model'    => 'claude-sonnet-5-5',
			),
			$service->answered()
		);
	}

	/**
	 * When the provider's model list can't be read, nothing is sent, and
	 * the error is a temporary one the editor offers to retry.
	 */
	public function test_unreadable_model_list_sends_nothing(): void {
		$this->providers( array( 'claude-sonnet-5-5' ), array( 'claude-sonnet-5-5' ) );
		$GLOBALS['fake_providers']['anthropic']['fails'] = true;

		$review = new Pin_Review_Service();
		$result = $review->ask( 'claude-sonnet-5-5' );

		$this->assertNotSent( $result, Model_Pin::ERROR_MODEL_CHECK_FAILED );
		$this->assertStringContainsString( 'could not confirm that Claude Sonnet 5.5 is available from Anthropic', $result->get_error_message() );
		$this->assertSame( 503, $result->get_error_data()['status'] );
		$this->assertFalse( $review->non_retryable( $result ) );
		$this->assertTrue( $review->is_service_unavailable_error( $result ) );

		$this->assertNotSent( ( new Pin_Reply_Service() )->ask( 'claude-sonnet-5-5' ), Model_Pin::ERROR_MODEL_CHECK_FAILED );
	}

	/**
	 * A provider that isn't set up fails, rather than another one answering.
	 */
	public function test_unconfigured_provider_fails(): void {
		$this->providers( array( 'claude-sonnet-5-5' ), array( 'claude-sonnet-5-5' ) );
		$GLOBALS['fake_providers']['anthropic']['configured'] = false;

		$result = ( new Pin_Review_Service() )->ask( 'claude-sonnet-5-5' );

		$this->assertNotSent( $result, Model_Pin::ERROR_PROVIDER_UNAVAILABLE );
		$this->assertStringContainsString( 'Settings > Connectors', $result->get_error_message() );
	}

	/**
	 * A provider that lists only dated IDs runs the newest dated release of
	 * the model, and not a different model that shares its prefix.
	 */
	public function test_short_name_runs_on_the_dated_release(): void {
		$this->providers( array( 'claude-opus-4-1-20250805', 'claude-opus-4-20250514', 'claude-opus-4-20250101' ) );

		$service = new Pin_Review_Service();

		$this->assertSame( 'Answer', $service->ask( 'claude-opus-4' ) );
		$this->assertSame(
			array(
				'provider' => 'anthropic',
				'model'    => 'claude-opus-4-20250514',
			),
			$service->answered()
		);
	}

	/**
	 * Dated, `-latest` and `models/` IDs match their short name; other
	 * models never do.
	 *
	 * @return array<string, array{list<string>, string, string|null}>
	 */
	public static function listed_ids(): array {
		return array(
			'exact ID wins'          => array( array( 'gpt-4o-2024-08-06', 'gpt-4o' ), 'gpt-4o', 'gpt-4o' ),
			'ISO dated'              => array( array( 'gpt-4o-mini', 'gpt-4o-2024-05-13', 'gpt-4o-2024-08-06' ), 'gpt-4o', 'gpt-4o-2024-08-06' ),
			'latest'                 => array( array( 'claude-opus-4-latest' ), 'claude-opus-4', 'claude-opus-4-latest' ),
			'models/ prefix'         => array( array( 'models/gemini-2.0-flash' ), 'gemini-2.0-flash', 'models/gemini-2.0-flash' ),
			'other model, no match'  => array( array( 'gpt-4o-mini', 'claude-opus-4-1-20250805' ), 'claude-opus-4', null ),
			'longer name, no match'  => array( array( 'gemini-2.0-flash-lite' ), 'gemini-2.0-flash', null ),
		);
	}

	/**
	 * Which listed ID a model ID runs as.
	 *
	 * @param list<string> $listed   IDs the provider lists.
	 * @param string       $model    Model ID.
	 * @param string|null  $expected Expected listed ID, or null for none.
	 */
	#[DataProvider( 'listed_ids' )]
	public function test_listed_id_matching( array $listed, string $model, ?string $expected ): void {
		$this->providers( $listed );
		$GLOBALS['test_filter_results']['ai_feedback_available_models'] = array(
			array(
				'id'       => $model,
				'name'     => $model,
				'provider' => 'anthropic',
			),
		);

		$result = ( new Model_Pin() )->resolve( $model );

		if ( null === $expected ) {
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( Model_Pin::ERROR_MODEL_UNAVAILABLE, $result->get_error_code() );
			return;
		}
		$this->assertIsArray( $result );
		$this->assertSame( $expected, $result['model_id'] );
		$this->assertSame( 'anthropic', $result['provider'] );
	}

	/**
	 * A site can map a model to a listed ID the matching misses, but not to
	 * one the provider doesn't list.
	 */
	public function test_filter_maps_to_a_listed_id_only(): void {
		$this->providers( array( 'claude-opus-4-0' ) );

		$GLOBALS['test_filter_results']['ai_feedback_provider_model_id'] = 'claude-opus-4-0';
		$result = ( new Model_Pin() )->resolve( 'claude-opus-4' );
		$this->assertIsArray( $result );
		$this->assertSame( 'claude-opus-4-0', $result['model_id'] );

		$GLOBALS['test_filter_results']['ai_feedback_provider_model_id'] = 'other-model-1';
		$result = ( new Model_Pin() )->resolve( 'claude-opus-4' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * A model added by a filter without a provider runs on the provider that
	 * lists its exact ID, and fails when none does.
	 */
	public function test_model_without_provider(): void {
		$this->providers( array( 'claude-sonnet-5-5' ), array( 'custom-model' ) );
		$GLOBALS['test_filter_results']['ai_feedback_available_models'] = array(
			array(
				'id'   => 'custom-model',
				'name' => 'Custom',
			),
		);

		$result = ( new Model_Pin() )->resolve( 'custom-model' );
		$this->assertIsArray( $result );
		$this->assertSame( 'fakeother', $result['provider'] );

		$result = ( new Model_Pin() )->resolve( 'missing-model' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( Model_Pin::ERROR_MODEL_UNAVAILABLE, $result->get_error_code() );
	}

	/**
	 * An answer from another provider is never used.
	 */
	public function test_answer_from_another_provider_is_refused(): void {
		$this->providers( array( 'claude-sonnet-5-5' ) );
		$GLOBALS['fake_ai_answered_by'] = array( 'fakeother', 'other-model-1' );

		$service = new Pin_Review_Service();
		$result  = $service->ask( 'claude-sonnet-5-5' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( Model_Pin::ERROR_WRONG_PROVIDER, $result->get_error_code() );
		$this->assertNull( $service->answered() );
		$this->assertTrue( $service->non_retryable( $result ) );
	}

	/**
	 * Run a full review through the fake client.
	 *
	 * @param  string            $model Model ID.
	 * @param  Pin_Notes_Manager $notes Notes manager.
	 * @return array<mixed>|WP_Error Review result.
	 */
	private function review( string $model, Pin_Notes_Manager $notes ): array|WP_Error {
		define( 'AI_FEEDBACK_MOCK_MODE', false );
		$GLOBALS['fake_ai_result'] = '{"summary": "Reads well.", "feedback": []}';

		$service = new Review_Service();
		( new ReflectionProperty( Review_Service::class, 'notes_manager' ) )->setValue( $service, $notes );

		return $service->review_document(
			1,
			array(
				'model'      => $model,
				'post_title' => 'Pinning',
				'blocks'     => array(
					array(
						'clientId' => 'block-1',
						'name'     => 'core/paragraph',
						'content'  => 'Our plugin is fast.',
					),
				),
			)
		);
	}

	/**
	 * A review records the provider and model that answered, in its result
	 * and in the review data its notes are saved with.
	 */
	public function test_review_records_the_model_that_answered(): void {
		$this->providers( array( 'claude-opus-4-20250514' ), array( 'claude-opus-4' ) );
		$notes = new Pin_Notes_Manager();

		$result = $this->review( 'claude-opus-4', $notes );

		$expected = array(
			'provider' => 'anthropic',
			'model'    => 'claude-opus-4-20250514',
		);
		$this->assertIsArray( $result );
		$this->assertSame( 'claude-opus-4', $result['model'] );
		$this->assertSame( $expected, $result['answered_by'] );
		$this->assertSame( $expected, $notes->review_data['answered_by'] ?? null );
	}

	/**
	 * A review on an unavailable model returns the error and makes no notes.
	 */
	public function test_review_on_unavailable_model_makes_no_notes(): void {
		$this->providers( array( 'claude-haiku-fake' ), array( 'claude-sonnet-5-5' ) );
		$notes = new Pin_Notes_Manager();

		$result = $this->review( 'claude-sonnet-5-5', $notes );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( Model_Pin::ERROR_MODEL_UNAVAILABLE, $result->get_error_code() );
		$this->assertNull( $notes->review_data );
		$this->assertArrayNotHasKey( 'generate_text_result', $GLOBALS['fake_ai_builder']->calls );
	}

	/**
	 * An unavailable model isn't mistaken for a refused image, so a review
	 * with images isn't sent again without them.
	 */
	public function test_unavailable_model_is_not_a_refused_input(): void {
		$this->providers( array() );
		$method = new \ReflectionMethod( Review_Service::class, 'is_refused_input' );

		foreach ( array( Model_Pin::ERROR_MODEL_UNAVAILABLE, Model_Pin::ERROR_PROVIDER_UNAVAILABLE ) as $code ) {
			$this->assertFalse( $method->invoke( null, new WP_Error( $code, 'Vision model not available.', array( 'status' => 400 ) ) ) );
		}
		$this->assertTrue( $method->invoke( null, new WP_Error( 'prompt_invalid_argument', 'Bad image.', array( 'status' => 400 ) ) ) );
	}
}
