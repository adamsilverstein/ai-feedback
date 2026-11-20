<?php
/**
 * Tests for the images a review shows a vision model (Review_Images), and
 * for alt text proposals through Review_Service::review_document().
 *
 * Runs in separate processes because it defines attachment API fakes and
 * the WP_Post class, which other tests rely on being absent.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use AI_Feedback\Notes_Manager;
use AI_Feedback\Review_Images;
use AI_Feedback\Review_Service;
use ReflectionProperty;
use WP_Error;

/**
 * Records the images each AI call gets and answers with queued responses.
 */
class ImageReviewService extends Review_Service {

	/**
	 * Images sent with each call, in order.
	 *
	 * @var list<array<mixed>>
	 */
	public array $sent = array();

	/**
	 * Responses to return, in order.
	 *
	 * @var list<string|WP_Error>
	 */
	public array $responses = array();

	/**
	 * Record the images and return the next response.
	 *
	 * @param  string $prompt             User prompt.
	 * @param  string $system_instruction System instruction.
	 * @param  string $model              Model to use.
	 * @param  array<mixed> $images       Images to attach.
	 * @return string|WP_Error Response.
	 */
	protected function call_ai( string $prompt, string $system_instruction, string $model, array $images = array() ): string|WP_Error {
		$this->sent[] = $images;
		return array_shift( $this->responses ) ?? new WP_Error( 'invalid_request', 'No response queued.' );
	}
}

/**
 * Notes manager that keeps the parsed items instead of creating notes.
 */
class CapturingNotesManager extends Notes_Manager {

	/**
	 * Items passed to create_notes_from_feedback().
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $items = array();

	/**
	 * Keep the items.
	 *
	 * @param  array<int, array<string, mixed>> $feedback_items Feedback items.
	 * @param  int                              $post_id        Post ID.
	 * @param  array<string, mixed>             $review_data    Review data.
	 * @return array<string, mixed>|WP_Error Always an error, so no notes are read back.
	 */
	public function create_notes_from_feedback( array $feedback_items, int $post_id, array $review_data = array() ): array|WP_Error {
		$this->items = $feedback_items;
		return new WP_Error( 'skipped', 'Notes skipped.' );
	}
}

/**
 * Test cases for Review_Images and alt text in reviews.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class ReviewImagesTest extends TestCase {

	/**
	 * Uploads directory for the test.
	 *
	 * @var string
	 */
	private string $uploads = '';

	/**
	 * Create an uploads directory with a few images.
	 */
	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/fixtures/fake-attachments.php';

		$this->uploads = sys_get_temp_dir() . '/ai-feedback-uploads-' . uniqid();
		mkdir( $this->uploads . '/2026/11', 0777, true );
		file_put_contents( $this->uploads . '/2026/11/chart.png', 'png-original' );
		file_put_contents( $this->uploads . '/2026/11/chart-768x400.png', 'png-medium-large' );
		file_put_contents( $this->uploads . '/2026/11/huge.jpg', str_repeat( 'x', Review_Images::MAX_BYTES + 1 ) );
		file_put_contents( $this->uploads . '/2026/11/logo.svg', '<svg/>' );
		file_put_contents( sys_get_temp_dir() . '/ai-feedback-outside.png', 'secret' );

		$GLOBALS['test_uploads_dir'] = $this->uploads;
		$GLOBALS['test_attachments'] = array(
			12 => array(
				'file'  => '2026/11/chart.png',
				'mime'  => 'image/png',
				'sizes' => array( 'medium_large' => '2026/11/chart-768x400.png' ),
			),
			13 => array(
				'file' => '2026/11/huge.jpg',
				'mime' => 'image/jpeg',
			),
			14 => array(
				'file' => '2026/11/logo.svg',
				'mime' => 'image/svg+xml',
			),
			15 => array(
				'file' => '../../ai-feedback-outside.png',
				'mime' => 'image/png',
			),
			16 => array(
				'file' => '2026/11/report.pdf',
				'mime' => 'application/pdf',
			),
		);
		foreach ( array_keys( $GLOBALS['test_attachments'] ) as $id ) {
			$GLOBALS['test_posts'][ $id ] = new \WP_Post(
				(object) array(
					'ID'        => $id,
					'post_type' => 'attachment',
				)
			);
		}
		$GLOBALS['test_posts'][1] = new \WP_Post(
			(object) array(
				'ID'         => 1,
				'post_type'  => 'post',
				'post_title' => 'Post',
			)
		);
	}

	/**
	 * An image block.
	 *
	 * @param  string $client_id Client ID.
	 * @param  int    $id        Attachment ID.
	 * @param  string $url       Image URL.
	 * @param  string $alt       Alt text.
	 * @return array<string, mixed>
	 */
	private static function image( string $client_id, int $id, string $url, string $alt = '' ): array {
		$attributes = array(
			'alt' => $alt,
			'url' => $url,
		);
		if ( $id > 0 ) {
			$attributes['id'] = $id;
		}
		return array(
			'clientId'   => $client_id,
			'name'       => 'core/image',
			'content'    => '',
			'attributes' => $attributes,
		);
	}

	/**
	 * An image without alt text is sent from its smaller size, as base64.
	 */
	public function test_sends_local_image_without_alt(): void {
		$images = ( new Review_Images() )->collect(
			array( self::image( 'img', 12, 'http://example.com/wp-content/uploads/2026/11/chart-1024x533.png' ) )
		);

		$this->assertSame(
			array(
				array(
					'block_id' => 'img',
					'mime'     => 'image/png',
					'data'     => base64_encode( 'png-medium-large' ),
				),
			),
			$images
		);
	}

	/**
	 * Images the AI must not be sent are skipped.
	 */
	public function test_skips_images_it_must_not_send(): void {
		$GLOBALS['test_current_user_can'] = static fn ( string $cap, mixed ...$args ): bool => ! ( 'read_post' === $cap && 12 === ( $args[0] ?? null ) );

		$images = ( new Review_Images() )->collect(
			array(
				// Already has alt text.
				self::image( 'has-alt', 12, 'http://example.com/wp-content/uploads/2026/11/chart.png', 'A chart' ),
				// External image: no attachment ID, so nothing is fetched.
				self::image( 'external', 0, 'http://169.254.169.254/latest/meta-data/image.png' ),
				// An attachment the user can't read.
				self::image( 'private', 12, 'http://example.com/wp-content/uploads/2026/11/chart.png' ),
				// Over the size limit, with no smaller size.
				self::image( 'huge', 13, 'http://example.com/wp-content/uploads/2026/11/huge.jpg' ),
				// Not an image type a model takes.
				self::image( 'svg', 14, 'http://example.com/wp-content/uploads/2026/11/logo.svg' ),
				// A file outside the uploads directory.
				self::image( 'outside', 15, 'http://example.com/wp-content/ai-feedback-outside.png' ),
				// Not an image attachment.
				self::image( 'pdf', 16, 'http://example.com/wp-content/uploads/2026/11/report.pdf' ),
				// An attachment that doesn't exist.
				self::image( 'missing', 99, 'http://example.com/wp-content/uploads/2026/11/gone.png' ),
			)
		);

		$this->assertSame( array(), $images );
	}

	/**
	 * An ID left over from another image, or a URL in another directory or
	 * on another origin, doesn't send the attachment.
	 */
	public function test_url_must_be_the_attachment(): void {
		$collector = new Review_Images();

		$this->assertSame( array(), $collector->collect( array( self::image( 'img', 12, 'http://example.com/wp-content/uploads/2026/11/photo.png' ) ) ) );
		$this->assertSame( array(), $collector->collect( array( self::image( 'img', 12, 'http://example.com/elsewhere/chart.png' ) ) ) );
		$this->assertCount( 1, $collector->collect( array( self::image( 'img', 12, 'http://example.com/wp-content/uploads/2026/11/chart-scaled.png' ) ) ) );

		// The same path on another host, or another scheme or port, is a different image.
		$this->assertSame( array(), $collector->collect( array( self::image( 'img', 12, 'http://evil.example/wp-content/uploads/2026/11/chart.png' ) ) ) );
		$this->assertSame( array(), $collector->collect( array( self::image( 'img', 12, 'https://example.com/wp-content/uploads/2026/11/chart.png' ) ) ) );
		$this->assertSame( array(), $collector->collect( array( self::image( 'img', 12, 'http://example.com:8080/wp-content/uploads/2026/11/chart.png' ) ) ) );
		$this->assertSame( array(), $collector->collect( array( self::image( 'img', 12, '/wp-content/uploads/2026/11/chart.png' ) ) ) );
		$this->assertCount( 1, $collector->collect( array( self::image( 'img', 12, 'http://EXAMPLE.com:80/wp-content/uploads/2026/11/chart.png' ) ) ) );
	}

	/**
	 * At most MAX_IMAGES are sent, and the filter can turn images off.
	 */
	public function test_image_limit(): void {
		$blocks = array_map(
			static fn ( int $n ): array => self::image( 'img-' . $n, 12, 'http://example.com/wp-content/uploads/2026/11/chart.png' ),
			range( 1, Review_Images::MAX_IMAGES + 2 )
		);

		$this->assertCount( Review_Images::MAX_IMAGES, ( new Review_Images() )->collect( $blocks ) );

		$GLOBALS['test_filter_results'] = array( 'ai_feedback_max_review_images' => 0 );
		$this->assertSame( array(), ( new Review_Images() )->collect( $blocks ) );
	}

	/**
	 * Images stop once their total would pass MAX_TOTAL_BYTES.
	 */
	public function test_total_size_limit(): void {
		$size = (int) ( Review_Images::MAX_TOTAL_BYTES / 2 ) - 10;
		file_put_contents( $this->uploads . '/2026/11/big.png', str_repeat( 'x', $size ) );
		$GLOBALS['test_attachments'][20] = array(
			'file' => '2026/11/big.png',
			'mime' => 'image/png',
		);
		$GLOBALS['test_posts'][20]       = new \WP_Post(
			(object) array(
				'ID'        => 20,
				'post_type' => 'attachment',
			)
		);
		$big = static fn ( string $id ): array => self::image( $id, 20, 'http://example.com/wp-content/uploads/2026/11/big.png' );

		$images = ( new Review_Images() )->collect( array( $big( 'a' ), $big( 'b' ), $big( 'c' ) ) );

		$this->assertSame( array( 'a', 'b' ), array_column( $images, 'block_id' ) );
	}

	/**
	 * Run a review that proposes alt text for the image.
	 *
	 * @param  string               $model     Model ID.
	 * @param  list<string|WP_Error> $responses AI responses, in order.
	 * @return array{0: ImageReviewService, 1: CapturingNotesManager}
	 */
	private function review( string $model, array $responses ): array {
		define( 'AI_FEEDBACK_MOCK_MODE', false );
		$service            = new ImageReviewService();
		$service->responses = $responses;
		$notes              = new CapturingNotesManager();
		( new ReflectionProperty( Review_Service::class, 'notes_manager' ) )->setValue( $service, $notes );

		$service->review_document(
			1,
			array(
				'model'  => $model,
				'blocks' => array(
					self::image( 'img', 12, 'http://example.com/wp-content/uploads/2026/11/chart.png' ),
				),
			)
		);

		return array( $service, $notes );
	}

	/**
	 * The AI's alt text proposal.
	 *
	 * @return string Review response JSON.
	 */
	private static function alt_response(): string {
		return (string) wp_json_encode(
			array(
				'summary'  => 'One image.',
				'feedback' => array(
					array(
						'block_id'     => 'img',
						'category'     => 'design',
						'severity'     => 'important',
						'title'        => 'Add alt text',
						'feedback'     => 'This image has no alt text.',
						'block_change' => array(
							'type'       => 'set_attributes',
							'attributes' => array( 'alt' => 'Bar chart of test times before and after' ),
						),
					),
				),
			)
		);
	}

	/**
	 * A vision model is shown the image, and its alt text is kept.
	 */
	public function test_vision_model_proposes_alt_text(): void {
		list( $service, $notes ) = $this->review( 'gpt-4o', array( self::alt_response() ) );

		$this->assertCount( 1, $service->sent );
		$this->assertSame( array( 'img' ), array_column( $service->sent[0], 'block_id' ) );
		$this->assertSame(
			array( 'alt' => 'Bar chart of test times before and after' ),
			$notes->items[0]['block_change']['attributes'] ?? null
		);
	}

	/**
	 * The default model is shown the image too, so a site with the default
	 * setup gets alt text proposals.
	 */
	public function test_default_model_proposes_alt_text(): void {
		list( $service, $notes ) = $this->review( AI_FEEDBACK_DEFAULT_MODEL, array( self::alt_response() ) );

		$this->assertSame( array( 'img' ), array_column( $service->sent[0], 'block_id' ) );
		$this->assertSame(
			array( 'alt' => 'Bar chart of test times before and after' ),
			$notes->items[0]['block_change']['attributes'] ?? null
		);
	}

	/**
	 * A text-only model gets no image, and alt text it makes up is dropped,
	 * leaving a plain note.
	 */
	public function test_text_model_only_flags_missing_alt_text(): void {
		$GLOBALS['test_filter_results'] = array(
			'ai_feedback_available_models' => array(
				array(
					'id'           => 'house-model',
					'provider'     => 'example',
					'capabilities' => array( 'text' ),
				),
			),
		);
		list( $service, $notes ) = $this->review( 'house-model', array( self::alt_response() ) );

		$this->assertSame( array( array() ), $service->sent );
		$this->assertSame( 'Add alt text', $notes->items[0]['title'] );
		$this->assertArrayNotHasKey( 'block_change', $notes->items[0] );
	}

	/**
	 * A site can turn images off for a model it knows can't read them.
	 */
	public function test_filter_turns_images_off(): void {
		$GLOBALS['test_filter_results'] = array( 'ai_feedback_model_supports_vision' => false );
		list( $service, $notes ) = $this->review( 'gpt-4o', array( self::alt_response() ) );

		$this->assertSame( array( array() ), $service->sent );
		$this->assertArrayNotHasKey( 'block_change', $notes->items[0] );
	}

	/**
	 * When the request with images is refused, the review runs again on the text
	 * alone, and alt text in that answer is dropped.
	 */
	public function test_falls_back_to_text_when_images_fail(): void {
		list( $service, $notes ) = $this->review(
			'gpt-4o',
			array( new WP_Error( 'bad_request', 'Images are not supported.', array( 'status' => 400 ) ), self::alt_response() )
		);

		$this->assertCount( 2, $service->sent );
		$this->assertNotEmpty( $service->sent[0] );
		$this->assertSame( array(), $service->sent[1] );
		$this->assertArrayNotHasKey( 'block_change', $notes->items[0] );
	}

	/**
	 * When core finds no configured model that takes the images, its 400
	 * error fails fast and the default model's review still succeeds on the
	 * text alone.
	 */
	public function test_default_model_falls_back_when_core_refuses_images(): void {
		list( $service, $notes ) = $this->review(
			AI_FEEDBACK_DEFAULT_MODEL,
			array(
				new WP_Error( 'prompt_invalid_argument', 'No models found that support text_generation for this prompt.', array( 'status' => 400 ) ),
				self::alt_response(),
			)
		);

		$this->assertCount( 2, $service->sent );
		$this->assertNotEmpty( $service->sent[0] );
		$this->assertSame( array(), $service->sent[1] );
		$this->assertSame( 'Add alt text', $notes->items[0]['title'] );
		$this->assertArrayNotHasKey( 'block_change', $notes->items[0] );
	}

	/**
	 * An auth, billing or rate failure would hit a text-only request too, so
	 * the review isn't sent again.
	 */
	public function test_no_text_retry_for_other_failures(): void {
		list( $service ) = $this->review(
			'gpt-4o',
			array( new WP_Error( 'unauthorized', 'Invalid API key.', array( 'status' => 401 ) ), self::alt_response() )
		);

		$this->assertCount( 1, $service->sent );
	}

	/**
	 * Errors that only mention a file or something unsupported, or carry a
	 * status a text request would hit too, don't send the review again.
	 */
	public function test_no_text_retry_for_unrelated_messages(): void {
		foreach (
			array(
				new WP_Error( 'not_found', 'File not found.' ),
				new WP_Error( 'region', 'Unsupported region.' ),
				new WP_Error( 'rate_limited', 'Too many image requests.', array( 'status' => 429 ) ),
			) as $error
		) {
			$this->assertFalse( ( new \ReflectionMethod( Review_Service::class, 'is_refused_input' ) )->invoke( null, $error ) );
		}

		$this->assertTrue(
			( new \ReflectionMethod( Review_Service::class, 'is_refused_input' ) )->invoke(
				null,
				new WP_Error( 'invalid_request', 'This model does not support image input.' )
			)
		);
	}
}

