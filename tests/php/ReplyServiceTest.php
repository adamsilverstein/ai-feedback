<?php
/**
 * Tests for Reply_Service.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Reply_Service;
use AI_Feedback\Prompt_Builder;
use AI_Feedback\Notes_Manager;

require_once dirname( __DIR__, 2 ) . '/includes/class-reply-cron-dispatcher.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-reply-service.php';
require_once __DIR__ . '/reply-service-mocks.php';
require_once __DIR__ . '/reply-cron-mocks.php';

/**
 * Subclass that captures the prompt and returns a canned AI reply.
 */
class Reply_Service_Stub extends Reply_Service {

	public string $captured_prompt             = '';
	public string $captured_system_instruction = '';
	public string $captured_model              = '';
	public string $canned_response             = 'OK — replace "40% growth" with "40% growth (Source: Stripe dashboard)".';

	protected function call_ai( string $prompt, string $system_instruction, string $model ): string|\WP_Error {
		$this->captured_prompt             = $prompt;
		$this->captured_system_instruction = $system_instruction;
		$this->captured_model              = $model;
		return $this->canned_response;
	}
}

/**
 * Fake Notes_Manager that records the add_reply_to_thread call.
 */
class Reply_Service_Fake_Notes_Manager extends Notes_Manager {

	public array $last_reply = array();

	public function add_reply_to_thread( array $feedback_item, int $post_id, int $parent_id, array $review_data = array() ): int|\WP_Error {
		$this->last_reply = array(
			'feedback_item' => $feedback_item,
			'post_id'       => $post_id,
			'parent_id'     => $parent_id,
			'review_data'   => $review_data,
		);
		return 999;
	}
}

/**
 * Reply_Service test cases.
 */
class ReplyServiceTest extends TestCase {

	/**
	 * Reset the fake-WP state before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['test_comments']           = array();
		$GLOBALS['test_comment_meta']       = array();
		$GLOBALS['test_options']            = array();
		$GLOBALS['test_post_content']       = array();
		$GLOBALS['test_parsed_blocks']      = array();
		$GLOBALS['test_parse_blocks_calls'] = 0;
	}

	/**
	 * Tear down state.
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['test_comments'],
			$GLOBALS['test_comment_meta'],
			$GLOBALS['test_options'],
			$GLOBALS['test_post_content'],
			$GLOBALS['test_parsed_blocks'],
			$GLOBALS['test_parse_blocks_calls'],
			$GLOBALS['test_missing_posts']
		);
		parent::tearDown();
	}

	/**
	 * The reply uses the model its author picked, over the site default.
	 */
	public function test_handle_reply_uses_reply_authors_model_preference(): void {
		$GLOBALS['test_comments'] = array(
			100 => (object) array(
				'comment_ID'      => 100,
				'comment_content' => 'Add a source.',
				'comment_author'  => 'AI Feedback',
			),
			200 => (object) array(
				'comment_ID'      => 200,
				'comment_content' => 'Which one?',
				'comment_author'  => 'Jane',
				'comment_parent'  => 100,
				'user_id'         => '7',
			),
		);

		$GLOBALS['test_options']   = array( 'ai_feedback_default_model' => 'claude-sonnet-4' );
		$GLOBALS['test_user_meta'] = array( 7 => array( 'ai_feedback_default_model' => 'gpt-4o' ) );

		$service = new Reply_Service_Stub( new Prompt_Builder(), new Reply_Service_Fake_Notes_Manager() );
		$service->handle_reply( 200, 100, 42, 'abc-123' );
		unset( $GLOBALS['test_user_meta'] );

		$this->assertSame( 'gpt-4o', $service->captured_model );
	}

	/**
	 * End-to-end: context built, prompt generated, AI reply persisted.
	 */
	public function test_handle_reply_persists_ai_reply_as_thread_child(): void {
		// Parent: AI note on a paragraph block with original feedback.
		$GLOBALS['test_comments'][100] = (object) array(
			'comment_ID'      => 100,
			'comment_content' => 'Add a source for the 40% growth claim.',
			'comment_author'  => 'AI Feedback',
		);
		$GLOBALS['test_comment_meta'][100] = array(
			'ai_feedback'        => '1',
			'block_name'         => 'core/paragraph',
			'feedback_category'  => 'content',
			'feedback_severity'  => 'important',
		);

		// Reply: user asking for clarification.
		$GLOBALS['test_comments'][200] = (object) array(
			'comment_ID'      => 200,
			'comment_content' => 'I already cite it in paragraph 3.',
			'comment_author'  => 'Jane',
		);

		// Prior thread entry (an earlier user comment, chronologically first).
		$GLOBALS['test_comments'][150] = (object) array(
			'comment_ID'      => 150,
			'comment_content' => 'Which stat?',
			'comment_author'  => 'Jane',
			'comment_parent'  => 100,
		);
		$GLOBALS['test_comments'][200]->comment_parent = 100;

		$GLOBALS['test_options']['ai_feedback_default_model'] = 'claude-sonnet-4';
		$GLOBALS['test_options']['ai_feedback_default_tone']  = 'professional';

		$fake_notes = new Reply_Service_Fake_Notes_Manager();
		$service    = new Reply_Service_Stub( new Prompt_Builder(), $fake_notes );

		$result = $service->handle_reply( 200, 100, 42, 'abc-123' );

		// Returns the fake Notes_Manager's stubbed insert ID.
		$this->assertSame( 999, $result );

		// The prompt carried the user reply, original feedback, and prior thread entry.
		$this->assertStringContainsString( 'I already cite it in paragraph 3.', $service->captured_prompt );
		$this->assertStringContainsString( 'Add a source for the 40% growth claim.', $service->captured_prompt );
		$this->assertStringContainsString( 'Which stat?', $service->captured_prompt );
		$this->assertStringContainsString( '[content/important]', $service->captured_prompt );

		// Model + system instruction routed through.
		$this->assertSame( 'claude-sonnet-4', $service->captured_model );
		$this->assertStringContainsString( '"edit": null', $service->captured_system_instruction );

		// Persisted as a child of the parent note with the canned AI reply.
		$this->assertSame( 100, $fake_notes->last_reply['parent_id'] );
		$this->assertSame( 42, $fake_notes->last_reply['post_id'] );
		$this->assertSame( $service->canned_response, $fake_notes->last_reply['feedback_item']['feedback'] );
		$this->assertSame( 'abc-123', $fake_notes->last_reply['feedback_item']['block_id'] );
		$this->assertSame( 'claude-sonnet-4', $fake_notes->last_reply['review_data']['model'] );
	}

	/**
	 * The user's own reply is excluded from the thread history so the
	 * prompt doesn't echo them back to themselves.
	 */
	public function test_thread_history_excludes_the_current_reply(): void {
		$GLOBALS['test_comments'][100] = (object) array(
			'comment_ID'      => 100,
			'comment_content' => 'Vague claim.',
			'comment_author'  => 'AI Feedback',
		);
		$GLOBALS['test_comment_meta'][100] = array( 'ai_feedback' => '1' );

		// The reply itself is the only sibling of the parent.
		$GLOBALS['test_comments'][200] = (object) array(
			'comment_ID'      => 200,
			'comment_content' => 'SHOULD_NOT_APPEAR_IN_THREAD',
			'comment_author'  => 'Jane',
			'comment_parent'  => 100,
		);

		$service = new Reply_Service_Stub( new Prompt_Builder(), new Reply_Service_Fake_Notes_Manager() );

		$service->handle_reply( 200, 100, 42, 'abc-123' );

		// The reply appears in the NEW USER REPLY section but not the thread history.
		$this->assertStringContainsString( 'NEW USER REPLY:', $service->captured_prompt );
		$this->assertStringContainsString( 'SHOULD_NOT_APPEAR_IN_THREAD', $service->captured_prompt );

		$before_reply_section = substr(
			$service->captured_prompt,
			0,
			strpos( $service->captured_prompt, 'NEW USER REPLY:' )
		);

		// The sentinel text from the reply should not appear in any pre-NEW-USER-REPLY section.
		$this->assertStringNotContainsString(
			'SHOULD_NOT_APPEAR_IN_THREAD',
			$before_reply_section
		);
	}

	/**
	 * Missing parent surfaces a WP_Error instead of crashing.
	 */
	public function test_missing_parent_returns_wp_error(): void {
		$GLOBALS['test_comments'][200] = (object) array(
			'comment_ID'      => 200,
			'comment_content' => 'Reply text',
			'comment_author'  => 'Jane',
		);

		$service = new Reply_Service_Stub( new Prompt_Builder(), new Reply_Service_Fake_Notes_Manager() );

		$result = $service->handle_reply( 200, 999, 42, 'abc-123' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'parent_not_found', $result->get_error_code() );
	}

	/**
	 * Missing reply comment surfaces a WP_Error.
	 */
	public function test_missing_reply_returns_wp_error(): void {
		$GLOBALS['test_comments'][100] = (object) array(
			'comment_ID'      => 100,
			'comment_content' => 'Original feedback.',
			'comment_author'  => 'AI Feedback',
		);

		$service = new Reply_Service_Stub( new Prompt_Builder(), new Reply_Service_Fake_Notes_Manager() );

		$result = $service->handle_reply( 999, 100, 42, 'abc-123' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'reply_not_found', $result->get_error_code() );
	}

	/**
	 * AI failure propagates as a WP_Error and the reply is not persisted.
	 */
	public function test_ai_failure_returns_wp_error_and_skips_persistence(): void {
		$GLOBALS['test_comments'][100] = (object) array(
			'comment_ID'      => 100,
			'comment_content' => 'Original feedback.',
			'comment_author'  => 'AI Feedback',
		);
		$GLOBALS['test_comment_meta'][100] = array( 'ai_feedback' => '1' );
		$GLOBALS['test_comments'][200]     = (object) array(
			'comment_ID'      => 200,
			'comment_content' => 'User reply.',
			'comment_author'  => 'Jane',
			'comment_parent'  => 100,
		);

		$fake_notes = new Reply_Service_Fake_Notes_Manager();
		$service    = new class( new Prompt_Builder(), $fake_notes ) extends Reply_Service {
			protected function call_ai( string $prompt, string $system_instruction, string $model ): string|\WP_Error {
				return new \WP_Error( 'ai_request_failed', 'simulated provider failure' );
			}
		};

		$result = $service->handle_reply( 200, 100, 42, 'abc-123' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_request_failed', $result->get_error_code() );
		$this->assertSame( array(), $fake_notes->last_reply, 'Notes_Manager must not be called when the AI fails.' );
	}

	/**
	 * on_reply_received records 'complete' status meta and the resulting AI
	 * reply comment ID when handle_reply succeeds.
	 */
	public function test_on_reply_received_records_complete_status_on_success(): void {
		$GLOBALS['test_comments'][100] = (object) array(
			'comment_ID'      => 100,
			'comment_content' => 'Vague.',
			'comment_author'  => 'AI Feedback',
		);
		$GLOBALS['test_comment_meta'][100] = array( 'ai_feedback' => '1' );
		$GLOBALS['test_comments'][200]     = (object) array(
			'comment_ID'      => 200,
			'comment_content' => 'Intentional.',
			'comment_author'  => 'Jane',
			'comment_parent'  => 100,
		);

		$service = new Reply_Service_Stub( new Prompt_Builder(), new Reply_Service_Fake_Notes_Manager() );

		// Cron only answers a reply that is still pending.
		$GLOBALS['test_comment_meta'][200][ \AI_Feedback\Reply_Cron_Dispatcher::STATUS_META_KEY ] = 'pending';

		$service->on_reply_received( 200, 100, 42, 'abc-123' );

		$this->assertSame(
			'complete',
			$GLOBALS['test_comment_meta'][200][ \AI_Feedback\Reply_Cron_Dispatcher::STATUS_META_KEY ]
		);
		$this->assertSame(
			999,
			$GLOBALS['test_comment_meta'][200]['ai_feedback_reply_comment_id']
		);
	}

	/**
	 * on_reply_received records 'failed' status with the error message when
	 * handle_reply returns WP_Error (e.g. missing parent).
	 */
	public function test_on_reply_received_records_failed_status_on_error(): void {
		$GLOBALS['test_comments'][200] = (object) array(
			'comment_ID'      => 200,
			'comment_content' => 'Reply without parent.',
			'comment_author'  => 'Jane',
		);

		$service = new Reply_Service_Stub( new Prompt_Builder(), new Reply_Service_Fake_Notes_Manager() );

		// Cron only answers a reply that is still pending.
		$GLOBALS['test_comment_meta'][200][ \AI_Feedback\Reply_Cron_Dispatcher::STATUS_META_KEY ] = 'pending';

		$service->on_reply_received( 200, 999, 42, 'abc-123' );

		$this->assertSame(
			'failed',
			$GLOBALS['test_comment_meta'][200][ \AI_Feedback\Reply_Cron_Dispatcher::STATUS_META_KEY ]
		);
		$this->assertNotEmpty(
			$GLOBALS['test_comment_meta'][200]['ai_feedback_reply_error'] ?? null
		);
	}

	/**
	 * If handle_reply returns a non-error, non-positive value (e.g. 0 from a
	 * silent wp_insert_comment failure), on_reply_received must not mark the
	 * reply 'complete' — that would leave the frontend polling forever on a
	 * non-existent comment ID.
	 */
	public function test_on_reply_received_records_failed_status_when_persistence_returns_zero(): void {
		$GLOBALS['test_comments'][100] = (object) array(
			'comment_ID'      => 100,
			'comment_content' => 'Original feedback.',
			'comment_author'  => 'AI Feedback',
		);
		$GLOBALS['test_comment_meta'][100] = array( 'ai_feedback' => '1' );
		$GLOBALS['test_comments'][200]     = (object) array(
			'comment_ID'      => 200,
			'comment_content' => 'User reply.',
			'comment_author'  => 'Jane',
			'comment_parent'  => 100,
		);

		$silent_failure_notes = new class() extends Notes_Manager {
			public function add_reply_to_thread( array $feedback_item, int $post_id, int $parent_id, array $review_data = array() ): int|\WP_Error {
				return 0;
			}
		};

		$service = new Reply_Service_Stub( new Prompt_Builder(), $silent_failure_notes );

		// Cron only answers a reply that is still pending.
		$GLOBALS['test_comment_meta'][200][ \AI_Feedback\Reply_Cron_Dispatcher::STATUS_META_KEY ] = 'pending';

		$service->on_reply_received( 200, 100, 42, 'abc-123' );

		$this->assertSame(
			'failed',
			$GLOBALS['test_comment_meta'][200][ \AI_Feedback\Reply_Cron_Dispatcher::STATUS_META_KEY ]
		);
		$this->assertArrayNotHasKey(
			'ai_feedback_reply_comment_id',
			$GLOBALS['test_comment_meta'][200]
		);
	}

	/**
	 * Seed a parent AI note (ID 100) and the user reply (ID 999) under it.
	 */
	private function seed_parent_and_reply(): void {
		$GLOBALS['test_comments'][100]     = (object) array(
			'comment_ID'      => 100,
			'comment_content' => 'Vague claim.',
			'comment_author'  => 'AI Feedback',
		);
		$GLOBALS['test_comment_meta'][100] = array( 'ai_feedback' => '1' );
		$GLOBALS['test_comments'][999]     = (object) array(
			'comment_ID'      => 999,
			'comment_content' => 'Latest reply.',
			'comment_author'  => 'Jane',
			'comment_parent'  => 100,
		);
	}

	/**
	 * Non-note comments under the same parent are left out of the thread.
	 */
	public function test_thread_history_only_includes_notes(): void {
		$this->seed_parent_and_reply();
		$GLOBALS['test_comments'][150] = (object) array(
			'comment_ID'      => 150,
			'comment_content' => 'PINGBACK_TEXT',
			'comment_author'  => 'Other site',
			'comment_parent'  => 100,
			'comment_type'    => 'pingback',
		);

		$service = new Reply_Service_Stub( new Prompt_Builder(), new Reply_Service_Fake_Notes_Manager() );
		$service->handle_reply( 999, 100, 42, 'abc-123' );

		$this->assertSame( 'note', $GLOBALS['test_get_comments_args']['type'] );
		$this->assertStringNotContainsString( 'PINGBACK_TEXT', $service->captured_prompt );
	}

	/**
	 * Long threads keep only the most recent entries, in chronological order.
	 */
	public function test_thread_history_keeps_most_recent_entries(): void {
		$this->seed_parent_and_reply();
		$total = Reply_Service::MAX_THREAD_ENTRIES + 5;
		for ( $i = 1; $i <= $total; $i++ ) {
			$GLOBALS['test_comments'][ 100 + $i ] = (object) array(
				'comment_ID'      => 100 + $i,
				'comment_content' => sprintf( 'Entry-%02d.', $i ),
				'comment_author'  => 'Jane',
				'comment_parent'  => 100,
			);
		}

		$service = new Reply_Service_Stub( new Prompt_Builder(), new Reply_Service_Fake_Notes_Manager() );
		$service->handle_reply( 999, 100, 42, 'abc-123' );

		$prompt = $service->captured_prompt;
		$this->assertStringNotContainsString( 'Entry-05.', $prompt );
		$this->assertStringContainsString( 'Entry-06.', $prompt );
		$this->assertStringContainsString( sprintf( 'Entry-%02d.', $total ), $prompt );
		$this->assertLessThan( strpos( $prompt, sprintf( 'Entry-%02d.', $total ) ), strpos( $prompt, 'Entry-06.' ) );
	}

	/**
	 * An empty AI response is an error, not an empty note.
	 */
	public function test_empty_ai_reply_returns_wp_error_and_skips_persistence(): void {
		$this->seed_parent_and_reply();
		$notes   = new Reply_Service_Fake_Notes_Manager();
		$service = new Reply_Service_Stub( new Prompt_Builder(), $notes );

		$service->canned_response = "  \n ";
		$result                   = $service->handle_reply( 999, 100, 42, 'abc-123' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_empty_reply', $result->get_error_code() );
		$this->assertSame( array(), $notes->last_reply );
	}

	/**
	 * A throw during generation still records a terminal failed status.
	 */
	public function test_on_reply_received_records_failed_status_when_generation_throws(): void {
		$this->seed_parent_and_reply();

		$service = new class( new Prompt_Builder(), new Reply_Service_Fake_Notes_Manager() ) extends Reply_Service {
			protected function call_ai( string $prompt, string $system_instruction, string $model ): string|\WP_Error {
				throw new \RuntimeException( 'Provider exploded' );
			}
		};

		// Cron only answers a reply that is still pending.
		$GLOBALS['test_comment_meta'][999][ \AI_Feedback\Reply_Cron_Dispatcher::STATUS_META_KEY ] = 'pending';

		$service->on_reply_received( 999, 100, 42, 'abc-123' );

		$this->assertSame(
			'failed',
			$GLOBALS['test_comment_meta'][999][ \AI_Feedback\Reply_Cron_Dispatcher::STATUS_META_KEY ]
		);
		$this->assertStringNotContainsString(
			'Provider exploded',
			$GLOBALS['test_comment_meta'][999][ \AI_Feedback\Reply_Cron_Dispatcher::REPLY_ERROR_META_KEY ]
		);
	}

	/**
	 * Register post content for post 42 and its parsed block tree.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 */
	private function seed_post_blocks( array $blocks ): void {
		$GLOBALS['test_post_content'][42]                 = '<!-- post 42 -->';
		$GLOBALS['test_parsed_blocks']['<!-- post 42 -->'] = $blocks;
	}

	/**
	 * Build a parsed leaf block.
	 *
	 * @param string   $name    Block name.
	 * @param string   $html    Block HTML.
	 * @param int|null $note_id Linked note ID, if any.
	 * @return array<string, mixed>
	 */
	private static function leaf_block( string $name, string $html, ?int $note_id = null ): array {
		return array(
			'blockName'    => $name,
			'attrs'        => null === $note_id ? array() : array( 'metadata' => array( 'noteId' => $note_id ) ),
			'innerBlocks'  => array(),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
		);
	}

	/**
	 * The prompt includes the text of the block linked to the note.
	 */
	public function test_prompt_includes_content_of_linked_block(): void {
		$this->seed_parent_and_reply();
		$this->seed_post_blocks(
			array(
				self::leaf_block( 'core/paragraph', '<p>Unrelated intro.</p>' ),
				self::leaf_block( 'core/paragraph', "<p>Revenue grew <strong>40%</strong>\n last year.</p>", 100 ),
			)
		);

		$service = new Reply_Service_Stub( new Prompt_Builder(), new Reply_Service_Fake_Notes_Manager() );
		$service->handle_reply( 999, 100, 42, 'abc-123' );

		$this->assertStringContainsString( 'Block content: Revenue grew 40% last year.', $service->captured_prompt );
		$this->assertStringNotContainsString( 'Unrelated intro.', $service->captured_prompt );
		// Falls back to the parsed block name when the note has no block_name meta.
		$this->assertStringContainsString( 'Block type: core/paragraph', $service->captured_prompt );
	}

	/**
	 * A linked container block contributes its inner blocks' text, and a
	 * block nested inside another container is still found.
	 */
	public function test_prompt_includes_inner_blocks_of_nested_linked_block(): void {
		$this->seed_parent_and_reply();
		$GLOBALS['test_comment_meta'][100]['block_name'] = 'core/quote';

		$quote = array(
			'blockName'    => 'core/quote',
			'attrs'        => array( 'metadata' => array( 'noteId' => '100' ) ),
			'innerBlocks'  => array(
				self::leaf_block( 'core/paragraph', '<p>First line.</p>' ),
				self::leaf_block( 'core/paragraph', '<p>Second line.</p>' ),
			),
			'innerHTML'    => '<blockquote class="wp-block-quote"><cite>Ada</cite></blockquote>',
			'innerContent' => array( '<blockquote class="wp-block-quote">', null, '', null, '<cite>Ada</cite></blockquote>' ),
		);
		$group = array(
			'blockName'    => 'core/group',
			'attrs'        => array(),
			'innerBlocks'  => array( $quote ),
			'innerHTML'    => '<div class="wp-block-group"></div>',
			'innerContent' => array( '<div class="wp-block-group">', null, '</div>' ),
		);
		$this->seed_post_blocks( array( $group ) );

		$service = new Reply_Service_Stub( new Prompt_Builder(), new Reply_Service_Fake_Notes_Manager() );
		$service->handle_reply( 999, 100, 42, 'abc-123' );

		$this->assertStringContainsString( 'Block type: core/quote', $service->captured_prompt );
		$this->assertStringContainsString( 'Block content: First line. Second line. Ada', $service->captured_prompt );
	}

	/**
	 * The editor stores `noteId` as an array once a block has several note
	 * threads, so a match anywhere in that array links the block.
	 */
	public function test_prompt_includes_content_of_block_with_note_id_array(): void {
		$this->seed_parent_and_reply();
		$block                                = self::leaf_block( 'core/paragraph', '<p>Shared block text.</p>' );
		$block['attrs']['metadata']['noteId'] = array( 205, '100' );
		$this->seed_post_blocks( array( $block ) );

		$service = new Reply_Service_Stub( new Prompt_Builder(), new Reply_Service_Fake_Notes_Manager() );
		$service->handle_reply( 999, 100, 42, 'abc-123' );

		$this->assertStringContainsString( 'Block content: Shared block text.', $service->captured_prompt );
	}

	/**
	 * Saved block HTML holds entities, which the AI would otherwise read as
	 * literal text and might flag as typos.
	 */
	public function test_block_content_decodes_html_entities(): void {
		$this->seed_parent_and_reply();
		$this->seed_post_blocks(
			array( self::leaf_block( 'core/paragraph', '<p>Tom &amp; Jerry&nbsp;said &quot;hi&quot; &#8211; it&#039;s 5&nbsp;&nbsp;pm.</p>', 100 ) )
		);

		$service = new Reply_Service_Stub( new Prompt_Builder(), new Reply_Service_Fake_Notes_Manager() );
		$service->handle_reply( 999, 100, 42, 'abc-123' );

		$this->assertStringContainsString( "Block content: Tom & Jerry said \"hi\" \u{2013} it's 5 pm.\n", $service->captured_prompt );
	}

	/**
	 * Line breaks and table cells don't run their text together.
	 */
	public function test_block_content_keeps_breaks_between_cells_and_lines(): void {
		$this->seed_parent_and_reply();
		$this->seed_post_blocks(
			array( self::leaf_block( 'core/table', '<figure class="wp-block-table"><table><tr><td>10</td><td>20</td></tr></table><figcaption>First<br>Second</figcaption></figure>', 100 ) )
		);

		$service = new Reply_Service_Stub( new Prompt_Builder(), new Reply_Service_Fake_Notes_Manager() );
		$service->handle_reply( 999, 100, 42, 'abc-123' );

		$this->assertStringContainsString( "Block content: 10 20 First Second\n", $service->captured_prompt );
	}

	/**
	 * Markup written as text, like a tag in a code sample, survives into the prompt.
	 */
	public function test_block_content_keeps_escaped_markup(): void {
		$this->seed_parent_and_reply();
		$this->seed_post_blocks(
			array( self::leaf_block( 'core/code', '<pre class="wp-block-code"><code>Wrap it in &lt;div class=&quot;box&quot;&gt;.</code></pre>', 100 ) )
		);

		$service = new Reply_Service_Stub( new Prompt_Builder(), new Reply_Service_Fake_Notes_Manager() );
		$service->handle_reply( 999, 100, 42, 'abc-123' );

		$this->assertStringContainsString( 'Block content: Wrap it in <div class="box">.' . "\n", $service->captured_prompt );
	}

	/**
	 * The note's block_name meta wins over the parsed block name.
	 */
	public function test_block_name_meta_takes_precedence_over_parsed_block_name(): void {
		$this->seed_parent_and_reply();
		$GLOBALS['test_comment_meta'][100]['block_name'] = 'core/heading';
		$this->seed_post_blocks(
			array( self::leaf_block( 'core/paragraph', '<p>Block text.</p>', 100 ) )
		);

		$service = new Reply_Service_Stub( new Prompt_Builder(), new Reply_Service_Fake_Notes_Manager() );
		$service->handle_reply( 999, 100, 42, 'abc-123' );

		$this->assertStringContainsString( 'Block type: core/heading', $service->captured_prompt );
		$this->assertStringContainsString( 'Block content: Block text.', $service->captured_prompt );
	}

	/**
	 * A post deleted before the cron run still gets a reply, without block text.
	 */
	public function test_missing_post_leaves_block_content_empty(): void {
		$this->seed_parent_and_reply();
		$GLOBALS['test_missing_posts'] = array( 42 );

		$service = new Reply_Service_Stub( new Prompt_Builder(), new Reply_Service_Fake_Notes_Manager() );
		$result  = $service->handle_reply( 999, 100, 42, 'abc-123' );

		$this->assertSame( 999, $result );
		$this->assertStringContainsString( "Block content: \n", $service->captured_prompt );
		$this->assertSame( 0, $GLOBALS['test_parse_blocks_calls'] );
	}

	/**
	 * Empty post content skips block parsing.
	 */
	public function test_empty_post_content_skips_block_parsing(): void {
		$this->seed_parent_and_reply();
		$GLOBALS['test_post_content'][42] = '';

		$service = new Reply_Service_Stub( new Prompt_Builder(), new Reply_Service_Fake_Notes_Manager() );
		$result  = $service->handle_reply( 999, 100, 42, 'abc-123' );

		$this->assertSame( 999, $result );
		$this->assertStringContainsString( "Block content: \n", $service->captured_prompt );
		$this->assertSame( 0, $GLOBALS['test_parse_blocks_calls'] );
	}

	/**
	 * When no block links to the note, the reply still goes out without block text.
	 */
	public function test_missing_linked_block_leaves_block_content_empty(): void {
		$this->seed_parent_and_reply();
		$this->seed_post_blocks(
			array( self::leaf_block( 'core/paragraph', '<p>Some other block.</p>', 555 ) )
		);

		$service = new Reply_Service_Stub( new Prompt_Builder(), new Reply_Service_Fake_Notes_Manager() );
		$result  = $service->handle_reply( 999, 100, 42, 'abc-123' );

		$this->assertSame( 999, $result );
		$this->assertStringNotContainsString( 'Some other block.', $service->captured_prompt );
		$this->assertStringContainsString( "Block content: \n", $service->captured_prompt );
	}

	/**
	 * Run a reply against a linked paragraph with a canned AI response.
	 *
	 * @param string $ai_response Canned AI response.
	 * @param string $html        HTML of the linked block.
	 * @return Reply_Service_Fake_Notes_Manager Notes manager holding the saved reply.
	 */
	private function reply_with( string $ai_response, string $html = '<p>Revenue grew 40% last year.</p>' ): Reply_Service_Fake_Notes_Manager {
		$this->seed_parent_and_reply();
		$this->seed_post_blocks( array( self::leaf_block( 'core/paragraph', $html, 100 ) ) );

		$fake_notes               = new Reply_Service_Fake_Notes_Manager();
		$service                  = new Reply_Service_Stub( new Prompt_Builder(), $fake_notes );
		$service->canned_response = $ai_response;

		$this->assertSame( 999, $service->handle_reply( 999, 100, 42, 'abc-123' ) );

		return $fake_notes;
	}

	/**
	 * A reply asking for the change saves the edit with the prose reply.
	 */
	public function test_reply_with_text_edit_saves_the_edit(): void {
		$notes = $this->reply_with( '{"reply": "Done, I added the year.", "edit": {"type": "replace_text", "find": "last year", "replace": "in 2025"}}' );

		$item = $notes->last_reply['feedback_item'];
		$this->assertSame( 'Done, I added the year.', $item['feedback'] );
		$this->assertSame(
			array(
				'type'    => 'replace_text',
				'find'    => 'last year',
				'replace' => 'in 2025',
			),
			$item['reply_edit']
		);
	}

	/**
	 * A reply that only answers a question has no edit.
	 */
	public function test_reply_without_edit_saves_prose_only(): void {
		$notes = $this->reply_with( '{"reply": "It is the growth figure.", "edit": null}' );

		$this->assertSame( 'It is the growth figure.', $notes->last_reply['feedback_item']['feedback'] );
		$this->assertArrayNotHasKey( 'reply_edit', $notes->last_reply['feedback_item'] );
	}

	/**
	 * An invalid edit is dropped and the prose still posts.
	 */
	public function test_invalid_edit_is_dropped_and_reply_still_posts(): void {
		$notes = $this->reply_with( '{"reply": "Changed it.", "edit": {"type": "replace_text", "find": "missing text", "replace": "x"}}' );

		$this->assertSame( 'Changed it.', $notes->last_reply['feedback_item']['feedback'] );
		$this->assertArrayNotHasKey( 'reply_edit', $notes->last_reply['feedback_item'] );
	}

	/**
	 * A rewrite is dropped unless the block type supports it.
	 */
	public function test_rewrite_is_dropped_for_unsupported_block(): void {
		$GLOBALS['test_filter_results'] = array( 'ai_feedback_reply_can_rewrite_block' => false );
		$notes                          = $this->reply_with( '{"reply": "Rewrote it.", "edit": {"type": "rewrite_block", "content": "Revenue grew a lot."}}' );
		unset( $GLOBALS['test_filter_results'] );

		$this->assertArrayNotHasKey( 'reply_edit', $notes->last_reply['feedback_item'] );
	}

	/**
	 * A supported block takes a rewrite, stored with the text it replaces.
	 */
	public function test_rewrite_is_kept_for_supported_block(): void {
		$GLOBALS['test_filter_results'] = array( 'ai_feedback_reply_can_rewrite_block' => true );
		$notes                          = $this->reply_with( '{"reply": "Rewrote it.", "edit": {"type": "rewrite_block", "content": "Revenue grew <strong>40%</strong> in 2025."}}' );
		unset( $GLOBALS['test_filter_results'] );

		$this->assertSame(
			array(
				'type'     => 'rewrite_block',
				'content'  => 'Revenue grew <strong>40%</strong> in 2025.',
				'original' => 'Revenue grew 40% last year.',
			),
			$notes->last_reply['feedback_item']['reply_edit']
		);
	}

	/**
	 * Data provider for blocks whose markup a plain text rewrite would drop.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function formatted_block_provider(): array {
		return array(
			'link'       => array( '<p>Revenue grew 40% <a href="https://example.com">last year</a>.</p>' ),
			'bold'       => array( '<p>Revenue grew <strong>40%</strong> last year.</p>' ),
			'line break' => array( '<p>Revenue grew 40%<br>last year.</p>' ),
		);
	}

	/**
	 * A block with links, formatting or line breaks can't be rewritten, and
	 * the prompt does not offer it.
	 *
	 * @dataProvider formatted_block_provider
	 *
	 * @param string $html Block HTML.
	 */
	public function test_rewrite_is_dropped_for_formatted_block( string $html ): void {
		$GLOBALS['test_filter_results'] = array( 'ai_feedback_reply_can_rewrite_block' => true );
		$this->seed_parent_and_reply();
		$this->seed_post_blocks( array( self::leaf_block( 'core/paragraph', $html, 100 ) ) );

		$notes                    = new Reply_Service_Fake_Notes_Manager();
		$service                  = new Reply_Service_Stub( new Prompt_Builder(), $notes );
		$service->canned_response = '{"reply": "Rewrote it.", "edit": {"type": "rewrite_block", "content": "Revenue grew 40% in 2025."}}';
		$service->handle_reply( 999, 100, 42, 'abc-123' );
		unset( $GLOBALS['test_filter_results'] );

		$this->assertStringNotContainsString( 'rewrite_block', $service->captured_prompt );
		$this->assertSame( 'Rewrote it.', $notes->last_reply['feedback_item']['feedback'] );
		$this->assertArrayNotHasKey( 'reply_edit', $notes->last_reply['feedback_item'] );
	}

	/**
	 * A plain text block offers the rewrite in the prompt.
	 */
	public function test_prompt_offers_rewrite_for_plain_block(): void {
		$GLOBALS['test_filter_results'] = array( 'ai_feedback_reply_can_rewrite_block' => true );
		$this->seed_parent_and_reply();
		$this->seed_post_blocks( array( self::leaf_block( 'core/paragraph', '<p class="has-text-align-center">Revenue grew 40% last year.</p>', 100 ) ) );

		$service = new Reply_Service_Stub( new Prompt_Builder(), new Reply_Service_Fake_Notes_Manager() );
		$service->handle_reply( 999, 100, 42, 'abc-123' );
		unset( $GLOBALS['test_filter_results'] );

		$this->assertStringContainsString( '"type": "rewrite_block"', $service->captured_prompt );
	}

	/**
	 * A block too long to send in full can't be rewritten.
	 */
	public function test_rewrite_is_dropped_for_truncated_block(): void {
		$GLOBALS['test_filter_results'] = array( 'ai_feedback_reply_can_rewrite_block' => true );
		$long                           = str_repeat( 'word ', 500 );
		$notes                          = $this->reply_with( '{"reply": "Rewrote it.", "edit": {"type": "rewrite_block", "content": "Short."}}', '<p>' . $long . '</p>' );
		unset( $GLOBALS['test_filter_results'] );

		$this->assertArrayNotHasKey( 'reply_edit', $notes->last_reply['feedback_item'] );
	}

	/**
	 * A reply meant as JSON that can't be decoded gets its own error.
	 */
	public function test_unreadable_json_reply_returns_its_own_error(): void {
		$this->seed_parent_and_reply();

		$fake_notes               = new Reply_Service_Fake_Notes_Manager();
		$service                  = new Reply_Service_Stub( new Prompt_Builder(), $fake_notes );
		$service->canned_response = '{"edit": {"type": ';

		$result = $service->handle_reply( 999, 100, 42, 'abc-123' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_unreadable_reply', $result->get_error_code() );
		$this->assertSame( 'The AI reply could not be read. Please try again.', $result->get_error_message() );
		$this->assertSame( array(), $fake_notes->last_reply );
	}

	/**
	 * A JSON reply with neither text nor a usable edit is an empty reply.
	 */
	public function test_json_reply_without_text_returns_wp_error(): void {
		$this->seed_parent_and_reply();

		$fake_notes               = new Reply_Service_Fake_Notes_Manager();
		$service                  = new Reply_Service_Stub( new Prompt_Builder(), $fake_notes );
		$service->canned_response = '{"reply": "", "edit": null}';

		$result = $service->handle_reply( 999, 100, 42, 'abc-123' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_empty_reply', $result->get_error_code() );
		$this->assertSame( array(), $fake_notes->last_reply );
	}

	/**
	 * Add an earlier AI reply with an edit to the seeded thread.
	 *
	 * @param int                  $id   Reply ID, below the user reply's 999.
	 * @param array<string, mixed> $edit Stored edit.
	 * @param array<string, mixed> $meta Extra meta.
	 */
	private function seed_ai_edit_reply( int $id, array $edit, array $meta = array() ): void {
		$GLOBALS['test_comments'][ $id ]     = (object) array(
			'comment_ID'      => $id,
			'comment_content' => 'Done, here is the change.',
			'comment_author'  => 'Fresh Eyes',
			'comment_parent'  => 100,
		);
		$GLOBALS['test_comment_meta'][ $id ] = array(
			'ai_feedback'                       => '1',
			Notes_Manager::REPLY_EDIT_META_KEY => (string) wp_json_encode( $edit ),
		) + $meta;
	}

	/**
	 * Run a reply in a thread already seeded, with a canned AI response.
	 *
	 * @param string $ai_response Canned AI response.
	 * @param string $html        HTML of the linked block.
	 * @return Reply_Service_Stub Service holding the captured prompt.
	 */
	private function refine_with( string $ai_response, string $html = '<p>Revenue grew 40% last year.</p>' ): Reply_Service_Stub {
		$this->seed_post_blocks( array( self::leaf_block( 'core/paragraph', $html, 100 ) ) );

		$service                  = new Reply_Service_Stub( new Prompt_Builder(), new Reply_Service_Fake_Notes_Manager() );
		$service->canned_response = $ai_response;
		$this->assertSame( 999, $service->handle_reply( 999, 100, 42, 'abc-123' ) );

		return $service;
	}

	/**
	 * The newest edit in the thread is sent as the pending suggestion, and
	 * each earlier edit shows in the conversation.
	 */
	public function test_prompt_includes_pending_edit_from_latest_reply(): void {
		$this->seed_parent_and_reply();
		$this->seed_ai_edit_reply( 500, array( 'type' => 'replace_text', 'find' => 'last year', 'replace' => 'in 2025' ) );
		$this->seed_ai_edit_reply( 600, array( 'type' => 'replace_text', 'find' => 'last year', 'replace' => 'in fiscal 2025' ) );

		$prompt = $this->refine_with( '{"reply": "Shorter.", "edit": null}' )->captured_prompt;

		$this->assertStringContainsString( "YOUR PENDING SUGGESTED EDIT (not applied, so the block content above does not include it):\nreplace \"last year\" with \"in fiscal 2025\"", $prompt );
		$this->assertStringContainsString( '(Suggested edit: replace "last year" with "in 2025")', $prompt );
		$this->assertStringContainsString( 'return a new edit that replaces it', $prompt );
	}

	/**
	 * The review's quick fix is the pending edit until a reply proposes another.
	 */
	public function test_prompt_includes_quick_fix_as_pending_edit(): void {
		$this->seed_parent_and_reply();
		$GLOBALS['test_comment_meta'][100]['feedback_quick_fix'] = '{"find":"last year","replace":"in 2025"}';

		$prompt = $this->refine_with( '{"reply": "Sure.", "edit": null}' )->captured_prompt;

		$this->assertStringContainsString( "YOUR PENDING SUGGESTED EDIT (not applied, so the block content above does not include it):\nreplace \"last year\" with \"in 2025\"", $prompt );
	}

	/**
	 * A thread without edits sends no pending edit.
	 */
	public function test_prompt_has_no_pending_edit_without_edits(): void {
		$this->seed_parent_and_reply();

		$prompt = $this->refine_with( '{"reply": "Sure.", "edit": null}' )->captured_prompt;

		$this->assertStringNotContainsString( 'PENDING SUGGESTED EDIT', $prompt );
		$this->assertStringNotContainsString( 'return a new edit that replaces it', $prompt );
	}

	/**
	 * An edit whose text is gone from the block, for example because it was
	 * applied, is no longer pending.
	 */
	public function test_applied_edit_is_not_pending(): void {
		$this->seed_parent_and_reply();
		$this->seed_ai_edit_reply( 500, array( 'type' => 'replace_text', 'find' => 'last year', 'replace' => 'in 2025' ) );

		$prompt = $this->refine_with( '{"reply": "Sure.", "edit": null}', '<p>Revenue grew 40% in 2025.</p>' )->captured_prompt;

		$this->assertStringNotContainsString( 'PENDING SUGGESTED EDIT', $prompt );
	}

	/**
	 * A rewrite stays pending while the block reads as the text it replaces.
	 */
	public function test_rewrite_is_pending_while_block_is_unchanged(): void {
		$this->seed_parent_and_reply();
		$this->seed_ai_edit_reply(
			500,
			array(
				'type'     => 'rewrite_block',
				'content'  => 'Revenue grew <strong>40%</strong> in 2025.',
				'original' => 'Revenue grew 40% last year.',
			)
		);

		$prompt = $this->refine_with( '{"reply": "Sure.", "edit": null}' )->captured_prompt;

		$this->assertStringContainsString( 'rewrite the block as "Revenue grew 40% in 2025."', $prompt );
	}

	/**
	 * An edit the author accepted or rejected as a suggestion is not pending.
	 *
	 * @dataProvider decided_suggestion_statuses
	 *
	 * @param string $status Suggestion status.
	 */
	public function test_decided_suggestion_is_not_pending( string $status ): void {
		$this->seed_parent_and_reply();
		$this->seed_ai_edit_reply(
			500,
			array( 'type' => 'replace_text', 'find' => 'last year', 'replace' => 'in 2025' ),
			array(
				'_wp_suggestion'        => '{"schemaVersion":2}',
				'_wp_suggestion_status' => $status,
			)
		);

		$prompt = $this->refine_with( '{"reply": "Sure.", "edit": null}' )->captured_prompt;

		$this->assertStringNotContainsString( 'PENDING SUGGESTED EDIT', $prompt );
	}

	/**
	 * Suggestion statuses the author has decided.
	 *
	 * @return array<string, array{string}>
	 */
	public static function decided_suggestion_statuses(): array {
		return array(
			'rejected'         => array( 'rejected' ),
			'rejected-unsaved' => array( 'rejected-unsaved' ),
			'applied'          => array( 'applied' ),
			'applied-unsaved'  => array( 'applied-unsaved' ),
		);
	}

	/**
	 * A pending suggestion's markers are left out of the block text, so the
	 * AI sees, and edits, the text as it reads without the suggestion.
	 */
	public function test_block_text_leaves_out_pending_suggestion_markers(): void {
		$this->seed_parent_and_reply();
		$this->seed_ai_edit_reply(
			500,
			array( 'type' => 'replace_text', 'find' => 'last year', 'replace' => 'in 2025' ),
			array( '_wp_suggestion' => '{"schemaVersion":2}' )
		);
		$html = '<p>Revenue grew 40% <mark class="wp-suggestion-add" data-suggestion-id="500" data-suggestion-type="add">in 2025</mark><mark class="wp-suggestion-del" data-suggestion-id="500" data-suggestion-type="del">last year</mark>.</p>';

		$service = $this->refine_with( '{"reply": "Shorter.", "edit": {"type": "replace_text", "find": "last year", "replace": "2025"}}', $html );

		$this->assertStringContainsString( "Block content: Revenue grew 40% last year.\n", $service->captured_prompt );
		$this->assertStringContainsString( 'replace "last year" with "in 2025"', $service->captured_prompt );
	}

	/**
	 * A new edit is numbered after the thread's earlier ones, counting the
	 * review's quick fix.
	 */
	public function test_new_edit_is_numbered_after_earlier_edits(): void {
		$this->seed_parent_and_reply();
		$GLOBALS['test_comment_meta'][100]['feedback_quick_fix'] = '{"find":"last year","replace":"in 2025"}';
		$this->seed_ai_edit_reply( 500, array( 'type' => 'replace_text', 'find' => 'last year', 'replace' => 'in fiscal 2025' ) );
		$this->seed_post_blocks( array( self::leaf_block( 'core/paragraph', '<p>Revenue grew 40% last year.</p>', 100 ) ) );

		$notes                    = new Reply_Service_Fake_Notes_Manager();
		$service                  = new Reply_Service_Stub( new Prompt_Builder(), $notes );
		$service->canned_response = '{"reply": "Shorter.", "edit": {"type": "replace_text", "find": "last year", "replace": "2025"}}';
		$service->handle_reply( 999, 100, 42, 'abc-123' );

		$this->assertSame( 3, $notes->last_reply['feedback_item']['revision'] );
	}

	/**
	 * The first edit in a thread without a quick fix is revision 1, and a
	 * reply without an edit has no revision.
	 */
	public function test_first_edit_is_revision_one(): void {
		$with_edit = $this->reply_with( '{"reply": "Done.", "edit": {"type": "replace_text", "find": "last year", "replace": "in 2025"}}' );
		$this->assertSame( 1, $with_edit->last_reply['feedback_item']['revision'] );

		$without_edit = $this->reply_with( '{"reply": "It is the growth figure.", "edit": null}' );
		$this->assertArrayNotHasKey( 'revision', $without_edit->last_reply['feedback_item'] );
	}
}
