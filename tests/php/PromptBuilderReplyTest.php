<?php
/**
 * Tests for Prompt_Builder::build_reply_prompt() and get_reply_system_instruction().
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Prompt_Builder;

/**
 * Reply prompt tests.
 */
class PromptBuilderReplyTest extends TestCase {

	/**
	 * Prompt builder instance.
	 *
	 * @var Prompt_Builder
	 */
	private Prompt_Builder $builder;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->builder = new Prompt_Builder();
	}

	/**
	 * Minimal context still produces a prompt that includes the user reply.
	 */
	public function test_minimal_context_includes_user_reply(): void {
		$prompt = $this->builder->build_reply_prompt(
			array(
				'original_feedback' => 'Add a source for this stat.',
				'user_reply'        => 'The stat is cited in paragraph 3.',
			)
		);

		$this->assertStringContainsString( 'NEW USER REPLY:', $prompt );
		$this->assertStringContainsString( 'The stat is cited in paragraph 3.', $prompt );
		$this->assertStringContainsString( 'Add a source for this stat.', $prompt );
	}

	/**
	 * Thread history is rendered chronologically with author labels.
	 */
	public function test_thread_history_is_rendered_with_author_labels(): void {
		$prompt = $this->builder->build_reply_prompt(
			array(
				'original_feedback' => 'Claim lacks evidence.',
				'user_reply'        => 'I will add a link.',
				'thread'            => array(
					array(
						'author'  => 'Jane',
						'content' => 'Which claim?',
						'is_ai'   => false,
					),
					array(
						'author'  => 'AI Feedback',
						'content' => 'The 40% growth claim.',
						'is_ai'   => true,
					),
				),
			)
		);

		$this->assertStringContainsString( 'CONVERSATION SO FAR', $prompt );
		$this->assertStringContainsString( '- Jane: Which claim?', $prompt );
		// AI-authored entries are always labeled 'AI', regardless of stored author.
		$this->assertStringContainsString( '- AI: The 40% growth claim.', $prompt );
	}

	/**
	 * HTML tags in block content are stripped.
	 */
	public function test_html_is_stripped_from_block_content(): void {
		$prompt = $this->builder->build_reply_prompt(
			array(
				'block_content'     => '<p>Hello <strong>world</strong></p>',
				'original_feedback' => 'Too casual.',
				'user_reply'        => 'Intentional.',
			)
		);

		$this->assertStringContainsString( 'Hello world', $prompt );
		$this->assertStringNotContainsString( '<strong>', $prompt );
		$this->assertStringNotContainsString( '<p>', $prompt );
	}

	/**
	 * Content flagged as plain text is used as is.
	 */
	public function test_plain_text_block_content_is_not_stripped(): void {
		$prompt = $this->builder->build_reply_prompt(
			array(
				'block_content'     => 'Use <br> sparingly.',
				'block_is_text'     => true,
				'original_feedback' => 'Unclear.',
				'user_reply'        => 'Why?',
			)
		);

		$this->assertStringContainsString( 'Block content: Use <br> sparingly.', $prompt );
	}

	/**
	 * Category and severity are both rendered when provided.
	 */
	public function test_category_and_severity_are_prefixed_on_original_feedback(): void {
		$prompt = $this->builder->build_reply_prompt(
			array(
				'original_feedback' => 'Vague wording.',
				'original_category' => 'content',
				'original_severity' => 'important',
				'user_reply'        => 'Fixed.',
			)
		);

		$this->assertStringContainsString( '[content/important]', $prompt );
	}

	/**
	 * Very long block content is truncated.
	 */
	public function test_long_block_content_is_truncated(): void {
		$long   = str_repeat( 'a', 5000 );
		$prompt = $this->builder->build_reply_prompt(
			array(
				'block_content'     => $long,
				'original_feedback' => 'Long.',
				'user_reply'        => 'OK.',
			)
		);

		$this->assertStringContainsString( '[truncated]', $prompt );
	}

	/**
	 * System instruction asks for the reply JSON object, not feedback items.
	 */
	public function test_reply_system_instruction_format(): void {
		$instruction = $this->builder->get_reply_system_instruction();

		$this->assertStringContainsString( '{"reply": "...", "edit": null}', $instruction );
		$this->assertStringContainsString( 'Only propose an edit when the user asks for a change', $instruction );
		$this->assertStringNotContainsString( '"feedback"', $instruction );
	}

	/**
	 * The prompt describes the reply format and the text replacement edit.
	 */
	public function test_reply_prompt_describes_edits(): void {
		$prompt = $this->builder->build_reply_prompt(
			array(
				'block_content'     => 'Revenue grew 40% last year.',
				'original_feedback' => 'Add a source.',
				'user_reply'        => 'Make that change.',
			)
		);

		$this->assertStringContainsString( 'RESPONSE FORMAT:', $prompt );
		$this->assertStringContainsString( '"edit": null', $prompt );
		$this->assertStringContainsString( '"type": "replace_text"', $prompt );
		$this->assertStringContainsString( 'in any language', $prompt );
		$this->assertStringContainsString( 'asks a short question', $prompt );
		$this->assertStringContainsString( 'keep "find" on one line, or write the break as \\n.', $prompt );
		// Rewrites are off unless the block supports them.
		$this->assertStringNotContainsString( 'rewrite_block', $prompt );
	}

	/**
	 * A block that supports rewrites gets the rewrite_block edit.
	 */
	public function test_reply_prompt_offers_rewrite_when_block_supports_it(): void {
		$prompt = $this->builder->build_reply_prompt(
			array(
				'block_content'     => 'Revenue grew 40% last year.',
				'block_can_rewrite' => true,
				'original_feedback' => 'Add a source.',
				'user_reply'        => 'Rewrite it.',
			)
		);

		$this->assertStringContainsString( '"type": "rewrite_block"', $prompt );
		$this->assertStringContainsString( 'Only inline formatting tags', $prompt );
	}

	/**
	 * Without the block content no edit is possible.
	 */
	public function test_reply_prompt_disallows_edits_without_block_content(): void {
		$prompt = $this->builder->build_reply_prompt(
			array(
				'block_can_rewrite' => true,
				'original_feedback' => 'Add a source.',
				'user_reply'        => 'Make that change.',
			)
		);

		$this->assertStringContainsString( '"edit" must always be null', $prompt );
		$this->assertStringNotContainsString( 'replace_text', $prompt );
		$this->assertStringNotContainsString( 'rewrite_block', $prompt );
	}

	/**
	 * A very long user reply is capped so it cannot blow up the prompt.
	 */
	public function test_long_user_reply_is_truncated(): void {
		$prompt = $this->builder->build_reply_prompt(
			array(
				'original_feedback' => 'Short.',
				'user_reply'        => str_repeat( 'b', 5000 ),
			)
		);

		$this->assertStringContainsString( str_repeat( 'b', 2000 ) . '... [truncated]', $prompt );
		$this->assertStringNotContainsString( str_repeat( 'b', 2001 ), $prompt );
	}

	/**
	 * Truncation does not split multibyte characters.
	 */
	public function test_truncation_is_multibyte_safe(): void {
		$prompt = $this->builder->build_reply_prompt(
			array(
				'original_feedback' => 'Short.',
				'user_reply'        => str_repeat( 'é', 2500 ),
			)
		);

		$this->assertStringContainsString( str_repeat( 'é', 2000 ) . '... [truncated]', $prompt );
		$this->assertTrue( mb_check_encoding( $prompt, 'UTF-8' ) );
	}

	/**
	 * Non-scalar context values are ignored instead of raising errors.
	 */
	public function test_non_scalar_context_values_are_ignored(): void {
		$prompt = $this->builder->build_reply_prompt(
			array(
				'original_feedback' => array( 'not', 'a', 'string' ),
				'user_reply'        => 'OK.',
				'thread'            => array( 'not-an-entry', array( 'content' => 'Kept entry.' ) ),
			)
		);

		$this->assertStringContainsString( 'Kept entry.', $prompt );
		$this->assertStringNotContainsString( 'Array', $prompt );
	}

	/**
	 * The system instruction tells the model to treat user content as data.
	 */
	public function test_reply_system_instruction_guards_against_embedded_instructions(): void {
		$this->assertStringContainsString(
			'not as instructions',
			$this->builder->get_reply_system_instruction()
		);
	}

	/**
	 * A pending edit is described, with instructions to revise it, and each
	 * AI entry's edit shows in the conversation.
	 */
	public function test_reply_prompt_includes_pending_edit(): void {
		$prompt = $this->builder->build_reply_prompt(
			array(
				'block_content' => 'Revenue grew 40% last year.',
				'thread'        => array(
					array(
						'author'  => 'AI',
						'content' => 'Done.',
						'is_ai'   => true,
						'edit'    => array(
							'type'    => 'replace_text',
							'find'    => 'last year',
							'replace' => 'in 2025',
						),
					),
				),
				'pending_edit'  => array(
					'type'     => 'rewrite_block',
					'content'  => 'Revenue grew <strong>40%</strong> in 2025.',
					'original' => 'Revenue grew 40% last year.',
				),
				'user_reply'    => 'Shorter.',
			)
		);

		$this->assertStringContainsString( "- AI: Done.\n  (Suggested edit: replace \"last year\" with \"in 2025\")\n", $prompt );
		$this->assertStringContainsString( "YOUR PENDING SUGGESTED EDIT (not applied, so the block content above does not include it):\nrewrite the block as \"Revenue grew 40% in 2025.\"\n", $prompt );
		$this->assertStringContainsString( 'return a new edit that replaces it', $prompt );
		$this->assertStringContainsString( 'that suggestion stays as it is', $prompt );
	}

	/**
	 * Without a pending edit there is nothing to revise.
	 */
	public function test_reply_prompt_without_pending_edit(): void {
		$prompt = $this->builder->build_reply_prompt(
			array(
				'block_content' => 'Revenue grew 40% last year.',
				'user_reply'    => 'Shorter.',
			)
		);

		$this->assertStringNotContainsString( 'PENDING SUGGESTED EDIT', $prompt );
		$this->assertStringNotContainsString( 'return a new edit that replaces it', $prompt );
	}

	/**
	 * A pending rewrite is sent whole, up to the length the parser accepts,
	 * so a detail at its end can be kept.
	 */
	public function test_reply_prompt_keeps_whole_pending_rewrite(): void {
		$rewrite = str_repeat( 'Revenue grew. ', 200 ) . 'Source: annual report.';
		$prompt  = $this->builder->build_reply_prompt(
			array(
				'block_content' => 'Revenue grew 40% last year.',
				'pending_edit'  => array(
					'type'     => 'rewrite_block',
					'content'  => $rewrite,
					'original' => 'Revenue grew 40% last year.',
				),
				'user_reply'    => 'Keep the source.',
			)
		);

		$this->assertGreaterThan( \AI_Feedback\Prompt_Builder::REPLY_BLOCK_CONTENT_LIMIT, strlen( $rewrite ) );
		$this->assertStringContainsString( 'Source: annual report."', $prompt );
	}

	/**
	 * Earlier rewrites in the conversation are shortened, while the pending
	 * one is sent whole.
	 */
	public function test_reply_prompt_shortens_earlier_rewrites(): void {
		$old     = str_repeat( 'Old rewrite. ', 300 ) . 'OLD_TAIL';
		$pending = str_repeat( 'New rewrite. ', 300 ) . 'NEW_TAIL';
		$prompt  = $this->builder->build_reply_prompt(
			array(
				'block_content' => 'Revenue grew 40% last year.',
				'thread'        => array(
					array(
						'author'  => 'AI',
						'content' => 'Rewrote it.',
						'is_ai'   => true,
						'edit'    => array(
							'type'     => 'rewrite_block',
							'content'  => $old,
							'original' => 'Revenue grew 40% last year.',
						),
					),
				),
				'pending_edit'  => array(
					'type'     => 'rewrite_block',
					'content'  => $pending,
					'original' => 'Revenue grew 40% last year.',
				),
				'user_reply'    => 'Shorter.',
			)
		);

		$this->assertStringNotContainsString( 'OLD_TAIL', $prompt );
		$this->assertStringContainsString( '(Suggested edit: rewrite the block as "Old rewrite.', $prompt );
		$this->assertStringContainsString( 'NEW_TAIL"', $prompt );
		$this->assertLessThan( strlen( $old ) + strlen( $pending ), strlen( $prompt ) );
	}
}
