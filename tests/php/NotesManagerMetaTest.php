<?php
/**
 * Tests for the note metadata built by Notes_Manager.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Notes_Manager;
use ReflectionMethod;

/**
 * Test cases for Notes_Manager::build_note_meta().
 */
class NotesManagerMetaTest extends TestCase {

	/**
	 * Call the private build_note_meta() method.
	 *
	 * @param  array<string, mixed> $feedback_item Feedback item.
	 * @return array<string, mixed> Note meta.
	 */
	private function build_note_meta( array $feedback_item ): array {
		$method = new ReflectionMethod( Notes_Manager::class, 'build_note_meta' );
		/** @var array<string, mixed> $meta */
		$meta = $method->invoke( new Notes_Manager(), $feedback_item, array( 'review_id' => 'abc' ) );

		return $meta;
	}

	/**
	 * Feedback text is stored in its own meta fields.
	 */
	public function test_stores_feedback_text_fields(): void {
		$meta = $this->build_note_meta(
			array(
				'block_id'   => 'block-1',
				'category'   => 'tone',
				'severity'   => 'suggestion',
				'title'      => 'Wordy phrase',
				'feedback'   => 'This sentence could be shorter.',
				'suggestion' => 'Replace "in order to" with "to".',
				'quick_fix'  => array(
					'find'    => 'in order to',
					'replace' => 'to',
				),
			)
		);

		$this->assertSame( '{"find":"in order to","replace":"to"}', $meta['feedback_quick_fix'] );

		$this->assertSame( 'Wordy phrase', $meta['feedback_title'] );
		$this->assertSame( 'This sentence could be shorter.', $meta['feedback_text'] );
		$this->assertSame( 'Replace "in order to" with "to".', $meta['feedback_suggestion'] );
	}

	/**
	 * Empty fields are not stored.
	 */
	public function test_skips_missing_feedback_fields(): void {
		$meta = $this->build_note_meta(
			array(
				'block_id' => 'block-1',
				'category' => 'flow',
				'severity' => 'important',
				'title'    => 'Abrupt transition',
				'feedback' => 'The section jumps topics.',
			)
		);

		$this->assertSame( 'Abrupt transition', $meta['feedback_title'] );
		$this->assertArrayNotHasKey( 'feedback_suggestion', $meta );
	}

	/**
	 * The block anchor is stored so the editor can re-link the note.
	 */
	public function test_stores_block_anchor(): void {
		$meta = $this->build_note_meta(
			array(
				'block_id'     => 'block-1',
				'block_anchor' => '1x2y3z',
				'category'     => 'flow',
				'severity'     => 'important',
				'title'        => 'Abrupt transition',
				'feedback'     => 'The section jumps topics.',
			)
		);

		$this->assertSame( '1x2y3z', $meta['block_anchor'] );
	}

	/**
	 * An AI reply's edit is stored as JSON.
	 */
	public function test_stores_reply_edit(): void {
		$edit = array(
			'type'    => 'replace_text',
			'find'    => 'last year',
			'replace' => 'in 2025',
		);
		$meta = $this->build_note_meta(
			array(
				'block_id'   => 'block-1',
				'feedback'   => 'Done.',
				'reply_edit' => $edit,
			)
		);

		$this->assertSame( $edit, Notes_Manager::decode_reply_edit( $meta[ Notes_Manager::REPLY_EDIT_META_KEY ] ) );
	}

	/**
	 * An AI reply's edit revision is stored, and nothing is stored without one.
	 */
	public function test_stores_revision(): void {
		$meta = $this->build_note_meta(
			array(
				'feedback' => 'Done.',
				'revision' => 2,
			)
		);
		$this->assertSame( 2, $meta[ Notes_Manager::REVISION_META_KEY ] );

		$meta = $this->build_note_meta( array( 'feedback' => 'Done.' ) );
		$this->assertArrayNotHasKey( Notes_Manager::REVISION_META_KEY, $meta );
	}

	/**
	 * Data provider for stored reply edits.
	 *
	 * @return array<string, array{0: mixed, 1: array<string, string>|null}>
	 */
	public static function stored_reply_edit_provider(): array {
		return array(
			'none'             => array( '', null ),
			'not a string'     => array( array( 'type' => 'replace_text' ), null ),
			'invalid JSON'     => array( '{nope', null ),
			'unknown type'     => array( '{"type":"delete_block"}', null ),
			'missing field'    => array( '{"type":"replace_text","find":"a"}', null ),
			'non-string field' => array( '{"type":"rewrite_block","content":"a","original":5}', null ),
			'text replacement' => array(
				'{"type":"replace_text","find":"a","replace":"b","extra":"x"}',
				array(
					'type'    => 'replace_text',
					'find'    => 'a',
					'replace' => 'b',
				),
			),
			'block rewrite'    => array(
				'{"type":"rewrite_block","content":"<em>New</em>","original":"Old"}',
				array(
					'type'     => 'rewrite_block',
					'content'  => '<em>New</em>',
					'original' => 'Old',
				),
			),
		);
	}

	/**
	 * Stored reply edits are decoded only when well formed.
	 *
	 * @dataProvider stored_reply_edit_provider
	 *
	 * @param mixed                      $stored   Stored meta value.
	 * @param array<string, string>|null $expected Decoded edit.
	 */
	public function test_decode_reply_edit( mixed $stored, ?array $expected ): void {
		$this->assertSame( $expected, Notes_Manager::decode_reply_edit( $stored ) );
	}
	/**
	 * A review note's structural edit is stored as JSON.
	 */
	public function test_stores_block_action(): void {
		$action = array(
			'type'            => 'move_block',
			'position'        => 'before',
			'target_block_id' => 'block-0',
			'target_name'     => 'core/heading',
			'target_text'     => 'Why this matters',
		);
		$meta   = $this->build_note_meta(
			array(
				'block_id'     => 'block-1',
				'feedback'     => 'Move this up.',
				'block_action' => $action,
			)
		);

		$this->assertSame( $action, Notes_Manager::decode_block_action( $meta[ Notes_Manager::BLOCK_ACTION_META_KEY ] ) );

		$meta = $this->build_note_meta( array( 'feedback' => 'No action.' ) );
		$this->assertArrayNotHasKey( Notes_Manager::BLOCK_ACTION_META_KEY, $meta );
	}

	/**
	 * Data provider for stored structural edits.
	 *
	 * @return array<string, array{0: mixed, 1: array<string, mixed>|null}>
	 */
	public static function stored_block_action_provider(): array {
		return array(
			'none'                    => array( '', null ),
			'invalid JSON'            => array( '{nope', null ),
			'unknown type'            => array( '{"type":"split_block","position":"before"}', null ),
			'remove'                  => array( '{"type":"remove_block","extra":"x"}', array( 'type' => 'remove_block' ) ),
			'move without position'   => array( '{"type":"move_block","target_block_id":"a","target_name":"core/paragraph","target_text":"A"}', null ),
			'move missing target'     => array( '{"type":"move_block","position":"after","target_name":"core/paragraph","target_text":"A"}', null ),
			'move'                    => array(
				'{"type":"move_block","position":"after","target_block_id":"","target_name":"core/paragraph","target_text":"A"}',
				array(
					'type'            => 'move_block',
					'position'        => 'after',
					'target_block_id' => '',
					'target_name'     => 'core/paragraph',
					'target_text'     => 'A',
				),
			),
			'insert without block'    => array( '{"type":"insert_block","position":"after"}', null ),
			'insert without content'  => array( '{"type":"insert_block","position":"after","block":{"name":"core/paragraph"}}', null ),
			'insert with mixed items' => array( '{"type":"insert_block","position":"after","block":{"name":"core/list","items":["a",1]}}', null ),
			'insert not allowed'      => array( '{"type":"insert_block","position":"after","block":{"name":"core/html","content":"x"}}', null ),
			'insert too many items'   => array(
				(string) json_encode(
					array(
						'type'     => 'insert_block',
						'position' => 'after',
						'block'    => array(
							'name'  => 'core/list',
							'items' => array_fill( 0, 21, 'a' ),
						),
					)
				),
				null,
			),
			'insert too long'         => array(
				(string) json_encode(
					array(
						'type'     => 'insert_block',
						'position' => 'after',
						'block'    => array(
							'name'    => 'core/paragraph',
							'content' => str_repeat( 'a', 1001 ),
						),
					)
				),
				null,
			),
			'heading level clamped'   => array(
				'{"type":"insert_block","position":"before","block":{"name":"core/heading","content":"Hi","level":9}}',
				array(
					'type'     => 'insert_block',
					'position' => 'before',
					'block'    => array(
						'name'    => 'core/heading',
						'content' => 'Hi',
						'level'   => 2,
					),
				),
			),
			'insert heading'          => array(
				'{"type":"insert_block","position":"before","block":{"name":"core/heading","content":"Hi","level":3,"extra":"x"}}',
				array(
					'type'     => 'insert_block',
					'position' => 'before',
					'block'    => array(
						'name'    => 'core/heading',
						'content' => 'Hi',
						'level'   => 3,
					),
				),
			),
			'insert list'             => array(
				'{"type":"insert_block","position":"after","block":{"name":"core/list","items":["a","b"]}}',
				array(
					'type'     => 'insert_block',
					'position' => 'after',
					'block'    => array(
						'name'    => 'core/list',
						'items'   => array( 'a', 'b' ),
						'ordered' => false,
					),
				),
			),
		);
	}

	/**
	 * Stored structural edits are decoded only when well formed, and also
	 * read as reply edits.
	 *
	 * @dataProvider stored_block_action_provider
	 *
	 * @param mixed                     $stored   Stored meta value.
	 * @param array<string, mixed>|null $expected Decoded edit.
	 */
	public function test_decode_block_action( mixed $stored, ?array $expected ): void {
		$this->assertSame( $expected, Notes_Manager::decode_block_action( $stored ) );
		if ( null !== $expected ) {
			$this->assertSame( $expected, Notes_Manager::decode_reply_edit( $stored ) );
		}
	}
}
