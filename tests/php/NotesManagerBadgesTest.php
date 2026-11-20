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
require_once __DIR__ . '/reply-service-mocks.php';

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
		unset( $GLOBALS['test_comment_meta'], $GLOBALS['test_comments'] );
	}

	/**
	 * Build a note.
	 *
	 * @param  int    $id   Comment ID.
	 * @param  string $type Comment type.
	 * @return \WP_Comment Note.
	 */
	private function note( int $id = 7, string $type = 'note' ): \WP_Comment {
		$note                   = ( new \ReflectionClass( \WP_Comment::class ) )->newInstanceWithoutConstructor();
		$note->comment_ID       = (string) $id;
		$note->comment_type     = $type;
		$note->comment_post_ID  = '1';
		$note->comment_parent   = '0';
		$note->comment_author   = 'Fresh Eyes';
		$note->comment_content  = '';
		$note->comment_date     = '2026-01-01 00:00:00';
		$note->comment_approved = '0';
		return $note;
	}

	/**
	 * Call a private Notes_Manager method.
	 *
	 * @param  string       $method Method name.
	 * @param  array<mixed> $args   Arguments.
	 * @return mixed Return value.
	 */
	private function call_private( string $method, array $args ): mixed {
		$manager = ( new \ReflectionClass( Notes_Manager::class ) )->newInstanceWithoutConstructor();
		return ( new \ReflectionMethod( Notes_Manager::class, $method ) )->invokeArgs( $manager, $args );
	}

	/**
	 * New notes store only the feedback text, not the badges.
	 */
	public function test_note_content_has_no_badges(): void {
		$content = $this->call_private(
			'build_note_content',
			array(
				array(
					'title'    => 'Title',
					'feedback' => 'Feedback.',
					'category' => 'tone',
					'severity' => 'critical',
				),
			)
		);

		$this->assertSame( "<strong>Title</strong>\n\nFeedback.", $content );
		$this->assertStringNotContainsString( 'ai-feedback-badge', $content );
	}

	/**
	 * The REST note's rendered content gets badges while the raw content does not.
	 */
	public function test_rest_rendered_content_has_badges(): void {
		$note                  = $this->note();
		$note->comment_content = 'Feedback.';

		$formatted = $this->call_private( 'format_single_note', array( $note ) );

		$this->assertIsArray( $formatted );
		$this->assertSame(
			array(
				'raw'      => 'Feedback.',
				'rendered' => "Feedback.\n\n" . self::BADGES,
			),
			$formatted['content']
		);
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

	/**
	 * Add an AI reply to note 7's thread.
	 *
	 * @param  int                   $id   Comment ID.
	 * @param  array<string, string> $meta Meta besides `ai_feedback`.
	 * @return \WP_Comment Reply.
	 */
	private function ai_reply( int $id, array $meta = array() ): \WP_Comment {
		$reply                            = $this->note( $id );
		$reply->comment_parent            = '7';
		$GLOBALS['test_comments'][ $id ]  = $reply;
		$GLOBALS['test_comment_meta'][ $id ] = array( 'ai_feedback' => '1' ) + $meta;
		return $reply;
	}

	/**
	 * AI replies with an edit are labeled in thread order, after the
	 * review's quick fix, whatever revision an earlier version stored.
	 */
	public function test_reply_edit_gets_revision_badge(): void {
		$edit = array( Notes_Manager::REPLY_EDIT_META_KEY => '{"type":"replace_text","find":"a","replace":"b"}' );

		$GLOBALS['test_comments']                          = array( 7 => $this->note() );
		$GLOBALS['test_comment_meta'][7]['feedback_quick_fix'] = '{"find":"a","replace":"c"}';
		$first   = $this->ai_reply( 8, $edit + array( 'feedback_revision' => '2' ) );
		$no_edit = $this->ai_reply( 9 );
		$second  = $this->ai_reply( 10, $edit + array( 'feedback_revision' => '2' ) );

		$badge = static fn( string $label ): string => "Done.\n\n" . '<span class="ai-feedback-badge ai-feedback-revision">' . $label . '</span>';
		$this->assertSame( $badge( 'Revised suggestion (2)' ), Notes_Manager::add_badges_to_comment_text( 'Done.', $first ) );
		$this->assertSame( 'Done.', Notes_Manager::add_badges_to_comment_text( 'Done.', $no_edit ) );
		$this->assertSame( $badge( 'Revised suggestion (3)' ), Notes_Manager::add_badges_to_comment_text( 'Done.', $second ) );

		// Without a review edit, the first reply edit is the first revision.
		unset( $GLOBALS['test_comment_meta'][7]['feedback_quick_fix'] );
		$this->assertSame( $badge( 'Suggested edit' ), Notes_Manager::add_badges_to_comment_text( 'Done.', $first ) );
	}
}
