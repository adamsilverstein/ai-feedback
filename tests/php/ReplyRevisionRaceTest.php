<?php
/**
 * Tests for numbering edits that two replies in one thread save at once.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Notes_Manager;
use AI_Feedback\Prompt_Builder;
use AI_Feedback\Reply_Service;
use AI_Feedback\Reply_Status_Controller;

require_once dirname( __DIR__, 2 ) . '/includes/class-reply-cron-dispatcher.php';
require_once __DIR__ . '/reply-cron-mocks.php';
require_once __DIR__ . '/reply-status-controller-bootstrap.php';
require_once __DIR__ . '/reply-service-mocks.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-reply-service.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-reply-status-controller.php';

/**
 * Reply service that answers a second reply while the first waits on the AI.
 */
class Race_Reply_Service extends Reply_Service {

	/**
	 * Reply to answer during the next AI call, as a second request would.
	 *
	 * @var int|null
	 */
	public ?int $overlapping_reply = null;

	/**
	 * Answer the overlapping reply, then return an edit.
	 *
	 * @param string $prompt             Prompt.
	 * @param string $system_instruction System instruction.
	 * @param string $model              Model.
	 * @return string|\WP_Error
	 */
	protected function call_ai( string $prompt, string $system_instruction, string $model ): string|\WP_Error {
		$overlapping             = $this->overlapping_reply;
		$this->overlapping_reply = null;
		if ( null !== $overlapping ) {
			$this->handle_reply( $overlapping, 100, 42, 'abc-123' );
		}
		return '{"reply": "Done.", "edit": {"type": "replace_text", "find": "last year", "replace": "in 2025"}}';
	}
}

/**
 * Notes manager that saves AI replies, with their real meta, in the fake store.
 */
class Race_Notes_Manager extends Notes_Manager {

	/**
	 * AI replies saved, oldest first.
	 *
	 * @var list<\WP_Comment>
	 */
	public array $saved = array();

	/**
	 * Save the AI reply as a note in the thread.
	 *
	 * @param array<string, mixed> $feedback_item Feedback item.
	 * @param int                  $post_id       Post ID.
	 * @param int                  $parent_id     Parent note ID.
	 * @param array<string, mixed> $review_data   Review data.
	 * @return int|\WP_Error
	 */
	public function add_reply_to_thread( array $feedback_item, int $post_id, int $parent_id, array $review_data = array() ): int|\WP_Error {
		$id   = 900 + count( $this->saved );
		$meta = ( new \ReflectionMethod( Notes_Manager::class, 'build_note_meta' ) )->invoke( $this, $feedback_item, $review_data );

		$reply                           = new \WP_Comment(
			(object) array(
				'comment_ID'      => (string) $id,
				'comment_post_ID' => (string) $post_id,
				'comment_parent'  => (string) $parent_id,
				'comment_author'  => 'Fresh Eyes',
				'comment_content' => 'Done.',
			)
		);
		$this->saved[]                   = $reply;
		$GLOBALS['test_comments'][ $id ] = $reply;
		$GLOBALS['test_comment_meta'][ $id ] = array_map(
			static fn( $value ) => is_scalar( $value ) ? (string) $value : $value,
			array_filter( is_array( $meta ) ? $meta : array(), static fn( $value ) => null !== $value )
		);

		return $id;
	}
}

/**
 * Revision numbers of edits saved at the same time.
 */
class ReplyRevisionRaceTest extends TestCase {

	/**
	 * A thread on note 100 in post 42, with two replies asking for a change.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['test_options']            = array();
		$GLOBALS['test_comment_meta']       = array( 100 => array( 'ai_feedback' => '1' ) );
		$GLOBALS['test_post_content']       = array( 42 => '<!-- post 42 -->' );
		$GLOBALS['test_parse_blocks_calls'] = 0;
		$GLOBALS['test_parsed_blocks']      = array(
			'<!-- post 42 -->' => array(
				array(
					'blockName'    => 'core/paragraph',
					'attrs'        => array( 'metadata' => array( 'noteId' => 100 ) ),
					'innerBlocks'  => array(),
					'innerHTML'    => '<p>Revenue grew 40% last year.</p>',
					'innerContent' => array( '<p>Revenue grew 40% last year.</p>' ),
				),
			),
		);

		$base                     = array(
			'comment_post_ID' => '42',
			'comment_type'    => 'note',
		);
		$GLOBALS['test_comments'] = array(
			100 => new \WP_Comment(
				(object) ( $base + array(
					'comment_ID'      => '100',
					'comment_author'  => 'Fresh Eyes',
					'comment_content' => 'Vague claim.',
				) )
			),
			200 => new \WP_Comment(
				(object) ( $base + array(
					'comment_ID'      => '200',
					'comment_parent'  => '100',
					'comment_author'  => 'Jane',
					'comment_content' => 'Make that change.',
				) )
			),
			201 => new \WP_Comment(
				(object) ( $base + array(
					'comment_ID'      => '201',
					'comment_parent'  => '100',
					'comment_author'  => 'Sam',
					'comment_content' => 'Make it shorter.',
				) )
			),
		);
	}

	/**
	 * Clean up globals.
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['test_options'],
			$GLOBALS['test_comments'],
			$GLOBALS['test_comment_meta'],
			$GLOBALS['test_post_content'],
			$GLOBALS['test_parsed_blocks'],
			$GLOBALS['test_parse_blocks_calls']
		);
		parent::tearDown();
	}

	/**
	 * Answer reply 200, with reply 201 answered while 200 waits on the AI.
	 *
	 * Both read the thread before either edit is saved.
	 *
	 * @return list<\WP_Comment> The two AI replies, oldest first.
	 */
	private function answer_both_at_once(): array {
		$notes                      = new Race_Notes_Manager();
		$service                    = new Race_Reply_Service( new Prompt_Builder(), $notes );
		$service->overlapping_reply = 201;
		$service->handle_reply( 200, 100, 42, 'abc-123' );

		$this->assertCount( 2, $notes->saved );
		return $notes->saved;
	}

	/**
	 * The sidebar numbers the two edits one after the other.
	 */
	public function test_threads_number_overlapping_edits_in_order(): void {
		$this->answer_both_at_once();

		$request = new \WP_REST_Request();
		$request->set_param( 'post', 42 );
		$data    = ( new Reply_Status_Controller() )->get_threads( $request )->get_data();
		$threads = is_array( $data ) ? (array) $data['threads'] : array();
		$replies = is_array( $threads[100] ?? null ) && is_array( $threads[100]['replies'] ) ? $threads[100]['replies'] : array();

		// Jane's reply, Sam's, then the two answers.
		$this->assertSame( array( null, null, 1, 2 ), array_column( $replies, 'revision' ) );
	}

	/**
	 * The Notes UI labels the two edits one after the other.
	 */
	public function test_badges_number_overlapping_edits_in_order(): void {
		list( $first, $second ) = $this->answer_both_at_once();

		$this->assertSame( "Done.\n\n" . '<span class="ai-feedback-badge ai-feedback-revision">Suggested edit</span>', Notes_Manager::add_badges_to_comment_text( 'Done.', $first ) );
		$this->assertSame( "Done.\n\n" . '<span class="ai-feedback-badge ai-feedback-revision">Revised suggestion (2)</span>', Notes_Manager::add_badges_to_comment_text( 'Done.', $second ) );
	}

	/**
	 * The sidebar and the Notes UI badge count the same thread the same way.
	 *
	 * Replies share one second, are stored out of ID order, and include a
	 * person's reply, a resolve marker and an AI reply whose edit was dropped.
	 */
	public function test_sidebar_and_badges_agree(): void {
		$edit = '{"type":"replace_text","find":"last year","replace":"in 2025"}';
		$GLOBALS['test_comment_meta'][100]['feedback_quick_fix'] = '{"find":"last year","replace":"in 2024"}';

		$replies = array(
			306 => array( 'ai_feedback' => '1', Notes_Manager::REPLY_EDIT_META_KEY => $edit ),
			302 => array( 'ai_feedback' => '1', Notes_Manager::REPLY_EDIT_META_KEY => $edit, 'feedback_revision' => '2' ),
			304 => array( 'ai_feedback' => '1', Notes_Manager::REPLY_EDIT_META_KEY => '{"type":"not_an_edit"}' ),
			301 => array(),
			305 => array( 'ai_feedback' => '1', Notes_Manager::REPLY_EDIT_META_KEY => $edit, 'feedback_revision' => '2' ),
			303 => array( '_wp_note_status' => 'resolved' ),
		);
		$GLOBALS['test_comments'] = array( 100 => $GLOBALS['test_comments'][100] );
		foreach ( $replies as $id => $meta ) {
			$GLOBALS['test_comments'][ $id ]     = new \WP_Comment(
				(object) array(
					'comment_ID'      => (string) $id,
					'comment_post_ID' => '42',
					'comment_parent'  => '100',
					'comment_type'    => 'note',
					'comment_author'  => isset( $meta['ai_feedback'] ) ? 'Fresh Eyes' : 'Jane',
					'comment_content' => 'Reply.',
					'comment_date'    => '2026-11-20 10:00:00',
				)
			);
			$GLOBALS['test_comment_meta'][ $id ] = $meta;
		}

		$request = new \WP_REST_Request();
		$request->set_param( 'post', 42 );
		$data    = ( new Reply_Status_Controller() )->get_threads( $request )->get_data();
		$threads = is_array( $data ) ? (array) $data['threads'] : array();
		$sidebar = array();
		foreach ( is_array( $threads[100] ?? null ) && is_array( $threads[100]['replies'] ) ? $threads[100]['replies'] : array() as $reply ) {
			if ( is_array( $reply ) && null !== $reply['revision'] ) {
				$sidebar[ $reply['id'] ] = $reply['revision'];
			}
		}

		$badges = array();
		foreach ( array_keys( $replies ) as $id ) {
			$text = Notes_Manager::add_badges_to_comment_text( 'Reply.', $GLOBALS['test_comments'][ $id ] );
			if ( is_string( $text ) && 1 === preg_match( '/ai-feedback-revision">(?:Suggested edit|Revised suggestion \((\d+)\))</', $text, $match ) ) {
				$badges[ $id ] = (int) ( $match[1] ?? 1 );
			}
		}
		ksort( $badges );

		// The review's quick fix is 1; the dropped edit, the marker and Jane's reply take no number.
		$this->assertSame(
			array(
				302 => 2,
				305 => 3,
				306 => 4,
			),
			$sidebar
		);
		$this->assertSame( $sidebar, $badges );
	}
}
