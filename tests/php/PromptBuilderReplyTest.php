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
	 * System instruction is plain prose (no JSON, no markdown headers).
	 */
	public function test_reply_system_instruction_format(): void {
		$instruction = $this->builder->get_reply_system_instruction();

		$this->assertStringContainsString( 'Plain text only', $instruction );
		$this->assertStringNotContainsString( '{', $instruction );
		$this->assertStringNotContainsString( '"feedback"', $instruction );
	}
}
