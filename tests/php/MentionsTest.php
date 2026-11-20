<?php
/**
 * Tests for Mentions.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Fresh_Eyes_User;
use AI_Feedback\Mentions;

/**
 * Mention detection, selection and link guard tests.
 */
class MentionsTest extends TestCase {

	/**
	 * Reset filter and capability overrides.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['test_filter_results'], $GLOBALS['test_user_can'], $GLOBALS['test_options'][ Fresh_Eyes_User::OPTION ] );
		Fresh_Eyes_User::reset_cache();
		parent::tearDown();
	}

	/**
	 * Read the completer chip cases shared with src/utils/test/mentions.test.js.
	 *
	 * @param string $key `mentions` or `others`.
	 * @return array<int, array{string, int}>
	 */
	private static function chip_cases( string $key ): array {
		$cases = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/mention-cases.json' ), true );
		return array_map( static fn ( string $content ): array => array( $content, (int) $cases['chips']['userId'] ), $cases['chips'][ $key ] );
	}

	/**
	 * Notes holding a completer chip for the Fresh Eyes user.
	 *
	 * @return array<int, array{string, int}>
	 */
	public static function chip_mentions(): array {
		return self::chip_cases( 'mentions' );
	}

	/**
	 * A completer chip for the Fresh Eyes user is a mention, whatever name it shows.
	 *
	 * @dataProvider chip_mentions
	 *
	 * @param string $content Note content.
	 * @param int    $user_id Fresh Eyes user ID.
	 */
	public function test_detects_user_chip( string $content, int $user_id ): void {
		$GLOBALS['test_options'][ Fresh_Eyes_User::OPTION ] = $user_id;
		Fresh_Eyes_User::reset_cache();

		$this->assertTrue( Mentions::has_user_chip( $content ) );
		$this->assertTrue( Mentions::mentions_fresh_eyes( $content ) );

		// Without the user, the chip is only its text.
		unset( $GLOBALS['test_options'][ Fresh_Eyes_User::OPTION ] );
		Fresh_Eyes_User::reset_cache();
		$this->assertFalse( Mentions::has_user_chip( $content ) );
	}

	/**
	 * Notes with a chip for someone else, or a chip that is quoted or not a chip.
	 *
	 * @return array<int, array{string, int}>
	 */
	public static function chip_others(): array {
		return self::chip_cases( 'others' );
	}

	/**
	 * Only the completer's chip for the Fresh Eyes user, in the note's own text, counts.
	 *
	 * @dataProvider chip_others
	 *
	 * @param string $content Note content.
	 * @param int    $user_id Fresh Eyes user ID.
	 */
	public function test_ignores_other_chips( string $content, int $user_id ): void {
		$GLOBALS['test_options'][ Fresh_Eyes_User::OPTION ] = $user_id;
		Fresh_Eyes_User::reset_cache();

		$this->assertFalse( Mentions::has_user_chip( $content ) );
		$this->assertFalse( Mentions::mentions_fresh_eyes( $content ) );
	}

	/**
	 * Read the cases shared with the editor's tests in src/utils/test/mentions.test.js.
	 *
	 * @param string $key `mentions` or `others`.
	 * @return array<int, array{string}>
	 */
	private static function shared_cases( string $key ): array {
		$cases = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/mention-cases.json' ), true );
		return array_map( static fn ( string $content ): array => array( $content ), $cases[ $key ] );
	}

	/**
	 * Notes that mention Fresh Eyes.
	 *
	 * @return array<int, array{string}>
	 */
	public static function mentioning_notes(): array {
		return self::shared_cases( 'mentions' );
	}

	/**
	 * A note that mentions Fresh Eyes is detected.
	 *
	 * @dataProvider mentioning_notes
	 *
	 * @param string $content Note content.
	 */
	public function test_detects_mention( string $content ): void {
		$this->assertTrue( Mentions::mentions_fresh_eyes( $content ) );
	}

	/**
	 * Notes that do not mention Fresh Eyes.
	 *
	 * @return array<int, array{string}>
	 */
	public static function other_notes(): array {
		return self::shared_cases( 'others' );
	}

	/**
	 * A note that does not mention Fresh Eyes in its own text is ignored.
	 *
	 * @dataProvider other_notes
	 *
	 * @param string $content Note content.
	 */
	public function test_ignores_other_notes( string $content ): void {
		$this->assertFalse( Mentions::mentions_fresh_eyes( $content ) );
	}

	/**
	 * Only the start of a very long note is scanned.
	 */
	public function test_scan_is_bounded(): void {
		$content = str_repeat( 'word ', Mentions::MAX_SCAN_LENGTH ) . '@fresh-eyes simplify';

		$this->assertFalse( Mentions::mentions_fresh_eyes( $content ) );
		$this->assertLessThanOrEqual( Mentions::MAX_SCAN_LENGTH, mb_strlen( Mentions::note_text( $content ) ) );
	}

	/**
	 * Handles added through the filter are matched, and bad values dropped.
	 */
	public function test_filtered_handles(): void {
		$GLOBALS['test_filter_results'] = array( 'ai_feedback_mention_handles' => array( 'Ojos Frescos', 42, '' ) );

		$this->assertSame( array( 'ojos frescos' ), Mentions::get_handles() );
		$this->assertTrue( Mentions::mentions_fresh_eyes( '@ojos-frescos simplifica' ) );
		$this->assertFalse( Mentions::mentions_fresh_eyes( '@fresh-eyes simplify' ) );
	}

	/**
	 * Asking needs the capabilities a review of the post needs.
	 */
	public function test_can_ask_requires_review_capabilities(): void {
		$GLOBALS['test_user_can'] = static fn ( int $user_id, string $cap, ...$args ): bool => 7 === $user_id && ( 'edit_posts' === $cap || array( 42 ) === $args );

		$this->assertTrue( Mentions::can_ask( 7, 42 ) );
		$this->assertFalse( Mentions::can_ask( 7, 43 ), 'Cannot edit that post.' );
		$this->assertFalse( Mentions::can_ask( 8, 42 ), 'Another user.' );
		$this->assertFalse( Mentions::can_ask( 0, 42 ), 'No user.' );
	}

	/**
	 * The text of an inline note's markers is its selection.
	 */
	public function test_selected_text_joins_the_notes_markers(): void {
		$html = '<p>Intro <mark class="wp-note" data-id="300">the very long</mark> <strong><mark data-id="300" class="wp-note">sentence</mark></strong> and <mark class="wp-note" data-id="301">other</mark>.</p>';

		$this->assertSame( 'the very long sentence', Mentions::get_selected_text( $html, 300 ) );
		$this->assertSame( 'other', Mentions::get_selected_text( $html, 301 ) );
		$this->assertSame( '', Mentions::get_selected_text( $html, 302 ) );
	}

	/**
	 * Only `wp-note` markers count, not highlights or similar classes.
	 */
	public function test_selected_text_ignores_other_marks(): void {
		$html = '<p><mark class="wp-note-draft" data-id="300">draft</mark> <mark class="has-inline-color" data-id="300">color</mark></p>';

		$this->assertSame( '', Mentions::get_selected_text( $html, 300 ) );
	}

	/**
	 * Made-up addresses are removed from an answer; ones from the sources stay.
	 */
	public function test_removes_unsourced_urls(): void {
		$reply = 'See https://example.com/real/ and https://made-up.example/study. Also www.made-up.example/paper and www.example.com/real';

		$this->assertSame(
			'See https://example.com/real/ and [link removed]. Also [link removed] and www.example.com/real',
			Mentions::remove_unsourced_urls( $reply, 'Our docs live at https://example.com/real' )
		);
	}

	/**
	 * An edit that adds an address the AI was not given is caught.
	 */
	public function test_edit_with_unsourced_url(): void {
		$edit = array(
			'type'    => 'rewrite_block',
			'content' => 'Read <a href="https://made-up.example/paper">the paper</a>.',
		);

		$this->assertTrue( Mentions::edit_has_unsourced_url( $edit, 'No links here.' ) );
		$this->assertFalse( Mentions::edit_has_unsourced_url( $edit, 'Link https://made-up.example/paper please' ) );
		$this->assertFalse(
			Mentions::edit_has_unsourced_url(
				array(
					'type'    => 'replace_text',
					'find'    => 'a',
					'replace' => 'b',
				),
				''
			)
		);
	}

	/**
	 * A filtered handle with regex characters is matched literally.
	 */
	public function test_filtered_handle_with_regex_characters(): void {
		$GLOBALS['test_filter_results'] = array( 'ai_feedback_mention_handles' => array( 'c++ bot' ) );

		$this->assertTrue( Mentions::mentions_fresh_eyes( '@c++-bot help' ) );
		$this->assertFalse( Mentions::mentions_fresh_eyes( '@ccc-bot help' ) );
	}

	/**
	 * Links an edit can hide in its markup, none of which the AI was given.
	 *
	 * @return array<string, array{string}>
	 */
	public static function hidden_links(): array {
		return array(
			'protocol-relative'         => array( '<a href="//evil.example/x">this</a>' ),
			'encoded colon'             => array( '<a href="https&#58;//evil.example/x">this</a>' ),
			'named colon entity'        => array( '<a href="https&colon;//evil.example/x">this</a>' ),
			'colon entity, no semicolon' => array( '<a href="https&#58//evil.example/x">this</a>' ),
			'uppercase scheme'          => array( '<a href="HTTPS://EVIL.EXAMPLE/x">this</a>' ),
			'tab inside'                => array( "<a href=\"ht\ttps://evil.example/x\">this</a>" ),
			'line break inside'         => array( "<a href=\"https:/\n/evil.example/x\">this</a>" ),
			'leading control character' => array( "<a href=\"\x01 https://evil.example/x\">this</a>" ),
			'unquoted'                  => array( '<a href=//evil.example/x>this</a>' ),
			'single quotes'             => array( "<a href='//evil.example/x'>this</a>" ),
			'slash before attribute'    => array( '<a/href="//evil.example/x">this</a>' ),
			'after a double quote'      => array( '<a title="x"href="//evil.example/x">this</a>' ),
			'after a single quote'      => array( "<a title='x'href='//evil.example/x'>this</a>" ),
			'uppercase attribute'       => array( '<a HREF="//evil.example/x">this</a>' ),
			'relative path'             => array( '<a href="/wp-admin/x">this</a>' ),
			'script scheme'             => array( '<a href="javascript:alert(1)">this</a>' ),
			'image source'              => array( '<img src="//evil.example/x.png">' ),
			'quote source'              => array( '<q cite="//evil.example/x">this</q>' ),
			'encoded text address'      => array( 'Read https&#58;//evil.example/x' ),
		);
	}

	/**
	 * An edit that adds a link the AI was not given is caught, however the
	 * link is written.
	 *
	 * @dataProvider hidden_links
	 *
	 * @param string $content Edit content.
	 */
	public function test_edit_with_hidden_link( string $content ): void {
		$edit = array(
			'type'    => 'rewrite_block',
			'content' => $content,
		);

		$this->assertTrue( Mentions::edit_has_unsourced_url( $edit, '<p>Read <a href="https://good.example/x">this</a>.</p>' ) );
	}

	/**
	 * A link from the sources may be written another way, and a link to a
	 * spot on the page is not a source at all.
	 */
	public function test_edit_with_known_link(): void {
		$sources = '<p>Read <a href="https://good.example/x">this</a>.</p>';
		foreach ( array( '<a href="//good.example/x">this</a>', '<a href="HTTPS&#58;//www.good.example/x/">this</a>', '<a href="#notes">notes</a>' ) as $content ) {
			$this->assertFalse(
				Mentions::edit_has_unsourced_url(
					array(
						'type'    => 'rewrite_block',
						'content' => $content,
					),
					$sources
				),
				$content
			);
		}
	}

	/**
	 * Marks nested in or around the note's marker keep its text whole.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function nested_marks(): array {
		return array(
			'highlight inside the note'      => array(
				'<p><mark class="wp-note" data-id="300">simplify <mark class="has-inline-color">this</mark> long sentence</mark> please</p>',
				'simplify this long sentence',
			),
			'note inside a highlight'        => array(
				'<p><mark class="has-inline-color">Some <mark class="wp-note" data-id="300">selected words</mark> here</mark></p>',
				'selected words',
			),
			'another note inside this note'  => array(
				'<p><mark class="wp-note" data-id="300">outer <mark class="wp-note" data-id="301">inner</mark> after inner</mark> outside</p>',
				'outer inner after inner',
			),
		);
	}

	/**
	 * The selection reads across nested marks.
	 *
	 * @dataProvider nested_marks
	 *
	 * @param string $html     Block HTML.
	 * @param string $expected Selected text.
	 */
	public function test_selected_text_with_nested_marks( string $html, string $expected ): void {
		$this->assertSame( $expected, Mentions::get_selected_text( $html, 300 ) );
	}

	/**
	 * A word split across two stretches of the note is not broken up.
	 */
	public function test_selected_text_keeps_split_words_whole(): void {
		$html = '<p><mark class="wp-note" data-id="300">un</mark><strong><mark class="wp-note" data-id="300">clear</mark></strong> and <mark class="wp-note" data-id="300">vague</mark></p>';

		$this->assertSame( 'unclear vague', Mentions::get_selected_text( $html, 300 ) );
	}

	/**
	 * An address with parentheses is compared whole, and a parenthesis
	 * that closes the text around it stays.
	 */
	public function test_urls_with_parentheses(): void {
		$sources = 'See https://example.com/Page_(one) for more.';

		$this->assertSame(
			'Compare https://example.com/Page_(one) with [link removed].',
			Mentions::remove_unsourced_urls( 'Compare https://example.com/Page_(one) with https://example.com/Page_(invented).', $sources )
		);
		$this->assertSame(
			'(see [link removed])',
			Mentions::remove_unsourced_urls( '(see https://made-up.example/x)', $sources )
		);
	}

	/**
	 * The host is compared without case; the path keeps its case.
	 */
	public function test_url_path_case_matters(): void {
		$sources = 'https://Example.com/Report';

		$this->assertSame( 'https://EXAMPLE.com/Report', Mentions::remove_unsourced_urls( 'https://EXAMPLE.com/Report', $sources ) );
		$this->assertSame( '[link removed]', Mentions::remove_unsourced_urls( 'https://example.com/report', $sources ) );
		$this->assertTrue(
			Mentions::edit_has_unsourced_url(
				array(
					'type'    => 'rewrite_block',
					'content' => '<a href="https://example.com/report">x</a>',
				),
				$sources
			)
		);
	}
}
