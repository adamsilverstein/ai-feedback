<?php
/**
 * Mocks for Reply_Detector tests.
 *
 * Declared in the root namespace because Reply_Detector calls
 * unqualified WordPress functions (get_comment_meta, do_action), and
 * PHP resolves those against the global namespace when not found in
 * the caller's namespace.
 *
 * @package AI_Feedback\Tests
 */

namespace {

	if ( ! function_exists( 'get_comment_meta' ) ) {
		/**
		 * Minimal get_comment_meta mock.
		 *
		 * Reads from $GLOBALS['test_comment_meta'][ $comment_id ][ $key ].
		 * Returns '' when unset, matching WP's single=true behavior.
		 *
		 * @param int    $comment_id Comment ID.
		 * @param string $key        Meta key.
		 * @param bool   $single     Whether to return a single value (ignored).
		 * @return mixed
		 */
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
		/**
		 * Minimal do_action mock.
		 *
		 * Appends each dispatch to $GLOBALS['test_dispatched_calls'] as
		 * `array( 'hook' => string, 'args' => array )` so tests can
		 * assert on hook name and arguments.
		 *
		 * @param string $hook Hook name.
		 * @param mixed  ...$args Hook arguments.
		 */
		function do_action( $hook, ...$args ) {
			$GLOBALS['test_dispatched_calls'][] = array(
				'hook' => $hook,
				'args' => $args,
			);
		}
	}
}

namespace AI_Feedback\Tests {
	/**
	 * Marker so the test file can avoid re-requiring this file.
	 */
	function __register_mocks(): void {}
}
