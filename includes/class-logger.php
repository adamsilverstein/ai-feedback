<?php
/**
 * Logger utility class
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

/**
 * Simple logging utility for debugging.
 */
class Logger {



	/**
	 * Log a debug message.
	 *
	 * @param string $message The message to log.
	 */
	public static function debug( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[AI_Feedback] ' . $message );
		}
	}
}
