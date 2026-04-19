<?php
/**
 * Mocks for Reply_Cron_Dispatcher and status-tracking tests.
 *
 * Declared in the root namespace because Reply_Cron_Dispatcher and
 * Reply_Service call unqualified WP functions (get_comment_meta,
 * update_comment_meta, wp_next_scheduled, wp_schedule_single_event,
 * time, add_action) that resolve against the global namespace.
 *
 * Shared fixtures:
 * - $GLOBALS['test_comment_meta'] — read/write store for *_comment_meta()
 * - $GLOBALS['test_scheduled_events'] — cron queue
 *
 * @package AI_Feedback\Tests
 */

namespace {

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

	if ( ! function_exists( 'update_comment_meta' ) ) {
		/**
		 * Minimal update_comment_meta mock — last-write-wins into the shared store.
		 *
		 * @param int    $comment_id Comment ID.
		 * @param string $key        Meta key.
		 * @param mixed  $value      Meta value.
		 * @return bool
		 */
		function update_comment_meta( $comment_id, $key, $value ) {
			if ( ! isset( $GLOBALS['test_comment_meta'][ $comment_id ] ) ) {
				$GLOBALS['test_comment_meta'][ $comment_id ] = array();
			}
			$GLOBALS['test_comment_meta'][ $comment_id ][ $key ] = $value;
			return true;
		}
	}

	if ( ! function_exists( 'wp_next_scheduled' ) ) {
		/**
		 * Returns the timestamp of a matching scheduled event, or false.
		 *
		 * Matches on ( hook, args ) like WP's own implementation.
		 *
		 * @param string $hook
		 * @param array  $args
		 * @return int|false
		 */
		function wp_next_scheduled( $hook, $args = array() ) {
			foreach ( $GLOBALS['test_scheduled_events'] ?? array() as $event ) {
				if ( $event['hook'] === $hook && ( $event['args'] ?? array() ) === $args ) {
					return (int) ( $event['timestamp'] ?? 0 );
				}
			}
			return false;
		}
	}

	if ( ! function_exists( 'wp_schedule_single_event' ) ) {
		/**
		 * Minimal wp_schedule_single_event mock — appends to the queue.
		 *
		 * @param int    $timestamp
		 * @param string $hook
		 * @param array  $args
		 * @return bool
		 */
		function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
			$GLOBALS['test_scheduled_events'][] = array(
				'timestamp' => (int) $timestamp,
				'hook'      => $hook,
				'args'      => $args,
			);
			return true;
		}
	}

	if ( ! function_exists( 'add_action' ) ) {
		/**
		 * No-op add_action — unit tests invoke methods directly rather than via the hook.
		 */
		function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
			return true;
		}
	}

	if ( ! function_exists( 'time' ) ) {
		// PHP's `time()` already exists, but keep this slot in case a future
		// test wants a deterministic value — currently unused.
	}
}
