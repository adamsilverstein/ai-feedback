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

	if ( ! function_exists( 'delete_comment_meta' ) ) {
		function delete_comment_meta( $comment_id, $key ) {
			unset( $GLOBALS['test_comment_meta'][ $comment_id ][ $key ] );
			return true;
		}
	}

	if ( ! function_exists( 'wp_slash' ) ) {
		// The comment meta mocks don't unslash, so slashing is a no-op here.
		function wp_slash( $value ) {
			return $value;
		}
	}
	// phpcs:enable
}

namespace AI_Feedback\Tests {

	use PHPUnit\Framework\TestCase;
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
						'comment_ID'      => (string) self::NOTE_ID,
						'comment_post_ID' => '42',
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
		 * A replacement builds a v2 payload with one inline operation.
		 */
		public function test_build_payload_for_replacement(): void {
			$payload = ( new Suggestions() )->build_payload(
				'core/paragraph',
				'content',
				array(
					'find'    => 'in order to',
					'replace' => 'to',
				)
			);

			$this->assertSame(
				array(
					'schemaVersion' => 2,
					'blockName'     => 'core/paragraph',
					'baseRevision'  => null,
					'operations'    => array(
						array(
							'type'           => 'inline-suggestion',
							'attribute'      => 'content',
							'suggestionType' => 'replace',
						),
					),
				),
				$payload
			);
		}

		/**
		 * An empty replacement is a deletion.
		 */
		public function test_empty_replacement_is_deletion(): void {
			$this->assertSame(
				'del',
				Suggestions::suggestion_type(
					array(
						'find'    => 'very ',
						'replace' => '',
					)
				)
			);
		}

		/**
		 * Creating a suggestion stores the payload and a pending status.
		 */
		public function test_create_for_note_stores_payload(): void {
			$result = ( new Suggestions() )->create_for_note( self::NOTE_ID, 'core/paragraph', 'We met in order to plan.' );

			$this->assertIsArray( $result );
			$this->assertSame( 'replace', $result['suggestion_type'] );
			$this->assertSame( 0, $result['author'] );
			$this->assertSame( 'pending', $result['status'] );

			$meta = $GLOBALS['test_comment_meta'][ self::NOTE_ID ];
			$this->assertSame( 'pending', $meta[ Suggestions::STATUS_META ] );
			$stored = json_decode( $meta[ Suggestions::PAYLOAD_META ], true );
			$this->assertSame( 'core/paragraph', $stored['blockName'] );
			$this->assertSame( 'pending', ( new Suggestions() )->get_status( self::NOTE_ID ) );
		}

		/**
		 * A fix that no longer matches the block exactly once is refused.
		 *
		 * @dataProvider stale_text_provider
		 *
		 * @param string $text Current block text.
		 */
		public function test_create_for_note_refuses_stale_text( string $text ): void {
			$result = ( new Suggestions() )->create_for_note( self::NOTE_ID, 'core/paragraph', $text );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'ai_feedback_suggestion_stale', $result->get_error_code() );
			$this->assertArrayNotHasKey( Suggestions::PAYLOAD_META, $GLOBALS['test_comment_meta'][ self::NOTE_ID ] );
		}

		/**
		 * Block text the fix no longer matches exactly once.
		 *
		 * @return array<string, array{string}>
		 */
		public static function stale_text_provider(): array {
			return array(
				'missing'   => array( 'We met to plan.' ),
				'ambiguous' => array( 'in order to go, in order to stay' ),
			);
		}

		/**
		 * Notes the plugin did not write, or replies, are refused.
		 */
		public function test_create_for_note_refuses_other_notes(): void {
			unset( $GLOBALS['test_comment_meta'][ self::NOTE_ID ]['ai_feedback'] );
			$result = ( new Suggestions() )->create_for_note( self::NOTE_ID, 'core/paragraph', 'in order to' );
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'invalid_note', $result->get_error_code() );

			$GLOBALS['test_comment_meta'][ self::NOTE_ID ]['ai_feedback'] = '1';
			$GLOBALS['test_comments'][ self::NOTE_ID ]->comment_parent    = '12';
			$result = ( new Suggestions() )->create_for_note( self::NOTE_ID, 'core/paragraph', 'in order to' );
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'ai_feedback_suggestion_reply', $result->get_error_code() );
		}

		/**
		 * Without the experiment nothing is written.
		 */
		public function test_create_for_note_requires_availability(): void {
			$GLOBALS['test_gutenberg_experiments'] = array();

			$result = ( new Suggestions() )->create_for_note( self::NOTE_ID, 'core/paragraph', 'in order to' );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'ai_feedback_suggestions_unavailable', $result->get_error_code() );
		}

		/**
		 * Decided suggestions can't be recreated or removed.
		 */
		public function test_decided_suggestion_is_locked(): void {
			$GLOBALS['test_comment_meta'][ self::NOTE_ID ][ Suggestions::PAYLOAD_META ] = '{"schemaVersion":2}';
			$GLOBALS['test_comment_meta'][ self::NOTE_ID ][ Suggestions::STATUS_META ]  = 'applied';

			$suggestions = new Suggestions();
			$created     = $suggestions->create_for_note( self::NOTE_ID, 'core/paragraph', 'in order to' );
			$removed     = $suggestions->remove_for_note( self::NOTE_ID );

			$this->assertInstanceOf( \WP_Error::class, $created );
			$this->assertSame( 'ai_feedback_suggestion_decided', $created->get_error_code() );
			$this->assertInstanceOf( \WP_Error::class, $removed );
		}

		/**
		 * Removing a pending suggestion leaves a plain AI note.
		 */
		public function test_remove_for_note_clears_meta(): void {
			$suggestions = new Suggestions();
			$suggestions->create_for_note( self::NOTE_ID, 'core/paragraph', 'in order to' );

			$this->assertTrue( $suggestions->remove_for_note( self::NOTE_ID ) );
			$this->assertNull( $suggestions->get_status( self::NOTE_ID ) );
			$this->assertSame( '1', $GLOBALS['test_comment_meta'][ self::NOTE_ID ]['ai_feedback'] );
		}

		/**
		 * A note without a payload is not a suggestion.
		 */
		public function test_status_is_null_without_payload(): void {
			$this->assertNull( ( new Suggestions() )->get_status( self::NOTE_ID ) );
		}
	}
}
