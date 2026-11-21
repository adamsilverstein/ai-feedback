<?php
/**
 * Tests for choosing the published posts rule suggestions are drawn from.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\House_Style;
use AI_Feedback\House_Style_Suggestions;

require_once __DIR__ . '/house-style-mocks.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-house-style-suggestions.php';

/**
 * House_Style_Suggestions::get_sample_posts().
 */
class HouseStyleSuggestionsTest extends TestCase {

	/**
	 * ID of the acting user.
	 */
	private const USER_ID = 7;

	/**
	 * Reset the seeded posts.
	 */
	protected function setUp(): void {
		$GLOBALS['test_current_user_id'] = self::USER_ID;
		$GLOBALS['test_get_posts']       = array();
		$GLOBALS['test_get_posts_args']  = array();
	}

	/**
	 * Clean up globals.
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['test_current_user_id'],
			$GLOBALS['test_current_user_can'],
			$GLOBALS['test_get_posts'],
			$GLOBALS['test_get_posts_args'],
			$GLOBALS['test_editor_post_types'],
			$GLOBALS['test_filter_results']
		);
	}

	/**
	 * Build a post as get_posts() returns it.
	 *
	 * @param  int                  $id     Post ID.
	 * @param  array<string, mixed> $fields Fields to change.
	 * @return object
	 */
	private static function post( int $id, array $fields = array() ): object {
		return (object) array_merge(
			array(
				'ID'            => $id,
				'post_author'   => (string) self::USER_ID,
				'post_status'   => 'publish',
				'post_type'     => 'post',
				'post_password' => '',
				'post_title'    => 'Post ' . $id,
				'post_content'  => '<!-- wp:paragraph --><p>Body of post ' . $id . '.</p><!-- /wp:paragraph -->',
			),
			$fields
		);
	}

	/**
	 * The query the last call made.
	 *
	 * @return array<string, mixed>
	 */
	private function query(): array {
		$queries = $GLOBALS['test_get_posts_args'] ?? null;
		$this->assertIsArray( $queries );
		$this->assertCount( 1, $queries );
		$query = array();
		foreach ( is_array( $queries[0] ) ? $queries[0] : array() as $key => $value ) {
			$query[ (string) $key ] = $value;
		}
		return $query;
	}

	/**
	 * Personal rules come from the user's own published posts, newest first.
	 */
	public function test_personal_rules_read_the_users_own_published_posts(): void {
		$GLOBALS['test_get_posts'] = array( self::post( 1 ), self::post( 2 ) );

		$posts = House_Style_Suggestions::get_sample_posts( House_Style::SCOPE_USER, self::USER_ID );

		$this->assertSame(
			array(
				array(
					'title' => 'Post 1',
					'text'  => 'Body of post 1.',
				),
				array(
					'title' => 'Post 2',
					'text'  => 'Body of post 2.',
				),
			),
			$posts
		);

		$query = $this->query();
		$this->assertSame( 'publish', $query['post_status'] );
		$this->assertSame( self::USER_ID, $query['author'] );
		$this->assertFalse( $query['has_password'] );
		$this->assertSame( 'date', $query['orderby'] );
		$this->assertSame( 'DESC', $query['order'] );
		$this->assertSame( array( 'post', 'page' ), $query['post_type'] );
	}

	/**
	 * Site rules come from published posts by any author.
	 */
	public function test_site_rules_read_posts_across_authors(): void {
		$GLOBALS['test_get_posts'] = array(
			self::post( 1 ),
			self::post( 2, array( 'post_author' => '9' ) ),
		);

		$posts = House_Style_Suggestions::get_sample_posts( House_Style::SCOPE_SITE, self::USER_ID );

		$this->assertSame( array( 'Post 1', 'Post 2' ), array_column( $posts, 'title' ) );
		$this->assertArrayNotHasKey( 'author', $this->query() );
		$this->assertSame( 'publish', $this->query()['post_status'] );
	}

	/**
	 * Whatever the query returns, a draft, a private post, a password
	 * protected post and a post the user can't read are never used.
	 */
	public function test_unpublished_private_protected_and_unreadable_posts_are_dropped(): void {
		$GLOBALS['test_get_posts']        = array(
			self::post( 1, array( 'post_status' => 'draft' ) ),
			self::post( 2, array( 'post_status' => 'private' ) ),
			self::post( 3, array( 'post_status' => 'pending' ) ),
			self::post( 4, array( 'post_status' => 'future' ) ),
			self::post( 5, array( 'post_password' => 'secret' ) ),
			self::post( 6 ),
			self::post( 7 ),
			self::post( 8, array( 'post_type' => 'attachment' ) ),
		);
		$GLOBALS['test_current_user_can'] = static function ( string $cap, mixed ...$args ): bool {
			return 'read_post' !== $cap || 6 !== ( $args[0] ?? null );
		};

		$posts = House_Style_Suggestions::get_sample_posts( House_Style::SCOPE_SITE, self::USER_ID );

		$this->assertSame( array( 'Post 7' ), array_column( $posts, 'title' ) );
	}

	/**
	 * Another author's post is never read for personal rules.
	 */
	public function test_personal_rules_drop_other_authors_posts(): void {
		$GLOBALS['test_get_posts'] = array(
			self::post( 1, array( 'post_author' => '9' ) ),
			self::post( 2 ),
		);

		$posts = House_Style_Suggestions::get_sample_posts( House_Style::SCOPE_USER, self::USER_ID );

		$this->assertSame( array( 'Post 2' ), array_column( $posts, 'title' ) );
	}

	/**
	 * At most five posts are read.
	 */
	public function test_reads_at_most_five_posts(): void {
		$GLOBALS['test_get_posts'] = array_map( array( self::class, 'post' ), range( 1, 9 ) );

		$posts = House_Style_Suggestions::get_sample_posts( House_Style::SCOPE_USER, self::USER_ID );

		$this->assertSame( array( 'Post 1', 'Post 2', 'Post 3', 'Post 4', 'Post 5' ), array_column( $posts, 'title' ) );
		$this->assertSame( 5, House_Style_Suggestions::MAX_POSTS );
	}

	/**
	 * A filter changes how many posts are read, up to a ceiling.
	 */
	public function test_sample_size_is_filterable_and_capped(): void {
		$GLOBALS['test_get_posts'] = array_map( array( self::class, 'post' ), range( 1, 30 ) );

		$GLOBALS['test_filter_results'] = array( 'ai_feedback_house_style_sample_size' => 2 );
		$this->assertCount( 2, House_Style_Suggestions::get_sample_posts( House_Style::SCOPE_USER, self::USER_ID ) );

		$GLOBALS['test_filter_results'] = array( 'ai_feedback_house_style_sample_size' => 999 );
		$this->assertCount( House_Style_Suggestions::SAMPLE_SIZE_LIMIT, House_Style_Suggestions::get_sample_posts( House_Style::SCOPE_USER, self::USER_ID ) );

		$GLOBALS['test_filter_results'] = array( 'ai_feedback_house_style_sample_size' => 'many' );
		$this->assertCount( House_Style_Suggestions::MAX_POSTS, House_Style_Suggestions::get_sample_posts( House_Style::SCOPE_USER, self::USER_ID ) );
	}

	/**
	 * Only post types with an editor are read.
	 */
	public function test_reads_only_post_types_with_an_editor(): void {
		$GLOBALS['test_editor_post_types'] = array( 'post' );
		$GLOBALS['test_get_posts']         = array(
			self::post( 1, array( 'post_type' => 'page' ) ),
			self::post( 2 ),
		);

		$posts = House_Style_Suggestions::get_sample_posts( House_Style::SCOPE_USER, self::USER_ID );

		$this->assertSame( array( 'post' ), $this->query()['post_type'] );
		$this->assertSame( array( 'Post 2' ), array_column( $posts, 'title' ) );
	}

	/**
	 * Posts are read as plain text: no markup, block comments or shortcodes,
	 * with headings marked, and a post with no text is skipped.
	 */
	public function test_posts_are_read_as_plain_text(): void {
		$GLOBALS['test_get_posts'] = array(
			self::post(
				1,
				array(
					'post_title'   => 'How <em>we</em> ship',
					'post_content' => "<!-- wp:heading --><h2 class=\"wp-block-heading\">Why weekly</h2><!-- /wp:heading -->\n\n<!-- wp:paragraph --><p>We ship <strong>every</strong> week.</p><!-- /wp:paragraph -->\n\n[gallery ids=\"1,2\"]<script>alert(1)</script>",
				)
			),
			self::post( 2, array( 'post_content' => '<!-- wp:image --><figure><img src="a.png" alt=""/></figure><!-- /wp:image -->' ) ),
		);

		$posts = House_Style_Suggestions::get_sample_posts( House_Style::SCOPE_USER, self::USER_ID );

		$this->assertSame(
			array(
				array(
					'title' => 'How we ship',
					'text'  => "## Why weekly\n\nWe ship every week.",
				),
			),
			$posts
		);
	}

	/**
	 * A user who is not logged in has no posts to read.
	 */
	public function test_no_user_reads_nothing(): void {
		$GLOBALS['test_get_posts'] = array( self::post( 1, array( 'post_author' => '0' ) ) );

		$this->assertSame( array(), House_Style_Suggestions::get_sample_posts( House_Style::SCOPE_USER, 0 ) );
		$this->assertSame( array(), $GLOBALS['test_get_posts_args'] );
	}
}
