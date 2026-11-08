<?php
/**
 * Tests for Analytics and Analytics_Controller.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Analytics;
use AI_Feedback\Analytics_Controller;

/**
 * Usage analytics tests.
 */
class AnalyticsTest extends TestCase {

	/**
	 * Reset global fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['test_options']          = array();
		$GLOBALS['test_now']              = strtotime( '2026-11-08 12:00:00 UTC' );
		$GLOBALS['test_current_user_id']  = 3;
		$GLOBALS['test_current_user_can'] = true;
		$GLOBALS['test_user_emails']      = array( 'ada@example.com' => 3 );
		$GLOBALS['test_users']            = array(
			3 => 'Ada',
			4 => 'Grace',
		);
	}

	/**
	 * Build a fake review result.
	 *
	 * @param  string $model Model ID.
	 * @param  int    $count Note count.
	 * @return array<string, mixed>
	 */
	private function review( string $model = 'model-a', int $count = 3 ): array {
		return array(
			'review_id'  => 'abc',
			'model'      => $model,
			'note_count' => $count,
			'summary'    => array(
				'by_severity' => array(
					'critical'   => 1,
					'important'  => 0,
					'suggestion' => 2,
				),
				'by_category' => array(
					'content' => 2,
					'flow'    => 1,
				),
			),
		);
	}

	/**
	 * Reviews on the same day share one bucket.
	 */
	public function test_track_review_aggregates_by_day(): void {
		Analytics::track_review( 1, $this->review() );
		$GLOBALS['test_current_user_id'] = 4;
		Analytics::track_review( 2, $this->review( 'model-b', 1 ) );

		$days = Analytics::get_days();
		$this->assertSame( array( '2026-11-08' ), array_keys( $days ) );
		$this->assertSame( 2, $days['2026-11-08']['reviews'] );
		$this->assertSame( 4, $days['2026-11-08']['feedback'] );
		$this->assertSame(
			array(
				'model-a' => 1,
				'model-b' => 1,
			),
			$days['2026-11-08']['models']
		);
		$this->assertSame( 2, $days['2026-11-08']['severity']['critical'] );
	}

	/**
	 * Storage is pruned to MAX_DAYS buckets.
	 */
	public function test_track_review_prunes_old_days(): void {
		$days = array();
		for ( $i = 0; $i < Analytics::MAX_DAYS; $i++ ) {
			$days[ gmdate( 'Y-m-d', strtotime( "2024-01-01 +{$i} days" ) ) ] = array(
				'reviews'  => 1,
				'feedback' => 1,
			);
		}
		$GLOBALS['test_options'][ Analytics::OPTION_KEY ] = $days;

		Analytics::track_review( 1, $this->review() );

		$stored = Analytics::get_days();
		$this->assertCount( Analytics::MAX_DAYS, $stored );
		$this->assertArrayNotHasKey( '2024-01-01', $stored );
		$this->assertArrayHasKey( '2026-11-08', $stored );
	}

	/**
	 * The summary covers only the requested period and fills empty days.
	 */
	public function test_get_summary_filters_period(): void {
		$GLOBALS['test_options'][ Analytics::OPTION_KEY ] = array(
			'2026-10-01' => array(
				'reviews'  => 5,
				'feedback' => 10,
			),
			'2026-11-07' => array(
				'reviews'  => 2,
				'feedback' => 3,
				'models'   => array( 'model-a' => 2 ),
				'users'    => array(
					'3' => 1,
					'4' => 1,
				),
			),
		);

		$week = Analytics::get_summary( '7days' );
		$this->assertSame( 2, $week['total_reviews'] );
		$this->assertSame( 3, $week['total_feedback'] );
		$this->assertSame( 1.5, $week['avg_feedback'] );
		$this->assertCount( 7, $week['by_day'] );
		$this->assertSame( '2026-11-08', $week['by_day'][6]['date'] );
		$this->assertSame( 0, $week['by_day'][6]['reviews'] );
		$this->assertSame( array( 'model-a' => 2 ), $week['by_model'] );
		$this->assertSame( array( 'Ada', 'Grace' ), array_column( $week['top_users'], 'name' ) );

		$all = Analytics::get_summary( 'all' );
		$this->assertSame( 7, $all['total_reviews'] );
		$this->assertCount( 2, $all['by_day'] );
	}

	/**
	 * Unknown periods fall back to 30 days, and empty data averages to zero.
	 */
	public function test_get_summary_defaults(): void {
		$summary = Analytics::get_summary( 'forever' );

		$this->assertSame( '30days', $summary['period'] );
		$this->assertCount( 30, $summary['by_day'] );
		$this->assertSame( 0.0, $summary['avg_feedback'] );
	}

	/**
	 * Malformed stored data is ignored.
	 */
	public function test_get_days_ignores_malformed_data(): void {
		$GLOBALS['test_options'][ Analytics::OPTION_KEY ] = array(
			'not-a-date' => array( 'reviews' => 1 ),
			'2026-11-08' => 'nope',
		);

		$this->assertSame( array(), Analytics::get_days() );
	}

	/**
	 * Deleting a user removes their counts.
	 */
	public function test_forget_user_removes_counts(): void {
		Analytics::track_review( 1, $this->review() );
		Analytics::forget_user( 3 );

		$days = Analytics::get_days();
		$this->assertSame( array(), $days['2026-11-08']['users'] );
		$this->assertSame( 1, $days['2026-11-08']['reviews'] );
	}

	/**
	 * The exporter and eraser are registered under the option key.
	 */
	public function test_registers_privacy_callbacks(): void {
		$exporters = Analytics::register_exporter( array() );
		$erasers   = Analytics::register_eraser( array() );

		$this->assertSame( array( Analytics::class, 'export_personal_data' ), $exporters[ Analytics::OPTION_KEY ]['callback'] );
		$this->assertSame( array( Analytics::class, 'erase_personal_data' ), $erasers[ Analytics::OPTION_KEY ]['callback'] );
	}

	/**
	 * The exporter lists the user's reviews per day.
	 */
	public function test_export_personal_data(): void {
		Analytics::track_review( 1, $this->review() );
		Analytics::track_review( 2, $this->review() );
		$GLOBALS['test_current_user_id'] = 4;
		Analytics::track_review( 3, $this->review() );

		$export = Analytics::export_personal_data( 'ada@example.com' );

		$this->assertTrue( $export['done'] );
		$this->assertCount( 1, $export['data'] );
		$this->assertSame( 'ai-feedback-usage-2026-11-08', $export['data'][0]['item_id'] );
		$this->assertSame( '2', $export['data'][0]['data'][1]['value'] );
	}

	/**
	 * Unknown emails export nothing.
	 */
	public function test_export_personal_data_unknown_email(): void {
		Analytics::track_review( 1, $this->review() );

		$this->assertSame( array(), Analytics::export_personal_data( 'nobody@example.com' )['data'] );
	}

	/**
	 * The eraser removes the user's counts but keeps site totals.
	 */
	public function test_erase_personal_data(): void {
		Analytics::track_review( 1, $this->review() );
		$GLOBALS['test_current_user_id'] = 4;
		Analytics::track_review( 2, $this->review() );

		$result = Analytics::erase_personal_data( 'ada@example.com' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
		$day = Analytics::get_days()['2026-11-08'];
		$this->assertSame( array( 4 => 1 ), $day['users'] );
		$this->assertSame( 2, $day['reviews'] );

		$this->assertFalse( Analytics::erase_personal_data( 'ada@example.com' )['items_removed'] );
	}

	/**
	 * Long model names are trimmed before they become keys.
	 */
	public function test_track_review_caps_model_length(): void {
		Analytics::track_review( 1, $this->review( str_repeat( 'm', 300 ) ) );

		$models = array_keys( Analytics::get_days()['2026-11-08']['models'] );
		$this->assertSame( 100, strlen( $models[0] ) );
	}

	/**
	 * Users missing from the site still appear in top users.
	 */
	public function test_top_users_handles_deleted_users(): void {
		$GLOBALS['test_current_user_id'] = 99;
		Analytics::track_review( 1, $this->review() );

		$summary = Analytics::get_summary( '7days' );
		$this->assertSame( 'Unknown user', $summary['top_users'][0]['name'] );
	}

	/**
	 * Only administrators may read analytics.
	 */
	public function test_permissions_check_requires_manage_options(): void {
		$controller = new Analytics_Controller();
		$this->assertTrue( $controller->get_analytics_permissions_check() );

		$GLOBALS['test_current_user_can'] = false;
		$result                           = $controller->get_analytics_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * The endpoint returns the summary for the requested period.
	 */
	public function test_controller_returns_summary(): void {
		Analytics::track_review( 1, $this->review() );

		$request = new \WP_REST_Request();
		$request->set_param( 'period', '7days' );

		$data = ( new Analytics_Controller() )->get_analytics( $request )->get_data();
		$this->assertSame( '7days', $data['period'] );
		$this->assertSame( 1, $data['total_reviews'] );
	}
}
