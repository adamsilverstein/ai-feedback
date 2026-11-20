<?php
/**
 * Rebuild the default prompt fixtures in default-prompts/.
 *
 * Run after a deliberate change to the default prompts, then review the
 * diff of default-prompts/ to confirm only the intended lines changed:
 *
 *     php tests/php/fixtures/regenerate-default-prompts.php
 *
 * Builds each prompt from default-prompt-inputs.php exactly as
 * PromptBuilderPersonaTest::test_default_prompts_match_before_personas()
 * does, with the PHPUnit bootstrap's WordPress mocks.
 *
 * @package AI_Feedback\Tests
 */

require dirname( __DIR__ ) . '/bootstrap.php';

/** @var array{blocks: list<array<string, mixed>>, options: array<string, mixed>, existing_feedback: list<array<string, mixed>>, reply_context: array<string, mixed>} $in */
$in      = require __DIR__ . '/default-prompt-inputs.php';
$builder = new AI_Feedback\Prompt_Builder();
$prompts = array(
	'review.txt'              => $builder->build_review_prompt( $in['blocks'], $in['options'] ),
	'review-continuation.txt' => $builder->build_review_prompt( $in['blocks'], $in['options'], $in['existing_feedback'] ),
	'reply.txt'               => $builder->build_reply_prompt( $in['reply_context'] ),
	'system.txt'              => $builder->get_system_instruction( false ),
	'system-continuation.txt' => $builder->get_system_instruction( true ),
	'reply-system.txt'        => $builder->get_reply_system_instruction(),
);

foreach ( $prompts as $name => $prompt ) {
	file_put_contents( __DIR__ . '/default-prompts/' . $name, $prompt ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A dev script, outside WordPress.
	echo $name . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
}
