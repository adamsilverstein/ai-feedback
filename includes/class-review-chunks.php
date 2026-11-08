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
	 * Lock option prefix, suffixed with the user ID.
	 *
	 * @var string
	 */
	const LOCK_PREFIX = 'ai_feedback_chunk_lock_';

	/**
	 * Seconds after which a session lock is treated as abandoned.
	 *
	 * @var int
	 */
	const LOCK_TTL = 30;

	/**
	 * Seconds after which an in-flight chunk is treated as abandoned.
	 *
	 * Longer than the AI request timeout and retry backoff, so a slow
	 * chunk is not claimed twice.
	 *
	 * @var int
	 */
	const IN_FLIGHT_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Failed chunks allowed per session before it ends.
	 *
	 * @var int
	 */
	const MAX_RETRIES = 2;

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
	 * Claim a follow-up chunk before it is sent to the AI.
	 *
	 * Chunks must arrive in order, for the same post and review, from the
	 * user who started the session. The claim marks the chunk as in flight
	 * under a lock, so a replayed or concurrent request for the same chunk
	 * is rejected instead of skipping the rate limit for another AI call.
	 * Follow with complete() on success or release() on failure.
	 *
	 * @param  int    $user_id   User ID.
	 * @param  int    $post_id   Post being reviewed.
	 * @param  string $review_id Review ID returned for the first chunk.
	 * @param  int    $index     Zero-based chunk index.
	 * @param  int    $total     Number of chunks.
	 * @return true|WP_Error True when the chunk was claimed, or error.
	 */
	public function claim( int $user_id, int $post_id, string $review_id, int $index, int $total ): bool|WP_Error {
		if ( ! $this->acquire_lock( $user_id ) ) {
			return $this->in_progress_error();
		}

		$key     = self::TRANSIENT_PREFIX . $user_id;
		$session = get_transient( $key );

		if (
			$index < 1 ||
			! is_array( $session ) ||
			( $session['review_id'] ?? '' ) !== $review_id ||
			( $session['post_id'] ?? 0 ) !== $post_id ||
			( $session['total'] ?? 0 ) !== $total ||
			( $session['next'] ?? 0 ) !== $index
		) {
			$this->release_lock( $user_id );
			return new WP_Error(
				'invalid_review_chunk',
				__( 'This review chunk does not match an active review. Please start the review again.', 'ai-feedback' ),
				array( 'status' => 409 )
			);
		}

		$in_flight = is_int( $session['in_flight'] ?? null ) ? $session['in_flight'] : 0;
		if ( $in_flight > time() - self::IN_FLIGHT_TTL ) {
			$this->release_lock( $user_id );
			return $this->in_progress_error();
		}

		$session['in_flight'] = time();
		set_transient( $key, $session, self::SESSION_TTL );
		$this->release_lock( $user_id );

		return true;
	}

	/**
	 * Mark a claimed chunk as reviewed and move on to the next one.
	 *
	 * The session ends after the last chunk.
	 *
	 * @param  int $user_id User ID.
	 * @param  int $index   Zero-based chunk index that finished.
	 * @return void
	 */
	public function complete( int $user_id, int $index ): void {
		$this->update_session(
			$user_id,
			static function ( array $session ) use ( $index ): ?array {
				$total = is_int( $session['total'] ?? null ) ? $session['total'] : 0;
				if ( $index + 1 >= $total ) {
					return null;
				}

				$session['next']      = $index + 1;
				$session['in_flight'] = 0;
				return $session;
			}
		);
	}

	/**
	 * Give back a claimed chunk after the review failed, so it can be retried.
	 *
	 * Each session allows MAX_RETRIES failed chunks before it ends and the
	 * user has to start a new review, which counts against the rate limit.
	 *
	 * @param  int $user_id User ID.
	 * @return void
	 */
	public function release( int $user_id ): void {
		$this->update_session(
			$user_id,
			static function ( array $session ): ?array {
				$retries = ( is_int( $session['retries'] ?? null ) ? $session['retries'] : 0 ) + 1;
				if ( $retries > self::MAX_RETRIES ) {
					return null;
				}

				$session['retries']   = $retries;
				$session['in_flight'] = 0;
				return $session;
			}
		);
	}

	/**
	 * Change the user's session under the lock.
	 *
	 * @param  int      $user_id User ID.
	 * @param  callable $change  Receives the session and returns the new one, or null to end it.
	 * @return void
	 */
	private function update_session( int $user_id, callable $change ): void {
		if ( ! $this->acquire_lock( $user_id ) ) {
			// Leave the session to expire on its own rather than race another request.
			return;
		}

		$key     = self::TRANSIENT_PREFIX . $user_id;
		$session = get_transient( $key );

		if ( is_array( $session ) ) {
			$updated = $change( $session );
			if ( is_array( $updated ) ) {
				set_transient( $key, $updated, self::SESSION_TTL );
			} else {
				delete_transient( $key );
			}
		}

		$this->release_lock( $user_id );
	}

	/**
	 * Error for a chunk that another request is already reviewing.
	 *
	 * @return WP_Error Error with status 409.
	 */
	private function in_progress_error(): WP_Error {
		return new WP_Error(
			'review_chunk_in_progress',
			__( 'This part of the review is already in progress.', 'ai-feedback' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Take the user's session lock.
	 *
	 * Uses an INSERT IGNORE on the options table, like WP_Upgrader::create_lock(),
	 * because add_option() checks and inserts in separate queries. A lock
	 * older than LOCK_TTL is treated as abandoned.
	 *
	 * @param  int $user_id User ID.
	 * @return bool True when the lock was acquired.
	 */
	protected function acquire_lock( int $user_id ): bool {
		/**
		 * WordPress database object.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		$name = self::LOCK_PREFIX . $user_id;

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$insert = $wpdb->prepare( "INSERT IGNORE INTO %i ( `option_name`, `option_value`, `autoload` ) VALUES (%s, %s, 'off') /* LOCK */", $wpdb->options, $name, (string) time() );
			if ( null === $insert ) {
				return false;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Atomic lock, must hit the database; prepared above.
			if ( $wpdb->query( $insert ) ) {
				return true;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the lock without a stale option cache.
			$locked_at = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name ) );
			if ( is_numeric( $locked_at ) && (int) $locked_at > time() - self::LOCK_TTL ) {
				return false;
			}

			$this->release_lock( $user_id );
		}

		return false;
	}

	/**
	 * Release the user's session lock.
	 *
	 * @param  int $user_id User ID.
	 * @return void
	 */
	protected function release_lock( int $user_id ): void {
		/**
		 * WordPress database object.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Paired with the INSERT IGNORE lock.
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::LOCK_PREFIX . $user_id ) );
	}
}
