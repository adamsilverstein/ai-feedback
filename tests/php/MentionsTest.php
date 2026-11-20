<?php
/**
 * Tests for Mentions.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Mentions;

/**
 * Mention detection, selection and link guard tests.
 */
class MentionsTest extends TestCase {

	/**
	 * Reset filter and capability overrides.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['test_filter_results'], $GLOBALS['test_user_can'] );
		parent::tearDown();
	}

	/**
	 * Notes that mention Fresh Eyes.
	 *
	 * @return array<string, array{string}>
	 */
	public static function mentioning_notes(): array {
		return array(
			'handle'               => array( '@fresh-eyes simplify this' ),
			'camel case'           => array( '@FreshEyes adjust the tone' ),
			'name with a space'    => array( 'Can @Fresh Eyes help here?' ),
			'underscore'           => array( '@fresh_eyes, shorten this.' ),
			'end of note'          => array( 'Thoughts, @fresh-eyes?' ),
			'in a paragraph'       => array( '<p>Hey <strong>@fresh-eyes</strong> simplify</p>' ),
			'mention chip'         => array( '<span class="wp-note-mention user-9">@Fresh Eyes</span> simplify' ),
			'encoded at sign'      => array( '&#64;fresh-eyes simplify' ),
			'after a line break'   => array( "First line\n@fresh-eyes second" ),
			'after an open paren'  => array( 'Simplify (@fresh-eyes)' ),
		);
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
	 * @return array<string, array{string}>
	 */
	public static function other_notes(): array {
		return array(
			'no mention'              => array( 'Simplify this paragraph.' ),
			'name without at sign'    => array( 'Fresh Eyes said this was fine.' ),
			'email address'           => array( 'Write to editor@fresh-eyes.example' ),
			'domain'                  => array( 'See @fresh-eyes.com for details' ),
			'longer name'             => array( '@fresh-eyesight is a different user' ),
			'other user'              => array( '@jane can you look?' ),
			'quoted'                  => array( '<blockquote>@fresh-eyes simplify this</blockquote> I disagree.' ),
			'inline quote'            => array( 'She wrote <q>@fresh-eyes rewrite</q> earlier.' ),
			'code'                    => array( 'Type <code>@fresh-eyes</code> to ask.' ),
			'nested quote'            => array( '<blockquote><p><q>x</q> @fresh-eyes</p></blockquote>' ),
			'unclosed quote'          => array( 'Earlier: <blockquote>@fresh-eyes do it' ),
			'class does not count'    => array( '<span class="wp-note-mention fresh-eyes user-0" data-fresh-eyes="1">@jane</span>' ),
			'attribute does not count' => array( '<a href="https://example.com/@fresh-eyes">link</a>' ),
			'empty'                   => array( '' ),
		);
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
		$reply = 'See https://example.com/real/ and https://made-up.example/study.';

		$this->assertSame(
			'See https://example.com/real/ and [link removed]',
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
}
