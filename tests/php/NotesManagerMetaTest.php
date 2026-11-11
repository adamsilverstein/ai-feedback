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
		$method->setAccessible( true );

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
			)
		);

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
}
