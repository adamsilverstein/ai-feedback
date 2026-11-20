<?php
/**
 * Tests that JSON note meta survives being stored through wp_insert_comment().
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Notes_Manager;

/**
 * Round trips of the JSON stored in note meta.
 *
 * The fake wp_insert_comment() unslashes each meta value, as core does
 * through add_comment_meta().
 */
class NotesManagerMetaStorageTest extends TestCase {

	/**
	 * Load the fake comment API and reset it.
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once __DIR__ . '/fixtures/fake-comments.php';
		require_once dirname( __DIR__, 2 ) . '/includes/class-plugin.php';
		\ai_feedback_test_reset_comments(); // @phpstan-ignore function.notFound (defined in the fixture above)
	}

	/**
	 * A reply edit with quotes, markup and accented text reads back intact.
	 */
	public function test_reply_edit_round_trips_through_insert(): void {
		$edit = array(
			'type'     => 'rewrite_block',
			'content'  => 'Le <a href="https://example.com/caf%C3%A9">café</a> a "grandi" de 40%\\.',
			'original' => 'Le café a grandi de 40%.',
		);

		$note_id = ( new Notes_Manager() )->add_reply_to_thread(
			array(
				'feedback'   => 'Voilà.',
				'block_id'   => 'block-1',
				'reply_edit' => $edit,
			),
			10,
			5
		);

		$this->assertIsInt( $note_id );
		$this->assertSame(
			$edit,
			Notes_Manager::decode_reply_edit( get_comment_meta( $note_id, Notes_Manager::REPLY_EDIT_META_KEY, true ) )
		);
	}

	/**
	 * A quick fix with quotes and accented text reads back intact.
	 */
	public function test_quick_fix_round_trips_through_insert(): void {
		$fix = array(
			'find'    => 'le "café"',
			'replace' => 'l’espresso',
		);

		$result = ( new Notes_Manager() )->create_notes_from_feedback(
			array(
				array(
					'block_id'  => 'block-1',
					'category'  => 'tone',
					'severity'  => 'suggestion',
					'title'     => 'Word choice',
					'feedback'  => 'Say "espresso".',
					'quick_fix' => $fix,
				),
			),
			10
		);

		$this->assertIsArray( $result );
		$note_id = (int) array_key_first( $GLOBALS['test_comments'] );
		$manager = new Notes_Manager();
		$this->assertSame( $fix, $manager->decode_quick_fix( get_comment_meta( $note_id, 'feedback_quick_fix', true ) ) );
	}
}
