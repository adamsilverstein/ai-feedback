<?php
/**
 * Global-namespace stubs for Reply_Status_Controller tests.
 *
 * The PHPUnit bootstrap provides WP_REST_Request, WP_REST_Response and
 * WP_Error; these add the comment and REST helpers the controller needs.
 *
 * @package AI_Feedback\Tests
 */

// phpcs:disable

namespace {

	if ( ! class_exists( 'WP_Comment' ) ) {
		/**
		 * Minimal WP_Comment stand-in.
		 */
		class WP_Comment {
			public $comment_ID      = '0';
			public $comment_post_ID = '0';
			public $comment_parent  = '0';
			public $comment_type    = 'note';
			public $comment_content = '';
			public $comment_author  = '';

			public function __construct( $comment = null ) {
				foreach ( (array) $comment as $key => $value ) {
					$this->$key = $value;
				}
			}
		}
	}

	if ( ! function_exists( 'get_comment' ) ) {
		function get_comment( $id ) {
			return $GLOBALS['test_comments'][ $id ] ?? null;
		}
	}

	if ( ! function_exists( 'absint' ) ) {
		function absint( $maybeint ) {
			return abs( (int) $maybeint );
		}
	}

	if ( ! function_exists( 'rest_authorization_required_code' ) ) {
		function rest_authorization_required_code() {
			return 403;
		}
	}
}
