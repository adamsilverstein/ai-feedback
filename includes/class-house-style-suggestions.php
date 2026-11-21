<?php
/**
 * House style suggestions
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

/**
 * Picks the published posts that house style rules are suggested from.
 *
 * Only published posts without a password that the user can read are
 * used, so a suggestion never draws on a draft or a private post. Nothing
 * here is stored: the posts go into one prompt and the proposed rules come
 * back for the user to save or skip.
 */
class House_Style_Suggestions {

	/**
	 * Posts read by default.
	 */
	public const MAX_POSTS = 5;

	/**
	 * Most posts a filter may ask for.
	 */
	public const SAMPLE_SIZE_LIMIT = 10;

	/**
	 * Most rules proposed by one request.
	 */
	public const MAX_SUGGESTIONS = 5;

	/**
	 * Get the text of the most recent published posts for a scope.
	 *
	 * Personal rules are suggested from the user's own posts, site rules
	 * from posts by any author.
	 *
	 * @param  string $scope   House_Style::SCOPE_SITE or House_Style::SCOPE_USER.
	 * @param  int    $user_id User asking.
	 * @return list<array{title: string, text: string}> Posts, newest first.
	 */
	public static function get_sample_posts( string $scope, int $user_id ): array {
		$post_types = self::get_post_types( $scope );
		if ( $user_id <= 0 || array() === $post_types ) {
			return array();
		}

		$size  = self::get_sample_size( $scope );
		$query = array(
			'post_type'           => $post_types,
			'post_status'         => 'publish',
			'has_password'        => false,
			// Some may be dropped below, such as posts without text.
			'posts_per_page'      => 2 * $size,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		);
		if ( House_Style::SCOPE_SITE !== $scope ) {
			$query['author'] = $user_id;
		}

		$posts = array();
		foreach ( get_posts( $query ) as $post ) {
			// Checked again here, since a plugin can change what the query returns.
			if (
				'publish' !== $post->post_status
				|| '' !== (string) $post->post_password
				|| ! in_array( $post->post_type, $post_types, true )
				|| ( House_Style::SCOPE_SITE !== $scope && (int) $post->post_author !== $user_id )
				|| ! current_user_can( 'read_post', (int) $post->ID )
			) {
				continue;
			}

			$text = self::plain_text( (string) $post->post_content );
			if ( '' === $text ) {
				continue;
			}

			$posts[] = array(
				'title' => wp_strip_all_tags( (string) $post->post_title, true ),
				'text'  => $text,
			);
			if ( count( $posts ) >= $size ) {
				break;
			}
		}

		return $posts;
	}

	/**
	 * Post types suggestions read: public ones edited in the editor, where
	 * reviews run.
	 *
	 * @param  string $scope House_Style::SCOPE_SITE or House_Style::SCOPE_USER.
	 * @return list<string> Post type names.
	 */
	public static function get_post_types( string $scope ): array {
		$post_types = array();
		foreach ( get_post_types( array( 'public' => true ) ) as $post_type ) {
			if ( 'attachment' !== $post_type && post_type_supports( $post_type, 'editor' ) ) {
				$post_types[] = $post_type;
			}
		}

		/**
		 * Filters the post types house style rules are suggested from.
		 *
		 * Only published posts the user can read are used, whatever the type.
		 *
		 * @param list<string> $post_types Post type names.
		 * @param string       $scope      'site' or 'user'.
		 */
		$filtered = apply_filters( 'ai_feedback_house_style_sample_post_types', $post_types, $scope );
		/**
		 * A filter can return anything.
		 *
		 * @var mixed $filtered
		 */

		return array_values( array_unique( array_filter( is_array( $filtered ) ? $filtered : array(), 'is_string' ) ) );
	}

	/**
	 * How many posts to read.
	 *
	 * @param  string $scope House_Style::SCOPE_SITE or House_Style::SCOPE_USER.
	 * @return int Between 1 and SAMPLE_SIZE_LIMIT.
	 */
	private static function get_sample_size( string $scope ): int {
		/**
		 * Filters how many recent published posts house style rules are suggested from.
		 *
		 * The text sent is capped per post and in total, so a larger sample
		 * does not make a larger request than a review.
		 *
		 * @param int    $size  Number of posts. Default 5, at most 10.
		 * @param string $scope 'site' or 'user'.
		 */
		$size = apply_filters( 'ai_feedback_house_style_sample_size', self::MAX_POSTS, $scope );
		/**
		 * A filter can return anything.
		 *
		 * @var mixed $size
		 */

		return is_numeric( $size ) ? max( 1, min( self::SAMPLE_SIZE_LIMIT, (int) $size ) ) : self::MAX_POSTS;
	}

	/**
	 * Read post content as plain text, keeping headings recognizable.
	 *
	 * @param  string $content Post content.
	 * @return string Text, without markup, block comments or shortcodes.
	 */
	private static function plain_text( string $content ): string {
		$content = strip_shortcodes( $content );
		// How headings are written is house style, so they stay marked.
		$content = (string) preg_replace( '/<h[1-6]\b[^>]*>/i', "\n\n## ", $content );
		$content = (string) preg_replace( '#</(?:h[1-6]|p|li|blockquote)>#i', "$0\n\n", $content );
		$content = wp_strip_all_tags( $content );
		$content = (string) preg_replace( '/[ \t]+/', ' ', $content );
		$content = (string) preg_replace( '/ ?\n ?/', "\n", $content );

		return trim( (string) preg_replace( '/\n{3,}/', "\n\n", $content ) );
	}
}
