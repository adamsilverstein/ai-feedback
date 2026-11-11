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
			$GLOBALS['test_added_actions'][] = array(
				'hook'          => $hook,
				'callback'      => $callback,
				'accepted_args' => $accepted_args,
			);
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
		 * get_comments mock over $GLOBALS['test_comments'].
		 *
		 * Honors `parent`, `type` (comment_type defaults to 'note' when unset),
		 * `number`, and the direction of an `orderby` array. comment_ID stands
		 * in for comment_date as the chronological key.
		 *
		 * @param array $args Query args.
		 * @return array
		 */
		function get_comments( $args = array() ) {
			$GLOBALS['test_get_comments_args'] = $args;

			$store  = $GLOBALS['test_comments'] ?? array();
			$parent = isset( $args['parent'] ) ? (int) $args['parent'] : null;
			$type   = $args['type'] ?? null;

			$matches = array();
			foreach ( $store as $comment ) {
				if ( null !== $parent && (int) ( $comment->comment_parent ?? 0 ) !== $parent ) {
					continue;
				}
				if ( null !== $type && ( $comment->comment_type ?? 'note' ) !== $type ) {
					continue;
				}
				$matches[] = $comment;
			}

			$descending = is_array( $args['orderby'] ?? null )
				&& 'DESC' === strtoupper( (string) reset( $args['orderby'] ) );

			usort(
				$matches,
				static function ( $a, $b ) use ( $descending ) {
					$cmp = ( (int) $a->comment_ID ) <=> ( (int) $b->comment_ID );
					return $descending ? -$cmp : $cmp;
				}
			);

			if ( ! empty( $args['number'] ) ) {
				$matches = array_slice( $matches, 0, (int) $args['number'] );
			}

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

	if ( ! function_exists( 'parse_blocks' ) ) {
		/**
		 * parse_blocks mock returning $GLOBALS['test_parsed_blocks'][ $content ].
		 *
		 * Tests register the parsed structure for a given content string so the
		 * real block parser is not needed. Each call is counted in
		 * $GLOBALS['test_parse_blocks_calls'].
		 *
		 * @param string $content Post content.
		 * @return array<mixed>
		 */
		function parse_blocks( string $content ): array {
			$calls                              = $GLOBALS['test_parse_blocks_calls'] ?? 0;
			$GLOBALS['test_parse_blocks_calls'] = ( is_int( $calls ) ? $calls : 0 ) + 1;

			$registered = $GLOBALS['test_parsed_blocks'] ?? array();
			$blocks     = is_array( $registered ) ? ( $registered[ $content ] ?? array() ) : array();
			return is_array( $blocks ) ? $blocks : array();
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
