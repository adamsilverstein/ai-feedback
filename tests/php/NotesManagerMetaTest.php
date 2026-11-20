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
	 * Data provider for stored reply edits.
	 *
	 * @return array<string, array{0: mixed, 1: array<string, string>|null}>
	 */
	public static function stored_reply_edit_provider(): array {
		return array(
			'none'              => array( '', null ),
			'not a string'      => array( array( 'type' => 'replace_text' ), null ),
			'invalid JSON'      => array( '{nope', null ),
			'unknown type'      => array( '{"type":"delete_block"}', null ),
			'missing field'     => array( '{"type":"replace_text","find":"a"}', null ),
			'non-string field'  => array( '{"type":"rewrite_block","content":"a","original":5}', null ),
			'text replacement'  => array(
				'{"type":"replace_text","find":"a","replace":"b","extra":"x"}',
				array(
					'type'    => 'replace_text',
					'find'    => 'a',
					'replace' => 'b',
				),
			),
			'block rewrite'     => array(
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
}
