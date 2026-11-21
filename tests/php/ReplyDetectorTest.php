<?php
/**
 * Tests for Reply_Detector.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Mentions;
use AI_Feedback\Reply_Detector;

require_once dirname( __DIR__, 2 ) . '/includes/class-reply-detector.php';
require_once __DIR__ . '/reply-cron-mocks.php';

/**
 * Test cases for Reply_Detector.
 */
class ReplyDetectorTest extends TestCase {

	/**
	 * Detector instance.
	 */
	private Reply_Detector $detector;

	/**
	 * Set up global mocks for comment meta and action dispatch.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->detector = new Reply_Detector();

		$GLOBALS['test_comment_meta']     = array();
		$GLOBALS['test_dispatched_calls'] = array();
		$GLOBALS['test_comments']         = array();

		if ( ! function_exists( 'AI_Feedback\\Tests\\__register_mocks' ) ) {
			require_once __DIR__ . '/reply-detector-mocks.php';
		}
	}

	/**
	 * Clean up globals after each test.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['test_comment_meta'], $GLOBALS['test_dispatched_calls'], $GLOBALS['test_comments'], $GLOBALS['test_user_can'] );
		parent::tearDown();
	}

	/**
	 * A user reply to an AI note dispatches the action.
	 */
	public function test_user_reply_to_ai_note_dispatches_action(): void {
		// Parent (comment 100) is an AI Feedback note.
		$GLOBALS['test_comment_meta'][100] = array(
			'ai_feedback' => '1',
			'block_id'    => 'abc-123',
		);

		$reply = (object) array(
			'comment_type'    => 'note',
			'comment_parent'  => 100,
			'comment_post_ID' => 42,
			'comment_content' => 'Thanks, but I disagree.',
		);

		$this->detector->maybe_dispatch_reply( 200, $reply );

		$dispatched = $GLOBALS['test_dispatched_calls'];
		$this->assertCount( 1, $dispatched );
		$this->assertSame( Reply_Detector::ACTION_REPLY_RECEIVED, $dispatched[0]['hook'] );
		$this->assertSame( array( 200, 100, 42, 'abc-123' ), $dispatched[0]['args'] );
	}

	/**
	 * A reply to a non-AI note is ignored.
	 */
	public function test_reply_to_non_ai_note_is_ignored(): void {
		// Parent has no ai_feedback meta.
		$GLOBALS['test_comment_meta'][100] = array();

		$reply = (object) array(
			'comment_type'    => 'note',
			'comment_parent'  => 100,
			'comment_post_ID' => 42,
			'comment_content' => 'Thanks, but I disagree.',
		);

		$this->detector->maybe_dispatch_reply( 200, $reply );

		$this->assertCount( 0, $GLOBALS['test_dispatched_calls'] );
	}

	/**
	 * A top-level (non-reply) block comment is ignored.
	 */
	public function test_top_level_comment_is_ignored(): void {
		$comment = (object) array(
			'comment_type'    => 'note',
			'comment_parent'  => 0,
			'comment_post_ID' => 42,
			'comment_content' => 'Thanks, but I disagree.',
		);

		$this->detector->maybe_dispatch_reply( 200, $comment );

		$this->assertCount( 0, $GLOBALS['test_dispatched_calls'] );
	}

	/**
	 * A reply authored by AI Feedback itself does not re-trigger detection.
	 */
	public function test_ai_authored_reply_is_ignored(): void {
		$GLOBALS['test_comment_meta'][100] = array( 'ai_feedback' => '1' );
		// The reply itself is flagged as ai_feedback — avoids loops.
		$GLOBALS['test_comment_meta'][200] = array( 'ai_feedback' => '1' );

		$reply = (object) array(
			'comment_type'    => 'note',
			'comment_parent'  => 100,
			'comment_post_ID' => 42,
			'comment_content' => 'Thanks, but I disagree.',
		);

		$this->detector->maybe_dispatch_reply( 200, $reply );

		$this->assertCount( 0, $GLOBALS['test_dispatched_calls'] );
	}

	/**
	 * AI notes written before the Fresh Eyes user (author 0), and after it
	 * (author 41), side by side.
	 *
	 * @return array<string, array{int}>
	 */
	public static function ai_note_authors(): array {
		return array(
			'old note, no author'          => array( 0 ),
			'new note, the Fresh Eyes user' => array( 41 ),
		);
	}

	/**
	 * An AI reply never asks for an AI answer, whoever its author, even one
	 * that mentions Fresh Eyes.
	 *
	 * @dataProvider ai_note_authors
	 *
	 * @param int $author AI note's user ID.
	 */
	public function test_ai_reply_is_ignored_whoever_its_author( int $author ): void {
		$GLOBALS['test_comment_meta'][100] = array(
			'ai_feedback' => '1',
			'block_id'    => 'abc-123',
		);
		$GLOBALS['test_comment_meta'][200] = array( 'ai_feedback' => '1' );

		$this->detector->maybe_dispatch_reply(
			200,
			(object) array(
				'comment_type'    => 'note',
				'comment_parent'  => 100,
				'comment_post_ID' => 42,
				'user_id'         => (string) $author,
				'comment_content' => 'Ask @fresh-eyes again if you like.',
			)
		);

		$this->assertCount( 0, $GLOBALS['test_dispatched_calls'] );
	}

	/**
	 * A person's reply to an AI note is answered, whoever wrote the AI note:
	 * the thread is Fresh Eyes' by its meta, not its author.
	 *
	 * @dataProvider ai_note_authors
	 *
	 * @param int $author AI note's user ID.
	 */
	public function test_reply_to_an_ai_note_is_answered_whoever_its_author( int $author ): void {
		$GLOBALS['test_comment_meta'][100] = array(
			'ai_feedback' => '1',
			'block_id'    => 'abc-123',
		);
		$GLOBALS['test_comments'][100]     = (object) array(
			'comment_parent' => 0,
			'user_id'        => (string) $author,
		);

		$this->detector->maybe_dispatch_reply(
			200,
			(object) array(
				'comment_type'    => 'note',
				'comment_parent'  => 100,
				'comment_post_ID' => 42,
				'user_id'         => '7',
				'comment_content' => 'Thanks, but I disagree.',
			)
		);

		$this->assertCount( 1, $GLOBALS['test_dispatched_calls'] );
		$this->assertSame( array( 200, 100, 42, 'abc-123' ), $GLOBALS['test_dispatched_calls'][0]['args'] );
	}

	/**
	 * A note that only carries the name "Fresh Eyes", with no meta, is a
	 * person's note: a reply to it is not a reply to Fresh Eyes.
	 */
	public function test_a_note_named_fresh_eyes_without_the_meta_is_not_an_ai_note(): void {
		$GLOBALS['test_comment_meta'][100] = array( 'block_id' => 'abc-123' );
		$GLOBALS['test_comments'][100]     = (object) array(
			'comment_parent' => 0,
			'comment_author' => 'Fresh Eyes',
			'user_id'        => '0',
		);

		$this->detector->maybe_dispatch_reply(
			200,
			(object) array(
				'comment_type'    => 'note',
				'comment_parent'  => 100,
				'comment_post_ID' => 42,
				'user_id'         => '7',
				'comment_content' => 'Thanks, but I disagree.',
			)
		);

		$this->assertCount( 0, $GLOBALS['test_dispatched_calls'] );
	}

	/**
	 * A non-note reply (e.g. a regular post comment) is ignored.
	 */
	public function test_non_note_is_ignored(): void {
		$GLOBALS['test_comment_meta'][100] = array( 'ai_feedback' => '1' );

		$comment = (object) array(
			'comment_type'    => 'comment',
			'comment_parent'  => 100,
			'comment_post_ID' => 42,
			'comment_content' => 'Thanks, but I disagree.',
		);

		$this->detector->maybe_dispatch_reply( 200, $comment );

		$this->assertCount( 0, $GLOBALS['test_dispatched_calls'] );
	}

	/**
	 * A reply whose parent has no block_id meta is ignored — consumers
	 * cannot resolve the target block, so dispatching with an empty
	 * block_id is worse than not dispatching at all.
	 */
	public function test_reply_without_parent_block_id_is_ignored(): void {
		$GLOBALS['test_comment_meta'][100] = array( 'ai_feedback' => '1' );

		$reply = (object) array(
			'comment_type'    => 'note',
			'comment_parent'  => 100,
			'comment_post_ID' => 42,
			'comment_content' => 'Thanks, but I disagree.',
		);

		$this->detector->maybe_dispatch_reply( 200, $reply );

		$this->assertCount( 0, $GLOBALS['test_dispatched_calls'] );
	}

	/**
	 * A comment without a valid post association (post_id <= 0) is ignored.
	 */
	public function test_reply_with_invalid_post_id_is_ignored(): void {
		$GLOBALS['test_comment_meta'][100] = array(
			'ai_feedback' => '1',
			'block_id'    => 'abc-123',
		);

		$reply = (object) array(
			'comment_type'    => 'note',
			'comment_parent'  => 100,
			'comment_post_ID' => 0,
			'comment_content' => 'Thanks, but I disagree.',
		);

		$this->detector->maybe_dispatch_reply( 200, $reply );

		$this->assertCount( 0, $GLOBALS['test_dispatched_calls'] );
	}

	/**
	 * WP_Comment stores IDs as numeric strings; those must still dispatch ints.
	 */
	public function test_numeric_string_ids_dispatch_ints(): void {
		$GLOBALS['test_comment_meta'][100] = array(
			'ai_feedback' => '1',
			'block_id'    => 'abc-123',
		);

		$reply = (object) array(
			'comment_type'    => 'note',
			'comment_parent'  => '100',
			'comment_post_ID' => '42',
			'comment_content' => 'Thanks, but I disagree.',
		);

		$this->detector->maybe_dispatch_reply( 200, $reply );

		$this->assertSame( array( 200, 100, 42, 'abc-123' ), $GLOBALS['test_dispatched_calls'][0]['args'] );
	}

	/**
	 * A non-string block_id meta value is treated as missing.
	 */
	public function test_non_string_block_id_is_ignored(): void {
		$GLOBALS['test_comment_meta'][100] = array(
			'ai_feedback' => '1',
			'block_id'    => array( 'abc-123' ),
		);

		$reply = (object) array(
			'comment_type'    => 'note',
			'comment_parent'  => 100,
			'comment_post_ID' => 42,
			'comment_content' => 'Thanks, but I disagree.',
		);

		$this->detector->maybe_dispatch_reply( 200, $reply );

		$this->assertCount( 0, $GLOBALS['test_dispatched_calls'] );
	}

	/**
	 * A reply to the AI's own reply is routed to the thread's root note.
	 */
	public function test_reply_to_ai_reply_routes_to_thread_root(): void {
		// 100 is the root AI note; 150 is an AI reply under it.
		$GLOBALS['test_comment_meta'][100] = array(
			'ai_feedback' => '1',
			'block_id'    => 'abc-123',
		);
		$GLOBALS['test_comment_meta'][150] = array(
			'ai_feedback' => '1',
			'block_id'    => 'abc-123',
		);
		$GLOBALS['test_comments'][100]     = (object) array( 'comment_parent' => 0 );
		$GLOBALS['test_comments'][150]     = (object) array( 'comment_parent' => '100' );

		$reply = (object) array(
			'comment_type'    => 'note',
			'comment_parent'  => 150,
			'comment_post_ID' => 42,
			'comment_content' => 'Thanks, but I disagree.',
		);

		$this->detector->maybe_dispatch_reply( 200, $reply );

		$this->assertSame( array( 200, 100, 42, 'abc-123' ), $GLOBALS['test_dispatched_calls'][0]['args'] );
	}

	/**
	 * Spam and trashed notes never trigger an AI reply.
	 */
	public function test_spam_or_trashed_reply_is_ignored(): void {
		$GLOBALS['test_comment_meta'][100] = array(
			'ai_feedback' => '1',
			'block_id'    => 'abc-123',
		);

		foreach ( array( 'spam', 'trash' ) as $approved ) {
			$reply = (object) array(
				'comment_type'     => 'note',
				'comment_parent'   => 100,
				'comment_post_ID'  => 42,
				'comment_content'  => 'Thanks, but I disagree.',
				'comment_approved' => $approved,
			);
			$this->detector->maybe_dispatch_reply( 200, $reply );
		}

		$this->assertCount( 0, $GLOBALS['test_dispatched_calls'] );
	}

	/**
	 * Resolving a thread in the editor posts an empty reply before core
	 * saves its `_wp_note_status` meta, so emptiness is the only signal.
	 */
	public function test_empty_resolve_marker_reply_is_ignored(): void {
		$GLOBALS['test_comment_meta'][100] = array(
			'ai_feedback' => '1',
			'block_id'    => 'abc-123',
		);

		foreach ( array( '', "  \n\t" ) as $content ) {
			$reply = (object) array(
				'comment_type'     => 'note',
				'comment_parent'   => 100,
				'comment_post_ID'  => 42,
				'comment_approved' => '1',
				'comment_content'  => $content,
			);
			$this->detector->maybe_dispatch_reply( 200, $reply );
		}

		$this->assertCount( 0, $GLOBALS['test_dispatched_calls'] );
	}

	/**
	 * A resolve or reopen marker inserted with its status meta is ignored,
	 * even when it carries text.
	 */
	public function test_note_status_marker_is_ignored(): void {
		$GLOBALS['test_comment_meta'][100] = array(
			'ai_feedback' => '1',
			'block_id'    => 'abc-123',
		);

		foreach ( array( 'resolved', 'reopen' ) as $status ) {
			$GLOBALS['test_comment_meta'][200] = array( '_wp_note_status' => $status );

			$reply = (object) array(
				'comment_type'    => 'note',
				'comment_parent'  => 100,
				'comment_post_ID' => 42,
				'comment_content' => 'Reopening this one.',
			);
			$this->detector->maybe_dispatch_reply( 200, $reply );
		}

		$this->assertCount( 0, $GLOBALS['test_dispatched_calls'] );
	}

	/**
	 * Reopening a thread with text in the editor is a real reply, so it still
	 * gets an AI response. Core saves `_wp_note_status` only after the
	 * `wp_insert_comment` action, so the detector sees text and no meta.
	 */
	public function test_reopen_with_text_before_meta_saved_dispatches(): void {
		$GLOBALS['test_comment_meta'] = array(
			100 => array(
				'ai_feedback' => '1',
				'block_id'    => 'abc-123',
			),
		);

		$reply = (object) array(
			'comment_type'     => 'note',
			'comment_parent'   => 100,
			'comment_post_ID'  => 42,
			'comment_approved' => '0',
			'comment_content'  => 'Reopening, still an issue.',
		);

		$this->detector->maybe_dispatch_reply( 200, $reply );

		$calls = $GLOBALS['test_dispatched_calls'];
		$this->assertSame(
			array(
				array(
					'hook' => Reply_Detector::ACTION_REPLY_RECEIVED,
					'args' => array( 200, 100, 42, 'abc-123' ),
				),
			),
			$calls
		);
	}

	/**
	 * Build a note by user 7 on post 42.
	 *
	 * @param string $content Note content.
	 * @param int    $parent  Parent note ID, or 0 for a new thread.
	 * @return object
	 */
	private static function note( string $content, int $parent = 0 ): object {
		return (object) array(
			'comment_type'    => 'note',
			'comment_parent'  => $parent,
			'comment_post_ID' => 42,
			'comment_content' => $content,
			'user_id'         => '7',
		);
	}

	/**
	 * A new note that mentions Fresh Eyes starts a conversation in its own thread.
	 */
	public function test_top_level_mention_dispatches_in_its_own_thread(): void {
		$this->detector->maybe_dispatch_reply( 300, self::note( '@fresh-eyes simplify this' ) );

		$this->assertSame(
			array(
				array(
					'hook' => Reply_Detector::ACTION_REPLY_RECEIVED,
					'args' => array( 300, 300, 42, '' ),
				),
			),
			$GLOBALS['test_dispatched_calls']
		);
		$this->assertSame( '1', $GLOBALS['test_comment_meta'][300][ Mentions::META_KEY ] ?? null );
	}

	/**
	 * A reply that mentions Fresh Eyes in a thread a person started is answered there.
	 */
	public function test_reply_mention_in_human_thread_dispatches_to_root(): void {
		$GLOBALS['test_comments'][301] = (object) array( 'comment_parent' => 300 );

		$this->detector->maybe_dispatch_reply( 302, self::note( 'Agreed. @FreshEyes can you shorten it?', 301 ) );

		$this->assertSame( array( 302, 300, 42, '' ), $GLOBALS['test_dispatched_calls'][0]['args'] ?? null );
	}

	/**
	 * The thread's block ID is passed on when the root note has one.
	 */
	public function test_mention_uses_root_block_id(): void {
		$GLOBALS['test_comment_meta'][300] = array( 'block_id' => 'block-9' );

		$this->detector->maybe_dispatch_reply( 302, self::note( '@fresh-eyes thoughts?', 300 ) );

		$this->assertSame( array( 302, 300, 42, 'block-9' ), $GLOBALS['test_dispatched_calls'][0]['args'] ?? null );
	}

	/**
	 * In a thread a person started, notes that do not mention Fresh Eyes are left alone.
	 */
	public function test_reply_without_mention_in_human_thread_is_ignored(): void {
		$this->detector->maybe_dispatch_reply( 302, self::note( 'I think fresh eyes would help.', 300 ) );

		$this->assertSame( array(), $GLOBALS['test_dispatched_calls'] );
		$this->assertArrayNotHasKey( 302, $GLOBALS['test_comment_meta'] );
	}

	/**
	 * A mention someone quotes does not ask Fresh Eyes anything.
	 */
	public function test_quoted_mention_is_ignored(): void {
		$this->detector->maybe_dispatch_reply( 300, self::note( '<blockquote>@fresh-eyes simplify this</blockquote> That was a bad idea.' ) );

		$this->assertSame( array(), $GLOBALS['test_dispatched_calls'] );
	}

	/**
	 * A note by someone who could not run a review is not answered.
	 */
	public function test_mention_by_user_who_cannot_review_is_ignored(): void {
		$GLOBALS['test_user_can'] = static fn ( int $user_id, string $cap ): bool => 'edit_posts' === $cap;

		$this->detector->maybe_dispatch_reply( 300, self::note( '@fresh-eyes simplify this' ) );

		$this->assertSame( array(), $GLOBALS['test_dispatched_calls'] );
		$this->assertArrayNotHasKey( 300, $GLOBALS['test_comment_meta'] );
	}

	/**
	 * A note without a user, such as one inserted by code, is not answered.
	 */
	public function test_mention_without_user_is_ignored(): void {
		$note          = self::note( '@fresh-eyes simplify this' );
		$note->user_id = '0';

		$this->detector->maybe_dispatch_reply( 300, $note );

		$this->assertSame( array(), $GLOBALS['test_dispatched_calls'] );
	}

	/**
	 * An AI note that mentions Fresh Eyes never triggers another answer.
	 */
	public function test_ai_note_mentioning_fresh_eyes_is_ignored(): void {
		$GLOBALS['test_comment_meta'][303] = array( 'ai_feedback' => '1' );

		$this->detector->maybe_dispatch_reply( 303, self::note( 'Ask @fresh-eyes again any time.', 300 ) );

		$this->assertSame( array(), $GLOBALS['test_dispatched_calls'] );
	}

	/**
	 * In a thread Fresh Eyes started, a mention is an ordinary reply.
	 */
	public function test_mention_in_ai_thread_is_an_ordinary_reply(): void {
		$GLOBALS['test_comment_meta'][100] = array(
			'ai_feedback' => '1',
			'block_id'    => 'abc-123',
		);

		$this->detector->maybe_dispatch_reply( 200, self::note( '@fresh-eyes why?', 100 ) );

		$this->assertSame( array( 200, 100, 42, 'abc-123' ), $GLOBALS['test_dispatched_calls'][0]['args'] ?? null );
		$this->assertArrayNotHasKey( Mentions::META_KEY, $GLOBALS['test_comment_meta'][200] ?? array() );
	}
}
