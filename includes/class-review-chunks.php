<?php
/**
 * Review Chunks
 *
 * Tracks chunked reviews, where the editor sends a large document to the
 * review endpoint in several smaller requests.
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

use WP_Error;

/**
 * Review Chunks class.
 *
 * Each user has at most one active chunked review, stored in a short-lived
 * transient. The session ties follow-up chunks to the first request so they
 * share a review ID and count once against the rate limit.
 */
class Review_Chunks {

	/**
	 * Default number of blocks reviewed per request.
	 *
	 * @var int
	 */
	const DEFAULT_CHUNK_SIZE = 20;

	/**
	 * Maximum blocks per review, across all chunks.
	 *
	 * @var int
	 */
	const MAX_BLOCKS = 100;

	/**
	 * How long a chunked review session stays valid, in seconds.
	 *
	 * @var int
	 */
	const SESSION_TTL = 30 * MINUTE_IN_SECONDS;

	/**
	 * Transient key prefix, suffixed with the user ID.
	 *
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'ai_feedback_chunked_review_';

	/**
	 * Get the number of blocks reviewed per request.
	 *
	 * @return int Chunk size between 1 and MAX_BLOCKS.
	 */
	public static function get_chunk_size(): int {
		/**
		 * Filters the number of blocks sent to the AI in each review request.
		 *
		 * Documents with more blocks are reviewed in several requests, which
		 * keeps each AI call short enough to avoid timeouts.
		 *
		 * @param int $chunk_size Blocks per request. Default 20.
		 */
		$size = (int) apply_filters( 'ai_feedback_chunk_size', self::DEFAULT_CHUNK_SIZE );

		return max( 1, min( self::MAX_BLOCKS, $size ) );
	}

	/**
	 * Get the most chunks a single review may be split into.
	 *
	 * @return int Maximum chunk count.
	 */
	public static function get_max_chunks(): int {
		return (int) ceil( self::MAX_BLOCKS / self::get_chunk_size() );
	}

	/**
	 * Read the chunk position from review options.
	 *
	 * @param  array<mixed> $options Review options with an optional `chunk` array.
	 * @return array{index: int, total: int} Zero-based index and chunk count, at least 1.
	 */
	public static function get_position( array $options ): array {
		$chunk = is_array( $options['chunk'] ?? null ) ? $options['chunk'] : array();
		$index = is_numeric( $chunk['index'] ?? null ) ? (int) $chunk['index'] : 0;
		$total = is_numeric( $chunk['total'] ?? null ) ? (int) $chunk['total'] : 1;

		return array(
			'index' => max( 0, $index ),
			'total' => max( 1, $total ),
		);
	}

	/**
	 * Start a chunked review session for a user.
	 *
	 * Replaces any earlier session the user left unfinished.
	 *
	 * @param  int $user_id User ID.
	 * @param  int $post_id Post being reviewed.
	 * @param  int $total   Number of chunks the client will send.
	 * @return string|WP_Error Review ID shared by all chunks, or error.
	 */
	public function start( int $user_id, int $post_id, int $total ): string|WP_Error {
		if ( $total < 2 || $total > self::get_max_chunks() ) {
			return new WP_Error(
				'too_many_blocks',
				__( 'Post has too many blocks (max 100). Please split into smaller posts.', 'ai-feedback' ),
				array( 'status' => 400 )
			);
		}

		$review_id = wp_generate_uuid4();

		set_transient(
			self::TRANSIENT_PREFIX . $user_id,
			array(
				'review_id' => $review_id,
				'post_id'   => $post_id,
				'total'     => $total,
				'next'      => 1,
			),
			self::SESSION_TTL
		);

		return $review_id;
	}

	/**
	 * Check that a follow-up chunk belongs to the user's active session.
	 *
	 * Chunks must arrive in order, for the same post and review, from the
	 * user who started the session.
	 *
	 * @param  int    $user_id   User ID.
	 * @param  int    $post_id   Post being reviewed.
	 * @param  string $review_id Review ID returned for the first chunk.
	 * @param  int    $index     Zero-based chunk index.
	 * @param  int    $total     Number of chunks.
	 * @return true|WP_Error True when the chunk is valid, or error.
	 */
	public function validate( int $user_id, int $post_id, string $review_id, int $index, int $total ): bool|WP_Error {
		$session = get_transient( self::TRANSIENT_PREFIX . $user_id );

		if (
			$index < 1 ||
			! is_array( $session ) ||
			( $session['review_id'] ?? '' ) !== $review_id ||
			( $session['post_id'] ?? 0 ) !== $post_id ||
			( $session['total'] ?? 0 ) !== $total ||
			( $session['next'] ?? 0 ) !== $index
		) {
			return new WP_Error(
				'invalid_review_chunk',
				__( 'This review chunk does not match an active review. Please start the review again.', 'ai-feedback' ),
				array( 'status' => 409 )
			);
		}

		return true;
	}

	/**
	 * Mark a validated chunk as reviewed.
	 *
	 * Called after the chunk succeeds, so a failed chunk can be retried.
	 * The session ends after the last chunk.
	 *
	 * @param  int $user_id User ID.
	 * @param  int $index   Zero-based chunk index that finished.
	 * @return void
	 */
	public function advance( int $user_id, int $index ): void {
		$key     = self::TRANSIENT_PREFIX . $user_id;
		$session = get_transient( $key );

		if ( ! is_array( $session ) ) {
			return;
		}

		$total = is_int( $session['total'] ?? null ) ? $session['total'] : 0;
		if ( $index + 1 >= $total ) {
			delete_transient( $key );
			return;
		}

		$session['next'] = $index + 1;
		set_transient( $key, $session, self::SESSION_TTL );
	}
}
