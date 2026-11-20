<?php
/**
 * WordPress stand-ins for the house style tests.
 *
 * Comment helpers come from the shared reply mocks, so every test sees the
 * same versions whichever file loads first.
 *
 * @package AI_Feedback\Tests
 */

// phpcs:disable

require_once __DIR__ . '/reply-cron-mocks.php';
require_once __DIR__ . '/reply-status-controller-bootstrap.php';

if ( ! function_exists( 'delete_user_meta' ) ) {
	function delete_user_meta( $user_id, $key, $value = '' ) {
		unset( $GLOBALS['test_user_meta'][ $user_id ][ $key ] );
		return true;
	}
}
