<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package AI_Feedback
 */

// Require Composer autoloader.
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Manually load plugin classes (WordPress filename convention).
$includes_dir = dirname( __DIR__, 2 ) . '/includes/';
require_once $includes_dir . 'class-logger.php';
require_once $includes_dir . 'class-prompt-builder.php';
require_once $includes_dir . 'class-response-parser.php';
require_once $includes_dir . 'class-notes-manager.php';
require_once $includes_dir . 'class-review-service.php';

// Define WordPress constants needed for testing.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

// Mock WordPress translation functions.
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}
}

// Mock WP_Error class if not available.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		private $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

// Mock is_wp_error function.
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return ( $thing instanceof WP_Error );
	}
}
