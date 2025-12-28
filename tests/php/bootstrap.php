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
		private $errors = array();
		private $error_data = array();

		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( ! empty( $code ) ) {
				$this->add( $code, $message, $data );
			}
		}

		public function add( $code, $message, $data = '' ) {
			$this->errors[ $code ][] = $message;
			if ( ! empty( $data ) ) {
				$this->error_data[ $code ] = $data;
			}
		}

		public function get_error_code() {
			$codes = $this->get_error_codes();
			return $codes[0] ?? '';
		}

		public function get_error_codes() {
			return array_keys( $this->errors );
		}

		public function get_error_message( $code = '' ) {
			if ( empty( $code ) ) {
				$code = $this->get_error_code();
			}
			return $this->errors[ $code ][0] ?? '';
		}

		public function get_error_messages( $code = '' ) {
			if ( empty( $code ) ) {
				$all_messages = array();
				foreach ( $this->errors as $messages ) {
					$all_messages = array_merge( $all_messages, $messages );
				}
				return $all_messages;
			}
			return $this->errors[ $code ] ?? array();
		}

		public function get_error_data( $code = '' ) {
			if ( empty( $code ) ) {
				$code = $this->get_error_code();
			}
			return $this->error_data[ $code ] ?? null;
		}

		public function has_errors() {
			return ! empty( $this->errors );
		}
	}
}

// Mock is_wp_error function.
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return ( $thing instanceof WP_Error );
	}
}

// Mock WordPress sanitization functions.
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $data ) {
		return $data;
	}
}

if ( ! function_exists( 'wp_kses' ) ) {
	function wp_kses( $data, $allowed_html, $allowed_protocols = array() ) {
		return $data;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return $str;
	}
}
