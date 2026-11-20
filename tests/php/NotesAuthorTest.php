<?php
/**
 * Tests for writing AI notes as the Fresh Eyes user, and its avatar.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use AI_Feedback\Fresh_Eyes_User;
use AI_Feedback\Notes_Manager;

require_once __DIR__ . '/fresh-eyes-user-mocks.php';

/**
 * New AI notes take the Fresh Eyes user as author when it exists, and 0
 * otherwise; either way the ai_feedback meta marks them.
 */
class NotesAuthorTest extends TestCase {

	/**
	 * The database object other tests use.
	 *
	 * @var mixed
	 */
	private mixed $wpdb = null;

	/**
	 * Load the fake comment API and reset users and comments.
	 */
	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/fixtures/fake-comments.php';
		require_once dirname( __DIR__, 2 ) . '/includes/class-plugin.php';
		\ai_feedback_test_reset_comments(); // @phpstan-ignore function.notFound (defined in the fixture above)
		ai_feedback_test_reset_users();
		$this->wpdb      = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new \wpdb();
	}

	/**
	 * Clean up.
	 */
	protected function tearDown(): void {
		ai_feedback_test_reset_users();
		$GLOBALS['wpdb'] = $this->wpdb;
		parent::tearDown();
	}

	/**
	 * Write a root note and a reply in its thread.
	 *
	 * @return array{int, int} Root and reply note IDs.
	 */
	private function write_notes(): array {
		$manager = new Notes_Manager();
		$result  = $manager->create_notes_from_feedback(
			array(
				array(
					'block_id' => 'block-1',
					'category' => 'tone',
					'severity' => 'suggestion',
					'title'    => 'Word choice',
					'feedback' => 'Try a warmer word.',
				),
			),
			10
		);
		$root    = (int) ( $result['note_ids'][0] ?? 0 );
		$reply   = $manager->add_reply_to_thread(
			array(
				'feedback' => 'Here is another.',
				'block_id' => 'block-1',
			),
			$root,
			10
		);
		$this->assertIsInt( $reply );
		return array( $root, $reply );
	}

	public function test_new_ai_notes_are_written_as_the_fresh_eyes_user(): void {
		$id = Fresh_Eyes_User::ensure();
		$this->assertGreaterThan( 0, $id );

		foreach ( $this->write_notes() as $note_id ) {
			$this->assertSame( $id, $GLOBALS['test_inserted_args'][ $note_id ]['user_id'] );
			$this->assertSame( 'Fresh Eyes', $GLOBALS['test_comments'][ $note_id ]->comment_author );
			$this->assertSame( '1', (string) get_comment_meta( $note_id, 'ai_feedback', true ) );
		}
	}

	/**
	 * When there is no account to write as.
	 *
	 * @return array<string, array{callable(): void}>
	 */
	public static function data_no_account(): array {
		return array(
			'never created'      => array( static function (): void {} ),
			'filter turned off'  => array(
				static function (): void {
					Fresh_Eyes_User::ensure();
					$GLOBALS['test_filter_results'] = array( 'ai_feedback_create_mention_user' => false );
					Fresh_Eyes_User::ensure();
				},
			),
			'deleted elsewhere'  => array(
				static function (): void {
					$id = Fresh_Eyes_User::ensure();
					unset( $GLOBALS['test_user_objects'][ $id ] );
				},
			),
			'option not ours'    => array(
				static function (): void {
					$GLOBALS['test_options'][ Fresh_Eyes_User::OPTION ] = 5;
				},
			),
		);
	}

	/**
	 * Without the account, AI notes are written as 0, exactly as before,
	 * and still carry the meta that marks them.
	 *
	 * @param callable $arrange Puts the site in the state to test.
	 */
	#[DataProvider( 'data_no_account' )]
	public function test_ai_notes_fall_back_to_no_author( callable $arrange ): void {
		$arrange();

		foreach ( $this->write_notes() as $note_id ) {
			$this->assertSame( 0, $GLOBALS['test_inserted_args'][ $note_id ]['user_id'] );
			$this->assertSame( '1', (string) get_comment_meta( $note_id, 'ai_feedback', true ) );
		}
	}

	/**
	 * Build a note.
	 *
	 * @param int    $id      Comment ID.
	 * @param string $author  Author name.
	 * @param int    $user_id User ID.
	 * @return \WP_Comment
	 */
	private function note( int $id, string $author, int $user_id ): \WP_Comment {
		$note                 = new \WP_Comment();
		$note->comment_ID     = (string) $id;
		$note->comment_author = $author;
		$note->user_id        = (string) $user_id;
		return $note;
	}

	public function test_fresh_eyes_gets_its_avatar_as_a_note_and_as_a_user(): void {
		$id   = Fresh_Eyes_User::ensure();
		$url  = 'http://example.test/wp-content/plugins/' . basename( dirname( __DIR__, 2 ) ) . '/assets/fresh-eyes-avatar.svg';
		$user = $GLOBALS['test_user_objects'][ $id ];

		$GLOBALS['test_comment_meta'][1] = array( 'ai_feedback' => '1' );
		$GLOBALS['test_comment_meta'][2] = array( 'ai_feedback' => '1' );
		$fresh_eyes                      = array(
			'old AI note, author 0'      => $this->note( 1, 'Fresh Eyes', 0 ),
			'new AI note, the user'      => $this->note( 2, 'Fresh Eyes', $id ),
			'AI note under an old name'  => $this->note( 3, 'Copydesk AI', 0 ),
			'note by the user, no meta'  => $this->note( 4, 'Someone', $id ),
			'user ID'                    => $id,
			'user ID as a string'        => (string) $id,
			'user object'                => $user,
		);
		foreach ( $fresh_eyes as $label => $id_or_email ) {
			$args = Fresh_Eyes_User::filter_avatar( array( 'size' => 48 ), $id_or_email );
			$this->assertSame( $url, $args['url'] ?? null, $label );
			$this->assertTrue( $args['found_avatar'] ?? false, $label );
		}

		$GLOBALS['test_user_objects'][7]      = new \Test_Fresh_Eyes_WP_User();
		$GLOBALS['test_user_objects'][7]->ID  = 7;
		$someone_else                         = array(
			'a person\'s note' => $this->note( 5, 'Jane', 7 ),
			'a person\'s ID'   => 7,
			'a person'         => $GLOBALS['test_user_objects'][7],
			'an email'         => 'jane@example.com',
		);
		foreach ( $someone_else as $label => $id_or_email ) {
			$this->assertSame( array( 'size' => 48 ), Fresh_Eyes_User::filter_avatar( array( 'size' => 48 ), $id_or_email ), $label );
		}
	}

	public function test_the_avatar_blinks_only_without_reduced_motion(): void {
		$svg = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/fresh-eyes-avatar.svg' );

		$this->assertStringContainsString( 'animation: blink 15s infinite', $svg );
		$this->assertStringContainsString( '@media (prefers-reduced-motion: reduce)', $svg );
		$this->assertNotFalse( simplexml_load_string( $svg ), 'The avatar is well-formed SVG.' );
	}
}
