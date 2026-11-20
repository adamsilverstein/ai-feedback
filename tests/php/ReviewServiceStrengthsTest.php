<?php
/**
 * Tests for strengths and progress in Review_Service::review_document().
 *
 * Runs in separate processes because each test defines AI_FEEDBACK_MOCK_MODE.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use AI_Feedback\Review_History;
use AI_Feedback\Review_Service;

/**
 * Review service tests with the mock AI response and the fake comment API.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class ReviewServiceStrengthsTest extends TestCase {

	/**
	 * Post under review.
	 *
	 * @var int
	 */
	private const POST_ID = 10;

	/**
	 * Use mock mode and a clean fake comment store.
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once __DIR__ . '/fixtures/fake-comments.php';
		require_once dirname( __DIR__, 2 ) . '/includes/class-plugin.php';

		if ( ! defined( 'AI_FEEDBACK_MOCK_MODE' ) ) {
			define( 'AI_FEEDBACK_MOCK_MODE', true );
		}

		\ai_feedback_test_reset_comments(); // @phpstan-ignore function.notFound (defined in the fixture above)
		$GLOBALS['test_post_meta']        = array();
		$GLOBALS['test_current_user_can'] = true;
	}

	/**
	 * Blocks under review.
	 *
	 * @return list<array<string, string>>
	 */
	private function blocks(): array {
		return array(
			array(
				'clientId' => 'block-1',
				'name'     => 'core/paragraph',
				'content'  => 'The opening example makes the problem concrete.',
			),
			array(
				'clientId' => 'block-2',
				'name'     => 'core/paragraph',
				'content'  => 'A second paragraph.',
			),
		);
	}

	/**
	 * Have the mock AI return this response.
	 *
	 * @param list<array<string, mixed>> $feedback  Feedback items.
	 * @param list<array<string, mixed>> $strengths Strengths.
	 */
	private function mock_response( array $feedback, array $strengths = array() ): void {
		$GLOBALS['test_filter_results'] = array(
			'ai_feedback_mock_review' => array(
				'summary'   => 'A solid draft.',
				'feedback'  => $feedback,
				'strengths' => $strengths,
			),
		);
	}

	/**
	 * A feedback item for a block.
	 *
	 * @param  string $block_id Block ID.
	 * @return array<string, mixed>
	 */
	private function item( string $block_id ): array {
		return array(
			'block_id' => $block_id,
			'category' => 'content',
			'severity' => 'important',
			// Distinct text per block, so the parser doesn't group them.
			'title'    => 'Revisit ' . $block_id,
			'feedback' => 'Something about ' . $block_id . ' could be clearer.',
		);
	}

	/**
	 * Run a review.
	 *
	 * @param  array<mixed> $existing_feedback Feedback from earlier reviews.
	 * @return array<string, mixed>
	 */
	private function review( array $existing_feedback = array() ): array {
		$service = new Review_Service();
		$options = array(
			'post_title'        => 'Draft',
			'blocks'            => $this->blocks(),
			'existing_feedback' => $existing_feedback,
		);

		// do_action() only records calls in these tests, so nothing is
		// added to the history unless a test records it.
		$result = $service->review_document( self::POST_ID, $options );
		$this->assertIsArray( $result );

		$review = array();
		foreach ( $result as $key => $value ) {
			$review[ (string) $key ] = $value;
		}

		return $review;
	}

	/**
	 * Strengths come back beside the feedback and never become notes.
	 */
	public function test_returns_strengths_without_creating_notes(): void {
		$this->mock_response(
			array( $this->item( 'block-2' ) ),
			array(
				array(
					'block_id' => 'block-1',
					'text'     => 'The opening example makes the problem concrete.',
				),
			)
		);

		$result = $this->review();

		$this->assertIsArray( $result['strengths'] );
		$this->assertCount( 1, $result['strengths'] );
		$this->assertSame( 1, $result['note_count'] );
		$this->assertIsArray( $GLOBALS['test_comments'] );
		$this->assertCount( 1, $GLOBALS['test_comments'] );
		$this->assertNull( $result['progress'] );
	}

	/**
	 * The result carries the comparison stored in the history entry.
	 */
	public function test_returns_progress_from_history(): void {
		$this->mock_response( array( $this->item( 'block-2' ) ) );

		$first = $this->review();
		Review_History::record( self::POST_ID, $first );
		$this->assertIsArray( $first['note_ids'] );
		$note_id = $first['note_ids'][0];
		$this->assertIsInt( $note_id );
		update_comment_meta( $note_id, 'ai_feedback_resolved', true );

		// Record the second review the way the hook would, then read it back.
		$service = new class() extends Review_Service {
			/**
			 * Record the review in history, like the plugin's hook.
			 *
			 * @param int                  $post_id Post ID.
			 * @param array<string, mixed> $result  Review result.
			 */
			protected function fire_review_completed( int $post_id, array $result ): void {
				Review_History::record( $post_id, $result );
			}
		};

		$second = $service->review_document(
			self::POST_ID,
			array(
				'post_title' => 'Draft',
				'blocks'     => $this->blocks(),
			)
		);

		$this->assertIsArray( $second );
		$this->assertSame(
			array(
				'addressed'  => 1,
				'still_open' => 0,
			),
			$second['progress']
		);
	}

	/**
	 * A follow-up review adds feedback to a block's open thread, but starts a
	 * new thread rather than hide it in a resolved one.
	 */
	public function test_follow_up_feedback_skips_resolved_threads(): void {
		$this->mock_response( array( $this->item( 'block-1' ), $this->item( 'block-2' ) ) );
		$first = $this->review();
		$this->assertIsArray( $first['block_mapping'] );
		$resolved = $first['block_mapping']['block-1'];
		$open     = $first['block_mapping']['block-2'];
		$this->assertIsInt( $resolved );
		$this->assertIsInt( $open );
		wp_set_comment_status( $resolved, 'approve' );

		$second = $this->review( array( array( 'id' => $open ) ) );

		$this->assertIsArray( $second['block_mapping'] );
		$this->assertSame( $open, $second['block_mapping']['block-2'] );
		$new_thread = $second['block_mapping']['block-1'];
		$this->assertIsInt( $new_thread );
		$this->assertNotSame( $resolved, $new_thread );

		$comment = get_comment( $new_thread );
		$this->assertInstanceOf( \WP_Comment::class, $comment );
		$this->assertSame( '0', $comment->comment_parent );
	}
}
