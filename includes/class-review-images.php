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
	 * Image types that may be sent, before the provider's own list.
	 */
	public const MIME_TYPES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

	/**
	 * Image types sent to a provider unless `ai_feedback_review_image_types`
	 * says otherwise. Core's model metadata lists no input file types, and
	 * no provider's list could be checked in its source, so GIF, which not
	 * every provider takes, is left out.
	 */
	public const DEFAULT_PROVIDER_TYPES = array( 'image/jpeg', 'image/png', 'image/webp' );

	/**
	 * Longest side, in pixels, of an image sent unless
	 * `ai_feedback_review_image_max_side` says otherwise. Kept well under
	 * the limits providers document, which could not be checked in their
	 * source; a smaller size is chosen when an image is larger.
	 */
	public const DEFAULT_MAX_SIDE = 2000;

	/**
	 * Why an image was left out: the provider doesn't take its type, or
	 * the file isn't the image its name says.
	 */
	public const SKIP_TYPE = 'type';

	/**
	 * Why an image was left out: no size of it fits the provider's limit
	 * on the longest side.
	 */
	public const SKIP_DIMENSIONS = 'dimensions';

	/**
	 * Why an image was left out: its file, or the request's images in all,
	 * is over the size limit.
	 */
	public const SKIP_FILE_SIZE = 'file_size';

	/**
	 * Images left out of the last collect(), with why.
	 *
	 * @var list<array{block_id: string, reason: string}>
	 */
	private array $skipped = array();

	/**
	 * Image sizes tried, smallest useful first, before the original file.
	 */
	public const SIZES = array( 'medium_large', 'large', 'medium' );

	/**
	 * Collect the images to send with a review.
	 *
	 * Images the provider would refuse are left out one by one, and listed
	 * by get_skipped().
	 *
	 * @param  array<mixed> $blocks   Blocks from the review request.
	 * @param  string       $provider Provider ID of the review's model, or '' when unknown.
	 * @return list<array{block_id: string, mime: string, data: string}> Images, in block order, with base64 data.
	 */
	public function collect( array $blocks, string $provider = '' ): array {
		/**
		 * Filters how many images a review may send to a vision model.
		 *
		 * Return 0 to never send images; the AI then only flags missing alt text.
		 * Raising it raises memory use and request size: each image is held
		 * in memory, base64 encoded, up to MAX_TOTAL_BYTES in all.
		 *
		 * @param int $max Maximum images per review request.
		 */
		$max           = max( 0, (int) apply_filters( 'ai_feedback_max_review_images', self::MAX_IMAGES ) );
		$images        = array();
		$total         = 0;
		$this->skipped = array();
		$types         = self::get_provider_types( $provider );
		$max_side      = self::get_max_side( $provider );

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

			$file = $this->get_image_file( $id, $url, $types, $max_side );
			if ( is_string( $file ) ) {
				Logger::debug( sprintf( 'Image block %s not sent: no file of attachment %d the provider takes (%s)', $block['clientId'], $id, $file ) );
				$this->skipped[] = array(
					'block_id' => $block['clientId'],
					'reason'   => $file,
				);
				continue;
			}
			if ( null === $file ) {
				Logger::debug( sprintf( 'Image block %s not sent: no readable local file for attachment %d', $block['clientId'], $id ) );
				continue;
			}
			$data = $this->read_file( $file['path'] );
			if ( null === $data ) {
				Logger::debug( sprintf( 'Image block %s not sent: unreadable', $block['clientId'] ) );
				continue;
			}
			if ( $total + strlen( $data ) > self::MAX_TOTAL_BYTES ) {
				Logger::debug( sprintf( 'Image block %s not sent: over the size limit for one request', $block['clientId'] ) );
				$this->skipped[] = array(
					'block_id' => $block['clientId'],
					'reason'   => self::SKIP_FILE_SIZE,
				);
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
	 * Whether any block is an image collect() might send: a Media Library
	 * image without alt text. Cheap, so the model's provider is only looked
	 * up when there may be images.
	 *
	 * @param  array<mixed> $blocks Blocks from the review request.
	 * @return bool
	 */
	public static function has_candidates( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || 'core/image' !== ( $block['name'] ?? '' ) ) {
				continue;
			}
			$attrs = Block_Change::get_block_attributes( $block );
			if ( '' === trim( is_string( $attrs['alt'] ?? null ) ? $attrs['alt'] : '' ) && is_int( $attrs['id'] ?? null ) && 0 !== $attrs['id'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Images the last collect() left out because the provider would refuse
	 * them or the request would be too large, in block order.
	 *
	 * Images that are never sent (with alt text, external, private, or not
	 * a local image) aren't listed.
	 *
	 * @return list<array{block_id: string, reason: string}> Block IDs, with a SKIP_* reason.
	 */
	public function get_skipped(): array {
		return $this->skipped;
	}

	/**
	 * Image types a provider is sent.
	 *
	 * @param  string $provider Provider ID, or '' when unknown.
	 * @return list<string> MIME types, never more than MIME_TYPES.
	 */
	public static function get_provider_types( string $provider ): array {
		/**
		 * Filters the image types a provider is sent for alt text.
		 *
		 * Only JPEG, PNG, GIF and WebP can be sent. GIF is left out by
		 * default; add `image/gif` for a provider known to take it.
		 *
		 * @param list<string> $types    MIME types. Default JPEG, PNG and WebP.
		 * @param string       $provider Provider ID, or '' when the model isn't in the model list.
		 */
		$filtered = apply_filters( 'ai_feedback_review_image_types', self::DEFAULT_PROVIDER_TYPES, $provider );

		/**
		 * Types after the filter, which can return anything; only the four
		 * types are ever sent.
		 *
		 * @var mixed $types
		 */
		$types = $filtered;
		return array_values( array_intersect( self::MIME_TYPES, is_array( $types ) ? array_filter( $types, 'is_string' ) : array() ) );
	}

	/**
	 * Longest side, in pixels, of an image a provider is sent.
	 *
	 * @param  string $provider Provider ID, or '' when unknown.
	 * @return int Pixels, at least 1.
	 */
	public static function get_max_side( string $provider ): int {
		/**
		 * Filters the longest side, in pixels, of an image a provider is
		 * sent for alt text. A smaller size of the image is sent when one
		 * fits, else the image is left out.
		 *
		 * @param int    $max_side Pixels. Default 2000.
		 * @param string $provider Provider ID, or '' when the model isn't in the model list.
		 */
		return max( 1, (int) apply_filters( 'ai_feedback_review_image_max_side', self::DEFAULT_MAX_SIDE, $provider ) );
	}

	/**
	 * Find a local file for an attachment the block shows.
	 *
	 * The type and dimensions are read from the file's contents, not its
	 * name, so a mislabeled file is sent as what it is, or left out. Returns
	 * a SKIP_* reason when the image exists but the provider would refuse
	 * every file of it, and null when the image is never sent.
	 *
	 * @param  int      $attachment_id Attachment ID from the block.
	 * @param  string   $url           Image URL from the block.
	 * @param  string[] $types         Image types the provider takes.
	 * @param  int      $max_side      Longest side the provider takes, in pixels.
	 * @return array{path: string, mime: string}|string|null File path and real type, a reason, or null.
	 */
	protected function get_image_file( int $attachment_id, string $url, array $types, int $max_side ): array|string|null {
		$attachment = get_post( $attachment_id );
		if (
			! $attachment instanceof \WP_Post
			|| 'attachment' !== $attachment->post_type
			|| ! wp_attachment_is_image( $attachment_id )
			|| ! current_user_can( 'read_post', $attachment_id )
		) {
			return null;
		}
		if ( ! $this->url_matches_attachment( $url, $attachment_id ) ) {
			Logger::debug( sprintf( 'Image URL does not match attachment %d, so it is not sent', $attachment_id ) );
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

		// The most telling reason a file was refused: its type, then its
		// dimensions, then its size.
		$reasons = array();
		foreach ( $candidates as $candidate ) {
			$path = realpath( $candidate );
			// Only files inside the uploads directory.
			if ( false === $path || ! str_starts_with( $path, $basedir . DIRECTORY_SEPARATOR ) || ! is_file( $path ) ) {
				continue;
			}
			// A file whose name isn't an image type is never read.
			if ( ! in_array( wp_check_filetype( $path )['type'], self::MIME_TYPES, true ) ) {
				continue;
			}
			$size = filesize( $path );
			if ( false === $size || 0 === $size ) {
				continue;
			}
			if ( $size > self::MAX_BYTES ) {
				$reasons[ self::SKIP_FILE_SIZE ] = true;
				continue;
			}
			$image = self::read_image_info( $path );
			if ( null === $image || ! in_array( $image['mime'], $types, true ) ) {
				$reasons[ self::SKIP_TYPE ] = true;
				continue;
			}
			if ( max( $image['width'], $image['height'] ) > $max_side ) {
				$reasons[ self::SKIP_DIMENSIONS ] = true;
				continue;
			}
			return array(
				'path' => $path,
				'mime' => $image['mime'],
			);
		}

		foreach ( array( self::SKIP_TYPE, self::SKIP_DIMENSIONS, self::SKIP_FILE_SIZE ) as $reason ) {
			if ( isset( $reasons[ $reason ] ) ) {
				return $reason;
			}
		}
		return null;
	}

	/**
	 * The real type and dimensions of an image file, from its contents.
	 *
	 * @param  string $path File path.
	 * @return array{mime: string, width: int, height: int}|null Null when the file isn't an image PHP can read.
	 */
	protected static function read_image_info( string $path ): ?array {
		$info = wp_getimagesize( $path );
		if ( ! is_array( $info ) || $info[0] < 1 || $info[1] < 1 ) {
			return null;
		}
		$mime = '' !== $info['mime'] ? $info['mime'] : wp_get_image_mime( $path );
		if ( false === $mime || '' === $mime ) {
			return null;
		}
		return array(
			'mime'   => $mime,
			'width'  => $info[0],
			'height' => $info[1],
		);
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
		$actual = wp_parse_url( $url );
		$wanted = wp_parse_url( $attachment_url );
		if ( ! is_array( $actual ) || ! is_array( $wanted ) || self::origin( $actual ) !== self::origin( $wanted ) ) {
			return false;
		}
		$path     = $actual['path'] ?? null;
		$expected = $wanted['path'] ?? null;
		if ( ! is_string( $path ) || ! is_string( $expected ) || dirname( $path ) !== dirname( $expected ) ) {
			return false;
		}

		// A size of the image adds "-{width}x{height}" or "-scaled" to its
		// name. An image edited after it was inserted gets a new
		// "-e{timestamp}" name, so a block still showing the old file
		// doesn't match and is skipped.
		$stem = preg_replace( '/-scaled$/', '', pathinfo( $expected, PATHINFO_FILENAME ) );
		$name = pathinfo( $path, PATHINFO_FILENAME );
		return is_string( $stem ) && '' !== $stem && 1 === preg_match( '/^' . preg_quote( $stem, '/' ) . '(?:-scaled)?(?:-\d+x\d+)?$/', $name );
	}

	/**
	 * The origin of a parsed URL: scheme, host and effective port.
	 *
	 * @param  array<string, int|string> $parts URL parts from wp_parse_url().
	 * @return string Origin, or '' when the URL has no scheme or host.
	 */
	private static function origin( array $parts ): string {
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( '' === $scheme || '' === $host ) {
			return '';
		}
		$port = $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 );
		return $scheme . '://' . $host . ':' . $port;
	}

	/**
	 * Read a local image file.
	 *
	 * @param  string $path File path inside the uploads directory.
	 * @return string|null File contents, or null when unreadable.
	 */
	protected function read_file( string $path ): ?string {
		// Read one byte past the limit, so a file that grew since its size
		// was checked is never read whole.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file in uploads, never a URL.
		$data = file_get_contents( $path, false, null, 0, self::MAX_BYTES + 1 );
		return is_string( $data ) && '' !== $data && strlen( $data ) <= self::MAX_BYTES ? $data : null;
	}
}
