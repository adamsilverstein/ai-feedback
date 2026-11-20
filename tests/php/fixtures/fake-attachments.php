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

function wp_get_upload_dir() {
	return array(
		'basedir' => $GLOBALS['test_uploads_dir'],
		'baseurl' => 'http://example.com/wp-content/uploads',
	);
}

function wp_attachment_is_image( $id ) {
	return isset( $GLOBALS['test_attachments'][ $id ] ) && str_starts_with( $GLOBALS['test_attachments'][ $id ]['mime'], 'image/' );
}

function get_attached_file( $id ) {
	return isset( $GLOBALS['test_attachments'][ $id ] ) ? $GLOBALS['test_uploads_dir'] . '/' . $GLOBALS['test_attachments'][ $id ]['file'] : false;
}

function wp_get_attachment_url( $id ) {
	return isset( $GLOBALS['test_attachments'][ $id ] ) ? 'http://example.com/wp-content/uploads/' . $GLOBALS['test_attachments'][ $id ]['file'] : false;
}

function image_get_intermediate_size( $id, $size ) {
	$file = $GLOBALS['test_attachments'][ $id ]['sizes'][ $size ] ?? null;
	return null === $file ? false : array(
		'file'   => basename( $file ),
		'width'  => 768,
		'height' => 400,
		'path'   => $file,
		'url'    => 'http://example.com/wp-content/uploads/' . $file,
	);
}

function wp_check_filetype( $filename ) {
	$types = array(
		'png'  => 'image/png',
		'jpg'  => 'image/jpeg',
		'svg'  => 'image/svg+xml',
	);
	$ext   = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	return array(
		'ext'  => $ext,
		'type' => $types[ $ext ] ?? false,
	);
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}
