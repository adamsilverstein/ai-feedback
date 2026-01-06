<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package AI_Feedback
 */

// Require Composer autoloader.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

// Manually load plugin classes (WordPress filename convention).
$includes_dir = dirname(__DIR__, 2) . '/includes/';
require_once $includes_dir . 'class-logger.php';
require_once $includes_dir . 'class-prompt-builder.php';
require_once $includes_dir . 'class-response-parser.php';
require_once $includes_dir . 'class-notes-manager.php';
require_once $includes_dir . 'class-review-service.php';

// Define WordPress constants needed for testing.
if (! defined('ABSPATH') ) {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
}

// Mock WordPress translation functions.
if (! function_exists('__') ) {
    function __( $text, $domain = 'default' )
    {
        return $text;
    }
}

if (! function_exists('esc_html__') ) {
    function esc_html__( $text, $domain = 'default' )
    {
        return $text;
    }
}

// Mock WP_Error class if not available.
if (! class_exists('WP_Error') ) {
    class WP_Error
    {
        private $errors = array();
        private $error_data = array();

        /**
         * Create a WP_Error instance and optionally add an initial error.
         *
         * If `$code` is not an empty string, the provided error (code, message, and data)
         * is added to the new instance.
         *
         * @param string $code    Error code. When empty, no initial error is added.
         * @param string $message Human-readable error message.
         * @param mixed  $data    Optional error data; can be any type associated with the error.
         */
        public function __construct( $code = '', $message = '', $data = '' )
        {
            if (! empty($code) ) {
                $this->add($code, $message, $data);
            }
        }

        /**
         * Adds an error message for a given error code and optionally associates data with that code.
         *
         * @param string $code    Error code identifier.
         * @param string $message Error message to record for the code.
         * @param mixed  $data    Optional data to associate with the error code.
         */
        public function add( $code, $message, $data = '' )
        {
            $this->errors[ $code ][] = $message;
            if (! empty($data) ) {
                $this->error_data[ $code ] = $data;
            }
        }

        /**
         * Get the first stored error code.
         *
         * @return string The first error code, or an empty string if no error codes exist.
         */
        public function get_error_code()
        {
            $codes = $this->get_error_codes();
            return $codes[0] ?? '';
        }

        / **
        * Retrieve all error codes stored in the object.
        *
        * @return array An array of error codes present in the error collection; empty if no errors.
        */
        public function get_error_codes()
        {
            return array_keys($this->errors);
        }

        /**
         * Retrieve the first error message for a given error code.
         *
         * If `$code` is empty, the first available error code is used.
         *
         * @param  string $code Optional error code to fetch the message for.
         * @return string The first message for the specified code, or an empty string if none exists.
         */
        public function get_error_message( $code = '' )
        {
            if (empty($code) ) {
                $code = $this->get_error_code();
            }
            return $this->errors[ $code ][0] ?? '';
        }

        /**
         * Retrieve error messages for a specific error code or all messages when no code is provided.
         *
         * @param  string $code Optional error code to filter messages by. When empty, messages for all codes are returned.
         * @return string[] An array of error message strings: messages for `$code` if provided, otherwise all messages across codes.
         */
        public function get_error_messages( $code = '' )
        {
            if (empty($code) ) {
                $all_messages = array();
                foreach ( $this->errors as $messages ) {
                    $all_messages = array_merge($all_messages, $messages);
                }
                return $all_messages;
            }
            return $this->errors[ $code ] ?? array();
        }

        /**
         * Retrieve the associated data for a specific error code.
         *
         * If `$code` is empty, the first registered error code is used.
         *
         * @param  string $code Optional error code to fetch data for.
         * @return mixed|null The associated data for the given error code, or `null` if no data exists.
         */
        public function get_error_data( $code = '' )
        {
            if (empty($code) ) {
                $code = $this->get_error_code();
            }
            return $this->error_data[ $code ] ?? null;
        }

        /**
         * Determine whether the error container contains any errors.
         *
         * @return bool `true` if one or more errors are present, `false` otherwise.
         */
        public function has_errors()
        {
            return ! empty($this->errors);
        }
    }
}

// Mock is_wp_error function.
if (! function_exists('is_wp_error') ) {
    /**
     * Determine whether a value is a WP_Error object.
     *
     * @param  mixed $thing The value to check.
     * @return bool `true` if `$thing` is an instance of `WP_Error`, `false` otherwise.
     */
    function is_wp_error( $thing )
    {
        return ( $thing instanceof WP_Error );
    }
}

// Mock WordPress sanitization functions.
if (! function_exists('wp_kses_post') ) {
    /**
     * Passes post content through post-safe HTML sanitization; in this test bootstrap this is a no-op.
     *
     * @param  mixed $data The data to sanitize (string, array, or null).
     * @return mixed The original `$data` unchanged.
     */
    function wp_kses_post( $data )
    {
        return $data;
    }
}

if (! function_exists('wp_kses') ) {
    /**
     * Passthrough placeholder for `wp_kses` used in tests that returns the input unchanged.
     *
     * @param  mixed $data              The data to be filtered (string or array of strings).
     * @param  array $allowed_html      Array of allowed HTML tags and attributes (ignored by this placeholder).
     * @param  array $allowed_protocols List of allowed protocols (ignored by this placeholder).
     * @return mixed The original `$data` unchanged.
     */
    function wp_kses( $data, $allowed_html, $allowed_protocols = array() )
    {
        return $data;
    }
}

if (! function_exists('sanitize_text_field') ) {
    /**
     * No-op sanitizer used in tests that returns the given string unchanged.
     *
     * @param  string $str The input string to sanitize (returned unchanged).
     * @return string The original string unchanged.
     */
    function sanitize_text_field( $str )
    {
        return $str;
    }
}