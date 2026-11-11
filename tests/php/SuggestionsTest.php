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

	if ( ! function_exists( 'registered_meta_key_exists' ) ) {
		function registered_meta_key_exists( $object_type, $meta_key ) {
			return ! empty( $GLOBALS['test_registered_meta'][ $object_type ][ $meta_key ] );
		}
	}
	// phpcs:enable
}

namespace AI_Feedback\Tests {

	use PHPUnit\Framework\TestCase;
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
		 * Clears forced filter results.
		 */
		protected function tearDown(): void {
			unset( $GLOBALS['test_filter_results'] );
			parent::tearDown();
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
