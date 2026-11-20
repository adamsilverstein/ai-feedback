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
		 * With $prev_value it only writes over a matching value, like core's
		 * compare-and-set update.
		 *
		 * @param int    $comment_id Comment ID.
		 * @param string $key        Meta key.
		 * @param mixed  $value      Meta value.
		 * @param mixed  $prev_value Value the stored one must match.
		 * @return bool
		 */
		function update_comment_meta( $comment_id, $key, $value, $prev_value = '' ) {
			if ( isset( $GLOBALS['test_before_meta_write'] ) && is_callable( $GLOBALS['test_before_meta_write'] ) ) {
				( $GLOBALS['test_before_meta_write'] )( $comment_id, $key );
			}
			if ( ! isset( $GLOBALS['test_comment_meta'][ $comment_id ] ) ) {
				$GLOBALS['test_comment_meta'][ $comment_id ] = array();
			}
			if ( '' !== $prev_value && ( $GLOBALS['test_comment_meta'][ $comment_id ][ $key ] ?? null ) != $prev_value ) {
				return false;
			}
			// Like core's update_metadata(), which unslashes the value.
			$GLOBALS['test_comment_meta'][ $comment_id ][ $key ] = wp_unslash( $value );
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
		 * @param bool   $wp_error
		 * @return bool|WP_Error
		 */
		function wp_schedule_single_event( $timestamp, $hook, $args = array(), $wp_error = false ) {
			if ( ! empty( $GLOBALS['test_schedule_fails'] ) ) {
				return $wp_error ? new WP_Error( 'schedule_event_false', 'Could not schedule.' ) : false;
			}
			$GLOBALS['test_scheduled_events'][] = array(
				'timestamp' => (int) $timestamp,
				'hook'      => $hook,
				'args'      => $args,
			);
			return true;
		}
	}

	if ( ! function_exists( 'wp_unschedule_event' ) ) {
		/**
		 * Removes a matching event from the queue.
		 *
		 * @param int    $timestamp
		 * @param string $hook
		 * @param array  $args
		 * @return bool
		 */
		function wp_unschedule_event( $timestamp, $hook, $args = array() ) {
			foreach ( $GLOBALS['test_scheduled_events'] ?? array() as $index => $event ) {
				if ( $event['hook'] === $hook && ( $event['args'] ?? array() ) === $args ) {
					unset( $GLOBALS['test_scheduled_events'][ $index ] );
					return true;
				}
			}
			return false;
		}
	}

	if ( ! function_exists( 'delete_comment_meta' ) ) {
		/**
		 * Removes a meta key from the shared store.
		 *
		 * @param int    $comment_id
		 * @param string $key
		 * @return bool
		 */
		function delete_comment_meta( $comment_id, $key ) {
			unset( $GLOBALS['test_comment_meta'][ $comment_id ][ $key ] );
			return true;
		}
	}

	if ( ! function_exists( 'add_comment_meta' ) ) {
		/**
		 * Adds a meta value; with $unique, fails when the key already exists.
		 *
		 * @param int    $comment_id
		 * @param string $key
		 * @param mixed  $value
		 * @param bool   $unique
		 * @return int|false
		 */
		function add_comment_meta( $comment_id, $key, $value, $unique = false ) {
			if ( isset( $GLOBALS['test_before_meta_write'] ) && is_callable( $GLOBALS['test_before_meta_write'] ) ) {
				( $GLOBALS['test_before_meta_write'] )( $comment_id, $key );
			}
			if ( $unique && isset( $GLOBALS['test_comment_meta'][ $comment_id ][ $key ] ) ) {
				return false;
			}
			$GLOBALS['test_comment_meta'][ $comment_id ][ $key ] = $value;
			return 1;
		}
	}

	if ( ! function_exists( 'wp_cache_delete' ) ) {
		/**
		 * No-op cache delete; the meta store has no cache.
		 *
		 * @param int|string $key
		 * @param string     $group
		 * @return bool
		 */
		function wp_cache_delete( $key, $group = '' ) {
			return true;
		}
	}

	if ( ! function_exists( 'add_action' ) ) {
		/**
		 * add_action mock that records registrations in $GLOBALS['test_added_actions'].
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
}
