<?php
/**
 * Tests for the reader persona stored on notes and shown as a badge.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Notes_Manager;
use AI_Feedback\Personas;
use ReflectionMethod;

require_once __DIR__ . '/reply-status-controller-bootstrap.php';

/**
 * Test cases for personas in Notes_Manager.
 */
class NotesManagerPersonaTest extends TestCase {

	/**
	 * Clear stored meta.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['test_comment_meta'] );
		parent::tearDown();
	}

	/**
	 * Call the private build_note_meta() method.
	 *
	 * @param  array<string, mixed> $review_data Review data.
	 * @return array<string, mixed> Note meta.
	 */
	private function build_note_meta( array $review_data ): array {
		$method = new ReflectionMethod( Notes_Manager::class, 'build_note_meta' );
		/** @var array<string, mixed> $meta */
		$meta = $method->invoke(
			new Notes_Manager(),
			array(
				'block_id' => 'block-1',
				'category' => 'content',
				'severity' => 'important',
			),
			$review_data
		);

		return $meta;
	}

	/**
	 * Build an AI note.
	 *
	 * @return \WP_Comment Note.
	 */
	private function note(): \WP_Comment {
		$note                   = ( new \ReflectionClass( \WP_Comment::class ) )->newInstanceWithoutConstructor();
		$note->comment_ID       = '7';
		$note->comment_type     = 'note';
		$note->comment_post_ID  = '1';
		$note->comment_parent   = '0';
		$note->comment_author   = 'Fresh Eyes';
		$note->comment_content  = 'Faster than what?';
		$note->comment_date     = '2026-01-01 00:00:00';
		$note->comment_approved = '0';
		return $note;
	}

	/**
	 * Store an AI note's meta.
	 *
	 * @param string $persona Persona meta, or '' for none.
	 */
	private function store_meta( string $persona ): void {
		$meta = array(
			'ai_feedback'       => '1',
			'feedback_category' => 'content',
			'feedback_severity' => 'important',
		);
		if ( '' !== $persona ) {
			$meta[ Personas::META_KEY ] = $persona;
		}
		$GLOBALS['test_comment_meta'] = array( 7 => $meta );
	}

	/**
	 * A persona review tags each note with its persona.
	 */
	public function test_meta_stores_persona(): void {
		$meta = $this->build_note_meta(
			array(
				'review_id' => 'abc',
				'persona'   => 'skeptical_expert',
			)
		);

		$this->assertSame( 'skeptical_expert', $meta[ Personas::META_KEY ] );
	}

	/**
	 * Notes from the default persona look as they always have.
	 */
	public function test_meta_leaves_out_default_persona(): void {
		$this->assertArrayNotHasKey( Personas::META_KEY, $this->build_note_meta( array( 'persona' => 'copy_editor' ) ) );
		$this->assertArrayNotHasKey( Personas::META_KEY, $this->build_note_meta( array( 'review_id' => 'abc' ) ) );
		$this->assertArrayNotHasKey( Personas::META_KEY, $this->build_note_meta( array( 'persona' => 'unknown_persona' ) ) );
	}

	/**
	 * The persona is stored on each note created through wp_insert_comment(),
	 * which unslashes meta like core, and reads back for the badge.
	 */
	public function test_persona_is_stored_on_inserted_notes(): void {
		require_once __DIR__ . '/fixtures/fake-comments.php';
		require_once dirname( __DIR__, 2 ) . '/includes/class-plugin.php';
		\ai_feedback_test_reset_comments(); // @phpstan-ignore function.notFound (defined in the fixture above)

		$result = ( new Notes_Manager() )->create_notes_from_feedback(
			array(
				array(
					'block_id' => 'block-1',
					'category' => 'content',
					'severity' => 'important',
					'title'    => 'Compared to what?',
					'feedback' => 'Link the benchmark.',
				),
				array(
					'block_id' => 'block-1',
					'category' => 'flow',
					'severity' => 'suggestion',
					'title'    => 'Lead with the number',
					'feedback' => 'Put it first.',
				),
			),
			10,
			array(
				'review_id' => 'r-1',
				'persona'   => 'skeptical_expert',
			)
		);

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result['note_ids'] );
		foreach ( $result['note_ids'] as $note_id ) {
			$this->assertSame( 'skeptical_expert', get_comment_meta( $note_id, Personas::META_KEY, true ) );
			$this->assertSame( 'Skeptical expert', Notes_Manager::get_persona_fields( $note_id )['persona_label'] );
		}
	}

	/**
	 * The note shows a badge naming the persona, before the other badges.
	 */
	public function test_badge_names_the_persona(): void {
		$this->store_meta( 'first_time_visitor' );

		$text = Notes_Manager::add_badges_to_comment_text( 'Faster than what?', $this->note() );

		$this->assertSame(
			"Faster than what?\n\n"
			. '<span class="ai-feedback-badge ai-feedback-persona">First-time visitor</span> '
			. '<span class="ai-feedback-badge ai-feedback-category-content">Content</span> '
			. '<span class="ai-feedback-badge ai-feedback-severity-important">Important</span>',
			$text
		);
	}

	/**
	 * Notes without a persona get no persona badge.
	 */
	public function test_no_badge_without_persona(): void {
		$this->store_meta( '' );

		$text = Notes_Manager::add_badges_to_comment_text( 'Faster than what?', $this->note() );

		$this->assertIsString( $text );
		$this->assertStringNotContainsString( 'ai-feedback-persona', $text );
	}

	/**
	 * The REST note carries the persona ID and label for the sidebar.
	 */
	public function test_persona_fields(): void {
		$this->store_meta( 'busy_skimmer' );
		$this->assertSame(
			array(
				'persona'       => 'busy_skimmer',
				'persona_label' => 'Busy skimmer',
			),
			Notes_Manager::get_persona_fields( 7 )
		);

		// A persona a filter has since removed is not shown.
		$this->store_meta( 'removed_persona' );
		$this->assertSame(
			array(
				'persona'       => '',
				'persona_label' => '',
			),
			Notes_Manager::get_persona_fields( 7 )
		);
	}
}
