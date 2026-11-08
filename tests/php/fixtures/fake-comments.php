<?php
/**
 * In-memory stand-ins for the WordPress comment API, for note tests.
 *
 * Reset between tests with ai_feedback_test_reset_comments().
 *
 * @package AI_Feedback\Tests
 */

// phpcs:disable

if ( ! class_exists( 'WP_Comment' ) ) {
	class WP_Comment {
		public $comment_ID       = '0';
		public $comment_post_ID  = '0';
		public $comment_parent   = '0';
		public $comment_type     = 'note';
		public $comment_approved = '0';
		public $comment_author   = '';
		public $comment_content  = '';
		public $comment_date     = '2026-01-01 00:00:00';
		public $user_id          = '0';
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public $ID           = 0;
		public $display_name = '';
	}
}

function ai_feedback_test_reset_comments(): void {
	$GLOBALS['test_comments']      = array();
	$GLOBALS['test_comment_meta']  = array();
	$GLOBALS['test_next_comment']  = 1;
	$GLOBALS['test_current_user']  = null;
}
ai_feedback_test_reset_comments();

if ( ! function_exists( 'wp_insert_comment' ) ) {
	function wp_insert_comment( $data ) {
		$id      = $GLOBALS['test_next_comment']++;
		$comment = new WP_Comment();

		$comment->comment_ID       = (string) $id;
		$comment->comment_post_ID  = (string) ( $data['comment_post_ID'] ?? 0 );
		$comment->comment_parent   = (string) ( $data['comment_parent'] ?? 0 );
		$comment->comment_type     = $data['comment_type'] ?? 'note';
		$comment->comment_approved = (string) ( $data['comment_approved'] ?? '0' );
		$comment->comment_author   = $data['comment_author'] ?? '';
		$comment->comment_content  = $data['comment_content'] ?? '';
		$comment->user_id          = (string) ( $data['user_id'] ?? 0 );

		$GLOBALS['test_comments'][ $id ] = $comment;

		foreach ( $data['comment_meta'] ?? array() as $key => $value ) {
			$GLOBALS['test_comment_meta'][ $id ][ $key ] = $value;
		}

		return $id;
	}
}

if ( ! function_exists( 'get_comment' ) ) {
	function get_comment( $id ) {
		return $GLOBALS['test_comments'][ (int) $id ] ?? null;
	}
}

if ( ! function_exists( 'get_comments' ) ) {
	function get_comments( $args = array() ) {
		return array_values(
			array_filter(
				$GLOBALS['test_comments'],
				static function ( $comment ) use ( $args ) {
					if ( isset( $args['parent'] ) && (int) $comment->comment_parent !== (int) $args['parent'] ) {
						return false;
					}
					if ( isset( $args['type'] ) && $comment->comment_type !== $args['type'] ) {
						return false;
					}
					return true;
				}
			)
		);
	}
}

if ( ! function_exists( 'get_comment_meta' ) ) {
	function get_comment_meta( $id, $key = '', $single = false ) {
		return $GLOBALS['test_comment_meta'][ (int) $id ][ $key ] ?? '';
	}
}

if ( ! function_exists( 'update_comment_meta' ) ) {
	function update_comment_meta( $id, $key, $value ) {
		if ( ( $GLOBALS['test_comment_meta'][ (int) $id ][ $key ] ?? null ) === $value ) {
			return false; // Matches core: unchanged values return false.
		}
		$GLOBALS['test_comment_meta'][ (int) $id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_comment_meta' ) ) {
	function delete_comment_meta( $id, $key ) {
		if ( ! isset( $GLOBALS['test_comment_meta'][ (int) $id ][ $key ] ) ) {
			return false;
		}
		unset( $GLOBALS['test_comment_meta'][ (int) $id ][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'wp_set_comment_status' ) ) {
	function wp_set_comment_status( $id, $status ) {
		$comment = get_comment( $id );
		if ( ! $comment ) {
			return false;
		}
		$comment->comment_approved = 'approve' === $status ? '1' : '0';
		return true;
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() {
		if ( ! $GLOBALS['test_current_user'] ) {
			$user               = new WP_User();
			$user->ID           = 5;
			$user->display_name = 'Editor';
			$GLOBALS['test_current_user'] = $user;
		}
		return $GLOBALS['test_current_user'];
	}
}
