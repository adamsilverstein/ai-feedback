<?php
/**
 * Fake attachment and uploads API for ReviewImagesTest.
 *
 * Attachments are read from $GLOBALS['test_attachments'], keyed by ID, each
 * with `file` (relative to the uploads dir), `mime` and optional `sizes`.
 *
 * @package AI_Feedback\Tests
 */

// phpcs:disable

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID = 0;
		public string $post_type = 'post';
		public string $post_content = '';
		public string $post_title = '';
		public function __construct( object $fields ) {
			foreach ( get_object_vars( $fields ) as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

function wp_get_upload_dir(): array {
	return array(
		'basedir' => $GLOBALS['test_uploads_dir'],
		'baseurl' => 'http://example.com/wp-content/uploads',
	);
}

function wp_attachment_is_image( int $id ): bool {
	return isset( $GLOBALS['test_attachments'][ $id ] ) && str_starts_with( $GLOBALS['test_attachments'][ $id ]['mime'], 'image/' );
}

function get_attached_file( int $id ): string|false {
	return isset( $GLOBALS['test_attachments'][ $id ] ) ? $GLOBALS['test_uploads_dir'] . '/' . $GLOBALS['test_attachments'][ $id ]['file'] : false;
}

function wp_get_attachment_url( int $id ): string|false {
	return isset( $GLOBALS['test_attachments'][ $id ] ) ? 'http://example.com/wp-content/uploads/' . $GLOBALS['test_attachments'][ $id ]['file'] : false;
}

function image_get_intermediate_size( int $id, string $size ): array|false {
	$file = $GLOBALS['test_attachments'][ $id ]['sizes'][ $size ] ?? null;
	return null === $file ? false : array(
		'file'   => basename( $file ),
		'width'  => 768,
		'height' => 400,
		'path'   => $file,
		'url'    => 'http://example.com/wp-content/uploads/' . $file,
	);
}

function wp_check_filetype( string $filename ): array {
	$types = array(
		'png' => 'image/png',
		'jpg' => 'image/jpeg',
		'gif' => 'image/gif',
		'svg' => 'image/svg+xml',
	);
	$ext   = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	return array(
		'ext'  => $ext,
		'type' => $types[ $ext ] ?? false,
	);
}

// Core's wp_getimagesize() wraps getimagesize(), quietly.
function wp_getimagesize( string $filename ): array|false {
	return @getimagesize( $filename );
}

function wp_get_image_mime( string $file ): string|false {
	$info = @getimagesize( $file );
	return is_array( $info ) && is_string( $info['mime'] ?? null ) ? $info['mime'] : false;
}

/**
 * The start of a PNG file, enough for getimagesize() to read its size.
 */
function ai_feedback_test_png( int $width, int $height, string $padding = '' ): string {
	return "\x89PNG\r\n\x1a\n" . pack( 'N', 13 ) . 'IHDR' . pack( 'NN', $width, $height ) . "\x08\x02\x00\x00\x00" . pack( 'N', 0 ) . $padding;
}

/**
 * The start of a GIF file, enough for getimagesize() to read its size.
 */
function ai_feedback_test_gif( int $width, int $height ): string {
	return 'GIF89a' . pack( 'vv', $width, $height ) . "\x00\x00\x00";
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return parse_url( $url, $component );
	}
}
