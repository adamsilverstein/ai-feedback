<?php
/**
 * Mocks for Reply_Service tests.
 *
 * Declared in the root namespace because Reply_Service calls unqualified
 * WordPress functions (get_comment, get_comments, get_comment_meta,
 * get_option, current_time) that resolve against the global namespace.
 *
 * The reply-detector-mocks file already defines get_comment_meta and
 * do_action — guard against redefinition.
 *
 * @package AI_Feedback\Tests
 */

namespace {

	// Reuse comment meta + do_action mocks if the detector tests loaded first.
	if ( ! function_exists( 'get_comment_meta' ) ) {
		function get_comment_meta( $comment_id, $key = '', $single = false ) {
			$store = $GLOBALS['test_comment_meta'] ?? array();
			$meta  = $store[ $comment_id ] ?? array();
			if ( '' === $key ) {
				return $meta;
			}
			return $meta[ $key ] ?? '';
		}
	}

	if ( ! function_exists( 'do_action' ) ) {
		function do_action( $hook, ...$args ) {
			$GLOBALS['test_dispatched_calls'][] = array(
				'hook' => $hook,
				'args' => $args,
			);
		}
	}

	if ( ! function_exists( 'add_action' ) ) {
		/**
		 * No-op add_action stub — Reply_Service::register() calls this at
		 * construction time in the integration test scenario, but the unit
		 * tests invoke handle_reply() directly and don't need dispatch.
		 */
		function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
			return true;
		}
	}

	if ( ! function_exists( 'get_comment' ) ) {
		/**
		 * Minimal get_comment mock that reads from $GLOBALS['test_comments'].
		 *
		 * @param int $id Comment ID.
		 * @return object|null
		 */
		function get_comment( $id ) {
			$store = $GLOBALS['test_comments'] ?? array();
			return $store[ $id ] ?? null;
		}
	}

	if ( ! function_exists( 'get_comments' ) ) {
		/**
		 * get_comments mock that filters $GLOBALS['test_comments'] by parent
		 * and orders by comment_ID ascending (chronological proxy).
		 *
		 * Only recognises the `parent` arg; other filters (`type`, `status`)
		 * are accepted for signature compatibility but ignored.
		 *
		 * @param array $args Query args.
		 * @return array
		 */
		function get_comments( $args = array() ) {
			$store  = $GLOBALS['test_comments'] ?? array();
			$parent = isset( $args['parent'] ) ? (int) $args['parent'] : null;

			$matches = array();
			foreach ( $store as $comment ) {
				if ( null !== $parent && (int) ( $comment->comment_parent ?? 0 ) !== $parent ) {
					continue;
				}
				$matches[] = $comment;
			}

			usort(
				$matches,
				static function ( $a, $b ) {
					return ( (int) $a->comment_ID ) <=> ( (int) $b->comment_ID );
				}
			);

			return $matches;
		}
	}

	if ( ! function_exists( 'get_option' ) ) {
		/**
		 * get_option mock reading from $GLOBALS['test_options'] with a
		 * caller-supplied default.
		 *
		 * @param string $key
		 * @param mixed  $default_value
		 * @return mixed
		 */
		function get_option( $key, $default_value = false ) {
			$opts = $GLOBALS['test_options'] ?? array();
			return array_key_exists( $key, $opts ) ? $opts[ $key ] : $default_value;
		}
	}

	if ( ! function_exists( 'current_time' ) ) {
		function current_time( $type, $gmt = 0 ) {
			return '2026-04-18 10:34:00';
		}
	}

	if ( ! function_exists( 'get_locale' ) ) {
		function get_locale() {
			return 'en_US';
		}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $_hook_name, $value, ...$args ) {
			return $value;
		}
	}
}
