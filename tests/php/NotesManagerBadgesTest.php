<?php
/**
 * Tests for the note badges added by Notes_Manager.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Notes_Manager;

require_once __DIR__ . '/reply-status-controller-bootstrap.php';

/**
 * Test cases for Notes_Manager::add_badges_to_comment_text().
 */
class NotesManagerBadgesTest extends TestCase {

	private const BADGES = '<span class="ai-feedback-badge ai-feedback-category-tone">Tone</span> '
		. '<span class="ai-feedback-badge ai-feedback-severity-critical">Critical</span>';

	/**
	 * Store an AI note's meta.
	 */
	protected function setUp(): void {
		$GLOBALS['test_comment_meta'] = array(
			7 => array(
				'ai_feedback'       => '1',
				'feedback_category' => 'tone',
				'feedback_severity' => 'critical',
			),
		);
	}

	/**
	 * Clear stored meta.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['test_comment_meta'] );
	}

	/**
	 * Build a note.
	 *
	 * @param  int    $id   Comment ID.
	 * @param  string $type Comment type.
	 * @return \WP_Comment Note.
	 */
	private function note( int $id = 7, string $type = 'note' ): \WP_Comment {
		$note               = ( new \ReflectionClass( \WP_Comment::class ) )->newInstanceWithoutConstructor();
		$note->comment_ID   = (string) $id;
		$note->comment_type = $type;
		return $note;
	}

	/**
	 * Badges are added from meta to a note that does not store them.
	 */
	public function test_adds_badges_from_meta(): void {
		$this->assertSame(
			"<strong>Title</strong>\n\nFeedback.\n\n" . self::BADGES,
			Notes_Manager::add_badges_to_comment_text( "<strong>Title</strong>\n\nFeedback.", $this->note() )
		);
	}

	/**
	 * Data for notes saved by earlier versions.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_legacy_badges(): array {
		return array(
			'with classes'    => array( self::BADGES ),
			'classes removed' => array( '<span>Tone</span> <span>Critical</span>' ),
			'spans removed'   => array( 'Tone Critical' ),
		);
	}

	/**
	 * Badges stored by earlier versions are replaced, not repeated.
	 *
	 * @dataProvider data_legacy_badges
	 *
	 * @param string $stored Badges stored in the note.
	 */
	public function test_replaces_stored_badges( string $stored ): void {
		$this->assertSame(
			"Feedback.\n\n" . self::BADGES,
			Notes_Manager::add_badges_to_comment_text( "Feedback.\n\n" . $stored . "\n", $this->note() )
		);
	}

	/**
	 * Feedback that merely ends in the badge words keeps them.
	 */
	public function test_keeps_feedback_ending_in_badge_words(): void {
		$this->assertSame(
			"This needs a Tone Critical\n\n" . self::BADGES,
			Notes_Manager::add_badges_to_comment_text( 'This needs a Tone Critical', $this->note() )
		);
	}

	/**
	 * Notes the plugin did not write are left alone.
	 */
	public function test_ignores_other_notes(): void {
		$this->assertSame( 'Hello', Notes_Manager::add_badges_to_comment_text( 'Hello', $this->note( 8 ) ) );
		$this->assertSame( 'Hello', Notes_Manager::add_badges_to_comment_text( 'Hello', $this->note( 7, 'comment' ) ) );
		$this->assertSame( 'Hello', Notes_Manager::add_badges_to_comment_text( 'Hello' ) );
	}
}
