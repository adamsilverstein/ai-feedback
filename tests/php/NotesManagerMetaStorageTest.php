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

	/**
	 * A structural edit with markup and quotes reads back intact on the
	 * thread's root note, and is not kept on a later note in the thread.
	 */
	public function test_block_action_round_trips_on_root_note_only(): void {
		$action = array(
			'type'     => 'insert_block',
			'position' => 'before',
			'block'    => array(
				'name'    => 'core/heading',
				'content' => 'Le <em>"café"</em>',
				'level'   => 3,
			),
		);
		$item   = static fn ( string $title ): array => array(
			'block_id'     => 'block-1',
			'category'     => 'flow',
			'severity'     => 'suggestion',
			'title'        => $title,
			'feedback'     => 'Add a heading.',
			'block_action' => $action,
		);

		$result = ( new Notes_Manager() )->create_notes_from_feedback( array( $item( 'First' ), $item( 'Second' ) ), 10 );

		$this->assertIsArray( $result );
		list( $root, $child ) = $result['note_ids'];
		$this->assertSame( $action, Notes_Manager::decode_block_action( get_comment_meta( $root, Notes_Manager::BLOCK_ACTION_META_KEY, true ) ) );
		$this->assertSame( '', get_comment_meta( $child, Notes_Manager::BLOCK_ACTION_META_KEY, true ) );
	}

	/**
	 * When several items share a block, the one with a structural edit leads
	 * the thread, so the edit is kept even when it was not listed first.
	 */
	public function test_item_with_block_action_becomes_the_root(): void {
		$item = static fn ( string $title, array $extra = array() ): array => array(
			'block_id' => 'block-1',
			'category' => 'content',
			'severity' => 'critical',
			'title'    => $title,
			'feedback' => 'Feedback.',
		) + $extra;

		$result = ( new Notes_Manager() )->create_notes_from_feedback(
			array(
				$item( 'Text only' ),
				$item( 'Cut it', array( 'block_action' => array( 'type' => 'remove_block' ) ) ),
			),
			10
		);

		$this->assertIsArray( $result );
		list( $root, $child ) = $result['note_ids'];
		$this->assertSame( $root, $result['block_mapping']['block-1'] );
		$this->assertSame( array( 'type' => 'remove_block' ), Notes_Manager::decode_block_action( get_comment_meta( $root, Notes_Manager::BLOCK_ACTION_META_KEY, true ) ) );
		$this->assertSame( 'Cut it', get_comment_meta( $root, 'feedback_title', true ) );
		$this->assertSame( 'Text only', get_comment_meta( $child, 'feedback_title', true ) );
	}
}
