<?php
/**
 * Usage Analytics
 *
 * Aggregates completed reviews into daily buckets for the analytics page.
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

/**
 * Analytics class.
 *
 * Data is stored as one non-autoloaded option keyed by site-local date, so
 * its size grows with days of use, not with reviews, and is pruned to
 * MAX_DAYS.
 */
class Analytics {

	/**
	 * Option holding the daily buckets.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'ai_feedback_analytics';

	/**
	 * Number of days kept.
	 *
	 * @var int
	 */
	const MAX_DAYS = 365;

	/**
	 * Supported reporting periods, in days. Zero means all stored data.
	 *
	 * @var array<string, int>
	 */
	const PERIODS = array(
		'7days'  => 7,
		'30days' => 30,
		'90days' => 90,
		'all'    => 0,
	);

	/**
	 * Record a completed review.
	 *
	 * Hooked to `ai_feedback_after_review`.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $review  Review result returned by Review_Service.
	 */
	public static function track_review( int $post_id, array $review ): void {
		/**
		 * Filters whether reviews are counted in usage analytics.
		 *
		 * @param bool  $enabled Whether to record the review. Default true.
		 * @param int   $post_id Post ID.
		 * @param array $review  Review result.
		 */
		if ( ! apply_filters( 'ai_feedback_analytics_enabled', true, $post_id, $review ) ) {
			return;
		}

		// Read-modify-write: two reviews finishing at the same instant can
		// lose one increment. Acceptable for usage stats.
		$days = self::get_days();
		$date = current_time( 'Y-m-d' );
		$day  = $days[ $date ] ?? self::empty_day();

		$summary  = is_array( $review['summary'] ?? null ) ? $review['summary'] : array();
		$model    = is_string( $review['model'] ?? null ) ? substr( sanitize_text_field( $review['model'] ), 0, 100 ) : '';
		$model    = '' !== $model ? $model : 'unknown';
		$feedback = is_numeric( $review['note_count'] ?? null ) ? (int) $review['note_count'] : 0;
		$user_id  = (string) get_current_user_id();

		++$day['reviews'];
		$day['feedback']         += $feedback;
		$day['models'][ $model ]  = ( $day['models'][ $model ] ?? 0 ) + 1;
		$day['users'][ $user_id ] = ( $day['users'][ $user_id ] ?? 0 ) + 1;
		$day['severity']          = self::add_counts( $day['severity'], $summary['by_severity'] ?? array() );
		$day['category']          = self::add_counts( $day['category'], $summary['by_category'] ?? array() );
		$days[ $date ]            = $day;

		ksort( $days );
		$days = array_slice( $days, -self::MAX_DAYS, null, true );

		update_option( self::OPTION_KEY, $days, false );
	}

	/**
	 * Remove a deleted user's counts.
	 *
	 * Hooked to `deleted_user`.
	 *
	 * @param int $user_id Deleted user ID.
	 */
	public static function forget_user( int $user_id ): void {
		$days    = self::get_days();
		$key     = (string) $user_id;
		$changed = false;

		foreach ( $days as $date => $day ) {
			if ( isset( $day['users'][ $key ] ) ) {
				unset( $days[ $date ]['users'][ $key ] );
				$changed = true;
			}
		}

		if ( $changed ) {
			update_option( self::OPTION_KEY, $days, false );
		}
	}

	/**
	 * Summarize usage for a period.
	 *
	 * @param  string $period One of the PERIODS keys.
	 * @return array{period: string, total_reviews: int, total_feedback: int, avg_feedback: float, by_model: array<string, int>, by_severity: array<string, int>, by_category: array<string, int>, by_day: list<array{date: string, reviews: int, feedback: int}>, top_users: list<array{user_id: int, name: string, reviews: int}>}
	 */
	public static function get_summary( string $period = '30days' ): array {
		if ( ! isset( self::PERIODS[ $period ] ) ) {
			$period = '30days';
		}

		$days   = self::get_days();
		$length = self::PERIODS[ $period ];
		$today  = current_time( 'Y-m-d' );

		if ( $length > 0 ) {
			$dates = self::date_range( $today, $length );
		} else {
			$dates = array_keys( $days );
		}

		$totals = self::empty_day();
		$by_day = array();

		foreach ( $dates as $date ) {
			$day      = $days[ $date ] ?? self::empty_day();
			$by_day[] = array(
				'date'     => $date,
				'reviews'  => $day['reviews'],
				'feedback' => $day['feedback'],
			);

			$totals['reviews']  += $day['reviews'];
			$totals['feedback'] += $day['feedback'];
			$totals['models']    = self::add_counts( $totals['models'], $day['models'] );
			$totals['users']     = self::add_counts( $totals['users'], $day['users'] );
			$totals['severity']  = self::add_counts( $totals['severity'], $day['severity'] );
			$totals['category']  = self::add_counts( $totals['category'], $day['category'] );
		}

		arsort( $totals['models'] );
		arsort( $totals['category'] );

		return array(
			'period'         => $period,
			'total_reviews'  => $totals['reviews'],
			'total_feedback' => $totals['feedback'],
			'avg_feedback'   => $totals['reviews'] > 0 ? round( $totals['feedback'] / $totals['reviews'], 1 ) : 0.0,
			'by_model'       => $totals['models'],
			'by_severity'    => $totals['severity'],
			'by_category'    => $totals['category'],
			'by_day'         => $by_day,
			'top_users'      => self::top_users( $totals['users'], 5 ),
		);
	}

	/**
	 * Read the stored daily buckets, discarding anything malformed.
	 *
	 * @return array<string, array{reviews: int, feedback: int, models: array<string, int>, users: array<string, int>, severity: array<string, int>, category: array<string, int>}>
	 */
	public static function get_days(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$days = array();
		foreach ( $stored as $date => $day ) {
			if ( ! is_string( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! is_array( $day ) ) {
				continue;
			}

			$days[ $date ] = array(
				'reviews'  => is_numeric( $day['reviews'] ?? null ) ? (int) $day['reviews'] : 0,
				'feedback' => is_numeric( $day['feedback'] ?? null ) ? (int) $day['feedback'] : 0,
				'models'   => self::add_counts( array(), $day['models'] ?? array() ),
				'users'    => self::add_counts( array(), $day['users'] ?? array() ),
				'severity' => self::add_counts( array(), $day['severity'] ?? array() ),
				'category' => self::add_counts( array(), $day['category'] ?? array() ),
			);
		}

		return $days;
	}

	/**
	 * An empty daily bucket.
	 *
	 * @return array{reviews: int, feedback: int, models: array<string, int>, users: array<string, int>, severity: array<string, int>, category: array<string, int>}
	 */
	private static function empty_day(): array {
		return array(
			'reviews'  => 0,
			'feedback' => 0,
			'models'   => array(),
			'users'    => array(),
			'severity' => array(),
			'category' => array(),
		);
	}

	/**
	 * Add a count map into another, ignoring non-numeric values.
	 *
	 * @param  array<string, int> $totals Running totals.
	 * @param  mixed              $counts Counts to add.
	 * @return array<string, int>
	 */
	private static function add_counts( array $totals, mixed $counts ): array {
		if ( ! is_array( $counts ) ) {
			return $totals;
		}

		foreach ( $counts as $key => $count ) {
			if ( ! is_numeric( $count ) ) {
				continue;
			}
			$key            = (string) $key;
			$totals[ $key ] = ( $totals[ $key ] ?? 0 ) + (int) $count;
		}

		return $totals;
	}

	/**
	 * Dates for the last N days ending today, oldest first.
	 *
	 * @param  string $today  Today's date, Y-m-d.
	 * @param  int    $length Number of days.
	 * @return list<string>
	 */
	private static function date_range( string $today, int $length ): array {
		// $today is already site-local; UTC here is only for day arithmetic.
		$end   = new \DateTimeImmutable( $today, new \DateTimeZone( 'UTC' ) );
		$dates = array();
		for ( $i = $length - 1; $i >= 0; $i-- ) {
			$dates[] = $end->modify( "-{$i} days" )->format( 'Y-m-d' );
		}
		return $dates;
	}

	/**
	 * Users with the most reviews.
	 *
	 * @param  array<string, int> $users Map of user ID to review count.
	 * @param  int                $limit Number of users to return.
	 * @return list<array{user_id: int, name: string, reviews: int}>
	 */
	private static function top_users( array $users, int $limit ): array {
		arsort( $users );

		$top = array();
		foreach ( array_slice( $users, 0, $limit, true ) as $user_id => $count ) {
			$user  = get_userdata( (int) $user_id );
			$top[] = array(
				'user_id' => (int) $user_id,
				'name'    => $user ? $user->display_name : __( 'Unknown user', 'ai-feedback' ),
				'reviews' => $count,
			);
		}

		return $top;
	}
}
