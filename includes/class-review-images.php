<?php
/**
 * Review Images
 *
 * Picks the images a review may show a vision model, so it can propose alt
 * text.
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

/**
 * Collects images for a review.
 *
 * Only images from the post's own image blocks that have no alt text are
 * sent, and only when the block shows a Media Library image the current
 * user can read. The file is read from the uploads directory, never fetched
 * from a URL, so a block pointing at another site or an internal address is
 * skipped and the AI only flags its missing alt text.
 */
class Review_Images {

	/**
	 * Most images sent with one review request.
	 */
	public const MAX_IMAGES = 4;

	/**
	 * Largest image file sent, in bytes.
	 */
	public const MAX_BYTES = 1572864;

	/**
	 * Largest total of image files sent with one request, in bytes, so a
	 * request stays well under provider and proxy body limits once base64
	 * encoded.
	 */
	public const MAX_TOTAL_BYTES = 3145728;

	/**
	 * Image types sent.
	 */
	public const MIME_TYPES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

	/**
	 * Image sizes tried, smallest useful first, before the original file.
	 */
	public const SIZES = array( 'medium_large', 'large', 'medium' );

	/**
	 * Collect the images to send with a review.
	 *
	 * @param  array<mixed> $blocks Blocks from the review request.
	 * @return list<array{block_id: string, mime: string, data: string}> Images, in block order, with base64 data.
	 */
	public function collect( array $blocks ): array {
		/**
		 * Filters how many images a review may send to a vision model.
		 *
		 * Return 0 to never send images; the AI then only flags missing alt text.
		 * Raising it raises memory use and request size: each image is held
		 * in memory, base64 encoded, up to MAX_TOTAL_BYTES in all.
		 *
		 * @param int $max Maximum images per review request.
		 */
		$max    = max( 0, (int) apply_filters( 'ai_feedback_max_review_images', self::MAX_IMAGES ) );
		$images = array();
		$total  = 0;

		foreach ( $blocks as $block ) {
			if ( count( $images ) >= $max ) {
				break;
			}
			if ( ! is_array( $block ) || 'core/image' !== ( $block['name'] ?? '' ) || ! is_string( $block['clientId'] ?? null ) ) {
				continue;
			}
			$attrs = Block_Change::get_block_attributes( $block );
			$alt   = is_string( $attrs['alt'] ?? null ) ? $attrs['alt'] : '';
			$id    = is_int( $attrs['id'] ?? null ) ? $attrs['id'] : 0;
			$url   = is_string( $attrs['url'] ?? null ) ? $attrs['url'] : '';
			if ( '' !== trim( $alt ) || 0 === $id || '' === $url ) {
				continue;
			}

			$file = $this->get_image_file( $id, $url );
			if ( null === $file ) {
				Logger::debug( sprintf( 'Image block %s not sent: no readable local file for attachment %d', $block['clientId'], $id ) );
				continue;
			}
			$data = $this->read_file( $file['path'] );
			if ( null === $data || $total + strlen( $data ) > self::MAX_TOTAL_BYTES ) {
				Logger::debug( sprintf( 'Image block %s not sent: unreadable, or over the size limit for one request', $block['clientId'] ) );
				continue;
			}
			$total += strlen( $data );

			$images[] = array(
				'block_id' => $block['clientId'],
				'mime'     => $file['mime'],
				'data'     => base64_encode( $data ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Image data for the AI client.
			);
		}

		return $images;
	}

	/**
	 * Find a local file for an attachment the block shows.
	 *
	 * @param  int    $attachment_id Attachment ID from the block.
	 * @param  string $url           Image URL from the block.
	 * @return array{path: string, mime: string}|null File path and type, or null when it can't be sent.
	 */
	protected function get_image_file( int $attachment_id, string $url ): ?array {
		$attachment = get_post( $attachment_id );
		if (
			! $attachment instanceof \WP_Post
			|| 'attachment' !== $attachment->post_type
			|| ! wp_attachment_is_image( $attachment_id )
			|| ! current_user_can( 'read_post', $attachment_id )
			|| ! $this->url_matches_attachment( $url, $attachment_id )
		) {
			return null;
		}

		$uploads = wp_get_upload_dir();
		$basedir = realpath( $uploads['basedir'] );
		if ( false === $basedir ) {
			return null;
		}

		$candidates = array();
		foreach ( self::SIZES as $size ) {
			$data = image_get_intermediate_size( $attachment_id, $size );
			if ( is_array( $data ) && '' !== $data['path'] ) {
				$candidates[] = $uploads['basedir'] . '/' . $data['path'];
			}
		}
		$original = get_attached_file( $attachment_id );
		if ( is_string( $original ) && '' !== $original ) {
			$candidates[] = $original;
		}

		foreach ( $candidates as $candidate ) {
			$path = realpath( $candidate );
			// Only files inside the uploads directory.
			if ( false === $path || ! str_starts_with( $path, $basedir . DIRECTORY_SEPARATOR ) || ! is_file( $path ) ) {
				continue;
			}
			$mime = wp_check_filetype( $path )['type'];
			$size = filesize( $path );
			if ( in_array( $mime, self::MIME_TYPES, true ) && false !== $size && $size > 0 && $size <= self::MAX_BYTES ) {
				return array(
					'path' => $path,
					'mime' => (string) $mime,
				);
			}
		}

		return null;
	}

	/**
	 * Whether a block's image URL is a file of the attachment, so an ID
	 * left over from another image is never sent.
	 *
	 * @param  string $url           Image URL from the block.
	 * @param  int    $attachment_id Attachment ID.
	 * @return bool
	 */
	protected function url_matches_attachment( string $url, int $attachment_id ): bool {
		$attachment_url = wp_get_attachment_url( $attachment_id );
		if ( ! is_string( $attachment_url ) || '' === $attachment_url ) {
			return false;
		}
		$path     = wp_parse_url( $url, PHP_URL_PATH );
		$expected = wp_parse_url( $attachment_url, PHP_URL_PATH );
		if ( ! is_string( $path ) || ! is_string( $expected ) || dirname( $path ) !== dirname( $expected ) ) {
			return false;
		}

		// A size of the image adds "-{width}x{height}" or "-scaled" to its name.
		$stem = preg_replace( '/-scaled$/', '', pathinfo( $expected, PATHINFO_FILENAME ) );
		$name = pathinfo( $path, PATHINFO_FILENAME );
		return is_string( $stem ) && '' !== $stem && 1 === preg_match( '/^' . preg_quote( $stem, '/' ) . '(?:-scaled)?(?:-\d+x\d+)?$/', $name );
	}

	/**
	 * Read a local image file.
	 *
	 * @param  string $path File path inside the uploads directory.
	 * @return string|null File contents, or null when unreadable.
	 */
	protected function read_file( string $path ): ?string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file in uploads, never a URL.
		$data = file_get_contents( $path );
		return is_string( $data ) && '' !== $data && strlen( $data ) <= self::MAX_BYTES ? $data : null;
	}
}
