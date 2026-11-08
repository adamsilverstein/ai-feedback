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
		$this->assertTrue( $this->chunks->validate( 1, 10, $review_id, 1, 3 ) );
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

		$this->assertTrue( $this->chunks->validate( 1, 10, $review_id, 1, 3 ) );
		$this->assertWPError( $this->chunks->validate( 1, 10, $review_id, 2, 3 ) );

		$this->chunks->advance( 1, 1 );
		$this->assertWPError( $this->chunks->validate( 1, 10, $review_id, 1, 3 ) );
		$this->assertTrue( $this->chunks->validate( 1, 10, $review_id, 2, 3 ) );

		$this->chunks->advance( 1, 2 );
		$this->assertWPError( $this->chunks->validate( 1, 10, $review_id, 2, 3 ) );
		$this->assertArrayNotHasKey( Review_Chunks::TRANSIENT_PREFIX . '1', $GLOBALS['test_transients'] );
	}

	/**
	 * A failed chunk can be retried because the session only advances on success.
	 */
	public function test_failed_chunk_can_be_retried(): void {
		$review_id = $this->chunks->start( 1, 10, 2 );

		$this->assertTrue( $this->chunks->validate( 1, 10, $review_id, 1, 2 ) );
		$this->assertTrue( $this->chunks->validate( 1, 10, $review_id, 1, 2 ) );
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
	public function test_validate_rejects_mismatched_chunks( int $user_id, int $post_id, string $review_id, int $index, int $total ): void {
		$session_id = $this->chunks->start( 1, 10, 3 );
		$review_id  = 'valid' === $review_id ? $session_id : $review_id;

		$result = $this->chunks->validate( $user_id, $post_id, $review_id, $index, $total );

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

		$this->assertWPError( $this->chunks->validate( 1, 10, $first, 1, 3 ) );
		$this->assertTrue( $this->chunks->validate( 1, 10, $second, 1, 3 ) );
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
