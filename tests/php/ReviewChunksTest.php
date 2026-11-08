<?php
/**
 * Tests for Review_Chunks session tracking.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Review_Chunks;

/**
 * Test cases for chunked review sessions.
 */
class ReviewChunksTest extends TestCase {

	/**
	 * Review chunks instance.
	 *
	 * @var Review_Chunks
	 */
	private Review_Chunks $chunks;

	/**
	 * Reset transients before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['test_transients'] = array();
		$GLOBALS['test_options']    = array();
		$this->chunks               = new Review_Chunks();
	}

	/**
	 * Chunk size defaults to 20, allowing up to five chunks of 100 blocks.
	 */
	public function test_default_chunk_size(): void {
		$this->assertSame( 20, Review_Chunks::get_chunk_size() );
		$this->assertSame( 5, Review_Chunks::get_max_chunks() );
	}

	/**
	 * Starting a session returns a UUID and stores the session.
	 */
	public function test_start_returns_review_id(): void {
		$review_id = $this->chunks->start( 1, 10, 3 );

		$this->assertIsString( $review_id );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $review_id );
		$this->assertTrue( $this->chunks->claim( 1, 10, $review_id, 1, 3 ) );
	}

	/**
	 * Sessions must have between two and the maximum number of chunks.
	 *
	 * @dataProvider data_invalid_totals
	 *
	 * @param int $total Chunk total.
	 */
	public function test_start_rejects_invalid_totals( int $total ): void {
		$result = $this->chunks->start( 1, 10, $total );

		$this->assertWPError( $result );
		$this->assertSame( 'too_many_blocks', $result->get_error_code() );
	}

	/**
	 * Data provider for invalid chunk totals.
	 *
	 * @return array<string, array{int}>
	 */
	public static function data_invalid_totals(): array {
		return array(
			'single chunk'    => array( 1 ),
			'too many chunks' => array( 6 ),
		);
	}

	/**
	 * Chunks are accepted in order and the session ends after the last one.
	 */
	public function test_chunks_advance_in_order(): void {
		$review_id = $this->chunks->start( 1, 10, 3 );

		$this->assertWPError( $this->chunks->claim( 1, 10, $review_id, 2, 3 ) );
		$this->assertTrue( $this->chunks->claim( 1, 10, $review_id, 1, 3 ) );
		$this->chunks->complete( 1, 1 );

		$this->assertTrue( $this->chunks->claim( 1, 10, $review_id, 2, 3 ) );
		$this->chunks->complete( 1, 2 );

		$this->assertWPError( $this->chunks->claim( 1, 10, $review_id, 2, 3 ) );
		$this->assertArrayNotHasKey( Review_Chunks::TRANSIENT_PREFIX . '1', $GLOBALS['test_transients'] );
	}

	/**
	 * A chunk that is in flight cannot be claimed again, so replays and
	 * concurrent requests cannot skip the rate limit for extra AI calls.
	 */
	public function test_replayed_chunk_is_rejected_while_in_flight(): void {
		$review_id = $this->chunks->start( 1, 10, 3 );

		$this->assertTrue( $this->chunks->claim( 1, 10, $review_id, 1, 3 ) );

		$replay = $this->chunks->claim( 1, 10, $review_id, 1, 3 );
		$this->assertWPError( $replay );
		$this->assertSame( 'review_chunk_in_progress', $replay->get_error_code() );
	}

	/**
	 * A completed chunk cannot be replayed.
	 */
	public function test_completed_chunk_cannot_be_replayed(): void {
		$review_id = $this->chunks->start( 1, 10, 3 );

		$this->assertTrue( $this->chunks->claim( 1, 10, $review_id, 1, 3 ) );
		$this->chunks->complete( 1, 1 );

		$replay = $this->chunks->claim( 1, 10, $review_id, 1, 3 );
		$this->assertWPError( $replay );
		$this->assertSame( 'invalid_review_chunk', $replay->get_error_code() );
	}

	/**
	 * A claim is refused while another request holds the session lock.
	 */
	public function test_claim_refused_while_locked(): void {
		$review_id = $this->chunks->start( 1, 10, 3 );
		$GLOBALS['test_options'][ Review_Chunks::LOCK_PREFIX . '1' ] = (string) time();

		$result = $this->chunks->claim( 1, 10, $review_id, 1, 3 );

		$this->assertWPError( $result );
		$this->assertSame( 'review_chunk_in_progress', $result->get_error_code() );
	}

	/**
	 * An abandoned lock is taken over and released after the claim.
	 */
	public function test_stale_lock_is_taken_over(): void {
		$review_id = $this->chunks->start( 1, 10, 3 );
		$GLOBALS['test_options'][ Review_Chunks::LOCK_PREFIX . '1' ] = (string) ( time() - Review_Chunks::LOCK_TTL - 1 );

		$this->assertTrue( $this->chunks->claim( 1, 10, $review_id, 1, 3 ) );
		$this->assertArrayNotHasKey( Review_Chunks::LOCK_PREFIX . '1', $GLOBALS['test_options'] );
	}

	/**
	 * A failed chunk can be retried a limited number of times.
	 */
	public function test_failed_chunk_retries_are_capped(): void {
		$review_id = $this->chunks->start( 1, 10, 3 );

		for ( $i = 0; $i < Review_Chunks::MAX_RETRIES; $i++ ) {
			$this->assertTrue( $this->chunks->claim( 1, 10, $review_id, 1, 3 ) );
			$this->chunks->release( 1 );
		}

		$this->assertTrue( $this->chunks->claim( 1, 10, $review_id, 1, 3 ) );
		$this->chunks->release( 1 );

		$result = $this->chunks->claim( 1, 10, $review_id, 1, 3 );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_review_chunk', $result->get_error_code() );
	}

	/**
	 * Chunks that do not match the session are rejected.
	 *
	 * @dataProvider data_mismatched_chunks
	 *
	 * @param int    $user_id   User ID.
	 * @param int    $post_id   Post ID.
	 * @param string $review_id Review ID, or 'valid' for the session's ID.
	 * @param int    $index     Chunk index.
	 * @param int    $total     Chunk total.
	 */
	public function test_claim_rejects_mismatched_chunks( int $user_id, int $post_id, string $review_id, int $index, int $total ): void {
		$session_id = $this->chunks->start( 1, 10, 3 );
		$review_id  = 'valid' === $review_id ? $session_id : $review_id;

		$result = $this->chunks->claim( $user_id, $post_id, $review_id, $index, $total );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_review_chunk', $result->get_error_code() );
	}

	/**
	 * Data provider for chunks that do not match the session.
	 *
	 * @return array<string, array{int, int, string, int, int}>
	 */
	public static function data_mismatched_chunks(): array {
		return array(
			'other user'      => array( 2, 10, 'valid', 1, 3 ),
			'other post'      => array( 1, 11, 'valid', 1, 3 ),
			'other review'    => array( 1, 10, '00000000-0000-4000-8000-000000000000', 1, 3 ),
			'different total' => array( 1, 10, 'valid', 1, 4 ),
			'first chunk'     => array( 1, 10, 'valid', 0, 3 ),
			'skipped chunk'   => array( 1, 10, 'valid', 2, 3 ),
		);
	}

	/**
	 * Starting a new review replaces an unfinished one.
	 */
	public function test_start_replaces_unfinished_session(): void {
		$first  = $this->chunks->start( 1, 10, 3 );
		$second = $this->chunks->start( 1, 10, 3 );

		$this->assertWPError( $this->chunks->claim( 1, 10, $first, 1, 3 ) );
		$this->assertTrue( $this->chunks->claim( 1, 10, $second, 1, 3 ) );
	}

	/**
	 * Assert that a value is a WP_Error.
	 *
	 * @param mixed $value Value to check.
	 */
	private function assertWPError( mixed $value ): void {
		$this->assertInstanceOf( \WP_Error::class, $value );
	}
}
