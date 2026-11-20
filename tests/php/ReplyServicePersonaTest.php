<?php
/**
 * Tests for replies continuing in the persona of the note they answer.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Notes_Manager;
use AI_Feedback\Personas;
use AI_Feedback\Prompt_Builder;
use AI_Feedback\Reply_Service;

require_once dirname( __DIR__, 2 ) . '/includes/class-reply-cron-dispatcher.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-reply-service.php';
require_once __DIR__ . '/reply-service-mocks.php';
require_once __DIR__ . '/reply-cron-mocks.php';

/**
 * Reply service that captures the prompt instead of calling the AI.
 */
class Persona_Reply_Service_Stub extends Reply_Service {

	/**
	 * Prompt sent to the AI.
	 *
	 * @var string
	 */
	public string $captured_prompt = '';

	/**
	 * Capture the prompt and return a canned reply.
	 *
	 * @param  string $prompt             Prompt.
	 * @param  string $system_instruction System instruction.
	 * @param  string $model              Model.
	 * @return string|\WP_Error Reply.
	 */
	protected function call_ai( string $prompt, string $system_instruction, string $model ): string|\WP_Error {
		$this->captured_prompt = $prompt;
		return 'Still need the benchmark link.';
	}
}

/**
 * Notes manager that records the saved reply.
 */
class Persona_Reply_Notes_Manager extends Notes_Manager {

	/**
	 * Review data of the saved reply.
	 *
	 * @var array<mixed>
	 */
	public array $review_data = array();

	/**
	 * Record the reply.
	 *
	 * @param  array<mixed> $feedback_item Feedback item.
	 * @param  int          $post_id       Post ID.
	 * @param  int          $parent_id     Parent note ID.
	 * @param  array<mixed> $review_data   Review data.
	 * @return int|\WP_Error Note ID.
	 */
	public function add_reply_to_thread( array $feedback_item, int $post_id, int $parent_id, array $review_data = array() ): int|\WP_Error {
		$this->review_data = $review_data;
		return 999;
	}
}

/**
 * Test cases for persona replies.
 */
class ReplyServicePersonaTest extends TestCase {

	/**
	 * Store a note and a user reply to it.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['test_comments']     = array(
			100 => (object) array(
				'comment_ID'      => 100,
				'comment_content' => '"40% faster" - compared to what?',
				'comment_author'  => 'Fresh Eyes',
			),
			200 => (object) array(
				'comment_ID'      => 200,
				'comment_content' => 'Than the last release.',
				'comment_author'  => 'Jane',
				'comment_parent'  => 100,
			),
		);
		$GLOBALS['test_comment_meta'] = array( 100 => array( 'ai_feedback' => '1' ) );
		$GLOBALS['test_options']      = array();
	}

	/**
	 * Clean up globals.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['test_comments'], $GLOBALS['test_comment_meta'], $GLOBALS['test_options'] );
		parent::tearDown();
	}

	/**
	 * Answer the user reply.
	 *
	 * @param  Persona_Reply_Notes_Manager $notes Notes manager.
	 * @return Persona_Reply_Service_Stub Service, with the captured prompt.
	 */
	private function answer( Persona_Reply_Notes_Manager $notes ): Persona_Reply_Service_Stub {
		$service = new Persona_Reply_Service_Stub( new Prompt_Builder(), $notes );
		$this->assertSame( 999, $service->handle_reply( 200, 100, 42, 'block-1' ) );
		return $service;
	}

	/**
	 * A reply to a persona note continues in that persona's voice, and the
	 * AI answer is tagged with the same persona.
	 */
	public function test_reply_continues_in_note_persona(): void {
		$GLOBALS['test_comment_meta'][100][ Personas::META_KEY ] = 'skeptical_expert';
		$notes = new Persona_Reply_Notes_Manager();

		$service = $this->answer( $notes );

		$this->assertStringContainsString( 'You gave the original feedback as this reader: Skeptical expert - ', $service->captured_prompt );
		$this->assertSame( 'skeptical_expert', $notes->review_data['persona'] ?? null );
	}

	/**
	 * A reply to a default note has no persona.
	 */
	public function test_reply_to_default_note_has_no_persona(): void {
		$notes = new Persona_Reply_Notes_Manager();

		$service = $this->answer( $notes );

		$this->assertStringNotContainsString( 'this reader', $service->captured_prompt );
		$this->assertSame( '', $notes->review_data['persona'] ?? null );
	}
}
