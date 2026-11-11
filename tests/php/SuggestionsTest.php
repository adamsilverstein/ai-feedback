<?php
/**
 * Tests for the Gutenberg Suggestion Mode integration.
 *
 * @package AI_Feedback\Tests
 */

namespace {
	// phpcs:disable
	require_once __DIR__ . '/reply-status-controller-bootstrap.php';
	require_once __DIR__ . '/reply-cron-mocks.php';

	if ( ! function_exists( 'gutenberg_is_experiment_enabled' ) ) {
		function gutenberg_is_experiment_enabled( $name ) {
			return ! empty( $GLOBALS['test_gutenberg_experiments'][ $name ] );
		}
	}

	if ( ! class_exists( 'WP_Post' ) ) {
		class WP_Post {
			public $ID                = 0;
			public $post_modified_gmt = '';

			public function __construct( $post = null ) {
				foreach ( (array) $post as $key => $value ) {
					$this->$key = $value;
				}
			}
		}
	}

	if ( ! function_exists( 'wp_get_post_autosave' ) ) {
		function wp_get_post_autosave( $post_id, $user_id = 0 ) {
			return $GLOBALS['test_autosaves'][ $post_id ] ?? false;
		}
	}

	if ( ! function_exists( 'registered_meta_key_exists' ) ) {
		function registered_meta_key_exists( $object_type, $meta_key ) {
			return ! empty( $GLOBALS['test_registered_meta'][ $object_type ][ $meta_key ] );
		}
	}
	// phpcs:enable
}

namespace AI_Feedback\Tests {

	use PHPUnit\Framework\TestCase;
	use AI_Feedback\Notes_Controller;
	use AI_Feedback\Notes_Manager;
	use AI_Feedback\Suggestions;

	/**
	 * Suggestions tests.
	 */
	class SuggestionsTest extends TestCase {

		/**
		 * Note ID used by the fixtures.
		 */
		private const NOTE_ID = 300;

		/**
		 * Resets the experiment, registered meta and a fixture AI note.
		 */
		protected function setUp(): void {
			parent::setUp();

			$GLOBALS['test_gutenberg_experiments'] = array( Suggestions::EXPERIMENT => true );
			$GLOBALS['test_registered_meta']       = array( 'comment' => array( Suggestions::PAYLOAD_META => true ) );
			$GLOBALS['test_comments']              = array(
				self::NOTE_ID => new \WP_Comment(
					array(
						'comment_ID'       => (string) self::NOTE_ID,
						'comment_post_ID'  => '42',
						'comment_approved' => '0',
					)
				),
			);
			$GLOBALS['test_comment_meta']          = array(
				self::NOTE_ID => array(
					'ai_feedback'        => '1',
					'feedback_quick_fix' => '{"find":"in order to","replace":"to"}',
				),
			);
		}

		/**
		 * Clears forced filter results, posts and autosaves.
		 */
		protected function tearDown(): void {
			unset( $GLOBALS['test_filter_results'], $GLOBALS['test_posts'], $GLOBALS['test_autosaves'] );
			parent::tearDown();
		}

		/**
		 * Adds a pending AI suggestion note to post 42.
		 *
		 * @param int    $note_id  Note ID.
		 * @param string $date_gmt Creation date, GMT.
		 * @param string $status   Suggestion status, or '' for none stored.
		 */
		private function add_suggestion( int $note_id, string $date_gmt, string $status = 'pending' ): void {
			$GLOBALS['test_comments'][ $note_id ]     = new \WP_Comment(
				array(
					'comment_ID'       => (string) $note_id,
					'comment_post_ID'  => '42',
					'comment_date_gmt' => $date_gmt,
				)
			);
			$GLOBALS['test_comment_meta'][ $note_id ] = array(
				'ai_feedback'              => '1',
				Suggestions::PAYLOAD_META  => '{"schemaVersion":2}',
				Suggestions::STATUS_META   => $status,
			);
		}

		/**
		 * Sets post 42's last save time.
		 *
		 * @param string $modified_gmt Last modified date, GMT.
		 */
		private function set_post_modified( string $modified_gmt ): void {
			$GLOBALS['test_posts'] = array(
				42 => new \WP_Post(
					array(
						'ID'                => 42,
						'post_modified_gmt' => $modified_gmt,
					)
				),
			);
		}

		/**
		 * Only pending suggestions created before the last save are listed.
		 */
		public function test_saved_pending_ids_skip_suggestions_newer_than_the_save(): void {
			$this->set_post_modified( '2026-05-01 12:00:00' );
			$this->add_suggestion( 401, '2026-05-01 11:00:00' );
			$this->add_suggestion( 402, '2026-05-01 13:00:00' );
			$this->add_suggestion( 403, '2026-05-01 12:00:00' );
			$this->add_suggestion( 404, '2026-05-01 11:00:00', '' );
			$this->add_suggestion( 405, '2026-05-01 11:00:00', 'applied-unsaved' );

			$this->assertSame( array( 401, 404 ), ( new Suggestions() )->get_saved_pending_ids( 42 ) );
		}

		/**
		 * A newer autosave may hold the markers, so nothing is listed.
		 */
		public function test_saved_pending_ids_wait_for_a_newer_autosave(): void {
			$this->set_post_modified( '2026-05-01 12:00:00' );
			$this->add_suggestion( 401, '2026-05-01 11:00:00' );

			$GLOBALS['test_autosaves'] = array(
				42 => new \WP_Post( array( 'post_modified_gmt' => '2026-05-01 12:30:00' ) ),
			);
			$this->assertSame( array(), ( new Suggestions() )->get_saved_pending_ids( 42 ) );

			$GLOBALS['test_autosaves'][42]->post_modified_gmt = '2026-05-01 11:30:00';
			$this->assertSame( array( 401 ), ( new Suggestions() )->get_saved_pending_ids( 42 ) );
		}

		/**
		 * The REST route returns the listed note IDs.
		 */
		public function test_rest_route_returns_saved_pending_ids(): void {
			require_once dirname( __DIR__, 2 ) . '/includes/class-notes-controller.php';

			$this->set_post_modified( '2026-05-01 12:00:00' );
			$this->add_suggestion( 401, '2026-05-01 11:00:00' );

			$request = new \WP_REST_Request();
			$request->set_param( 'post_id', 42 );
			$response = ( new Notes_Controller() )->get_saved_pending_suggestions( $request );

			$this->assertInstanceOf( \WP_REST_Response::class, $response );
			$this->assertSame( array( 'note_ids' => array( 401 ) ), $response->get_data() );
		}

		/**
		 * Nothing is listed without Suggestion Mode or a post.
		 */
		public function test_saved_pending_ids_need_suggestion_mode_and_a_post(): void {
			$this->set_post_modified( '2026-05-01 12:00:00' );
			$this->add_suggestion( 401, '2026-05-01 11:00:00' );

			$GLOBALS['test_gutenberg_experiments'] = array();
			$this->assertSame( array(), ( new Suggestions() )->get_saved_pending_ids( 42 ) );

			$GLOBALS['test_gutenberg_experiments'] = array( Suggestions::EXPERIMENT => true );
			$GLOBALS['test_posts']                 = array();
			$this->assertSame( array(), ( new Suggestions() )->get_saved_pending_ids( 42 ) );
		}

		/**
		 * Suggestions are available only with the experiment and its meta.
		 */
		public function test_is_available_requires_experiment_and_meta(): void {
			$this->assertTrue( Suggestions::is_available() );

			$GLOBALS['test_registered_meta'] = array();
			$this->assertFalse( Suggestions::is_available() );

			$GLOBALS['test_registered_meta']       = array( 'comment' => array( Suggestions::PAYLOAD_META => true ) );
			$GLOBALS['test_gutenberg_experiments'] = array();
			$this->assertFalse( Suggestions::is_available() );
		}

		/**
		 * The `ai_feedback_use_suggestions` filter has the last word.
		 */
		public function test_filter_overrides_availability(): void {
			$GLOBALS['test_filter_results'] = array( 'ai_feedback_use_suggestions' => false );
			$this->assertFalse( Suggestions::is_available() );

			$GLOBALS['test_gutenberg_experiments'] = array();
			$GLOBALS['test_filter_results']        = array( 'ai_feedback_use_suggestions' => true );
			$this->assertTrue( Suggestions::is_available() );
		}

		/**
		 * A note without a payload is not a suggestion.
		 */
		public function test_status_is_null_without_payload(): void {
			$this->assertNull( ( new Suggestions() )->get_status( self::NOTE_ID ) );

			$GLOBALS['test_comment_meta'][ self::NOTE_ID ][ Suggestions::PAYLOAD_META ] = '';
			$this->assertNull( ( new Suggestions() )->get_status( self::NOTE_ID ) );
		}

		/**
		 * A payload without a status is pending.
		 */
		public function test_status_defaults_to_pending(): void {
			$GLOBALS['test_comment_meta'][ self::NOTE_ID ][ Suggestions::PAYLOAD_META ] = '{"schemaVersion":2}';
			$this->assertSame( 'pending', ( new Suggestions() )->get_status( self::NOTE_ID ) );

			$GLOBALS['test_comment_meta'][ self::NOTE_ID ][ Suggestions::STATUS_META ] = 'rejected';
			$this->assertSame( 'rejected', ( new Suggestions() )->get_status( self::NOTE_ID ) );
		}

		/**
		 * Notes report their suggestion status next to the quick fix.
		 */
		public function test_note_fields_include_suggestion_status(): void {
			$manager = new Notes_Manager();

			$this->assertNull( $manager->get_notes_for_post( 42 )[0]['suggestion_status'] );

			$GLOBALS['test_comment_meta'][ self::NOTE_ID ][ Suggestions::PAYLOAD_META ] = '{"schemaVersion":2}';
			$GLOBALS['test_comment_meta'][ self::NOTE_ID ][ Suggestions::STATUS_META ]  = 'applied-unsaved';

			$this->assertSame( 'applied-unsaved', $manager->get_notes_for_post( 42 )[0]['suggestion_status'] );
		}
	}
}
