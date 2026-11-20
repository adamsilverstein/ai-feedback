<?php
/**
 * Inputs for the default prompt fixtures.
 *
 * The fixtures in default-prompts/ were built from these inputs with the
 * plugin as it was before reader personas (e10091b), so a default review
 * can be checked against the prompts it sent then.
 *
 * After a deliberate change to the default prompts, such as the line break
 * instruction for `find`, rebuild them with regenerate-default-prompts.php
 * and check the diff holds only the intended lines.
 *
 * @package AI_Feedback\Tests
 */

$blocks = array(
	array(
		'clientId'   => 'block-1',
		'name'       => 'core/heading',
		'content'    => 'Why our plugin is faster',
		'attributes' => array( 'level' => 2 ),
	),
	array(
		'clientId' => 'block-2',
		'name'     => 'core/paragraph',
		'content'  => 'Our plugin is 40% faster than the alternatives, in order to save you time.',
	),
);

$options = array(
	'focus_areas' => array( 'content', 'tone', 'flow', 'grammar' ),
	'target_tone' => 'professional',
	'post_title'  => 'Why our plugin is faster',
	'locale'      => 'en_US',
);

$existing_feedback = array(
	array(
		'block_id' => 'block-2',
		'category' => 'content',
		'severity' => 'important',
		'content'  => array( 'raw' => 'Faster than what? Add a baseline.' ),
		'replies'  => array(
			array(
				'content'     => array( 'raw' => 'Than the last release.' ),
				'author_name' => 'Jane',
				'is_ai'       => false,
			),
		),
	),
);

$reply_context = array(
	'block_content'     => 'Our plugin is 40% faster than the alternatives.',
	'block_type'        => 'core/paragraph',
	'block_can_rewrite' => true,
	'original_feedback' => 'Faster than what? Add a baseline.',
	'original_category' => 'content',
	'original_severity' => 'important',
	'thread'            => array(
		array(
			'author'  => 'Jane',
			'content' => 'Which number?',
			'is_ai'   => false,
		),
	),
	'user_reply'        => 'Than the last release.',
	'target_tone'       => 'professional',
	'locale'            => 'en_US',
);

return compact( 'blocks', 'options', 'existing_feedback', 'reply_context' );
