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
use AI_Feedback\Image_Refusals;
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
	 * Models asked whether they read images, in order.
	 *
	 * @var list<string>
	 */
	public array $vision_checks = array();

	/**
	 * Record the model, then ask as usual.
	 *
	 * @param  string $model Model ID.
	 * @return bool
	 */
	protected function model_supports_vision( string $model ): bool {
		$this->vision_checks[] = $model;
		return parent::model_supports_vision( $model );
	}

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
		file_put_contents( $this->uploads . '/2026/11/chart.png', ai_feedback_test_png( 1600, 833, 'original' ) );
		file_put_contents( $this->uploads . '/2026/11/chart-768x400.png', ai_feedback_test_png( 768, 400, 'medium-large' ) );
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
			// A GIF.
			30 => array(
				'file' => '2026/11/dance.gif',
				'mime' => 'image/gif',
			),
			// A long infographic: medium_large keeps its height.
			31 => array(
				'file'  => '2026/11/tall.png',
				'mime'  => 'image/png',
				'sizes' => array(
					'medium_large' => '2026/11/tall-768x9000.png',
					'large'        => '2026/11/tall-87x1024.png',
				),
			),
			// Too tall in every size.
			32 => array(
				'file'  => '2026/11/scroll.png',
				'mime'  => 'image/png',
				'sizes' => array( 'medium_large' => '2026/11/scroll-768x9000.png' ),
			),
			// A PNG named .jpg.
			33 => array(
				'file' => '2026/11/photo.jpg',
				'mime' => 'image/jpeg',
			),
			// A GIF named .png.
			34 => array(
				'file' => '2026/11/moving.png',
				'mime' => 'image/png',
			),
			// Not an image at all.
			35 => array(
				'file' => '2026/11/notes.png',
				'mime' => 'image/png',
			),
		);
		file_put_contents( $this->uploads . '/2026/11/dance.gif', ai_feedback_test_gif( 400, 300 ) );
		file_put_contents( $this->uploads . '/2026/11/tall.png', ai_feedback_test_png( 1536, 18000 ) );
		file_put_contents( $this->uploads . '/2026/11/tall-768x9000.png', ai_feedback_test_png( 768, 9000 ) );
		file_put_contents( $this->uploads . '/2026/11/tall-87x1024.png', ai_feedback_test_png( 87, 1024 ) );
		file_put_contents( $this->uploads . '/2026/11/scroll.png', ai_feedback_test_png( 1536, 18000 ) );
		file_put_contents( $this->uploads . '/2026/11/scroll-768x9000.png', ai_feedback_test_png( 768, 9000 ) );
		file_put_contents( $this->uploads . '/2026/11/photo.jpg', ai_feedback_test_png( 640, 480 ) );
		file_put_contents( $this->uploads . '/2026/11/moving.png', ai_feedback_test_gif( 320, 240 ) );
		file_put_contents( $this->uploads . '/2026/11/notes.png', 'Just some text.' );
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
					'data'     => base64_encode( ai_feedback_test_png( 768, 400, 'medium-large' ) ),
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
		file_put_contents( $this->uploads . '/2026/11/big.png', ai_feedback_test_png( 800, 600, str_repeat( 'x', $size - 33 ) ) );
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
	 * @param  array<mixed>|null    $blocks    Blocks to review, by default one image without alt text.
	 * @return array{0: ImageReviewService, 1: CapturingNotesManager, 2: array<string, mixed>|WP_Error} Service, notes and the review result.
	 */
	private function review( string $model, array $responses, ?array $blocks = null ): array {
		if ( ! defined( 'AI_FEEDBACK_MOCK_MODE' ) ) {
			define( 'AI_FEEDBACK_MOCK_MODE', false );
		}
		$service            = new ImageReviewService();
		$service->responses = $responses;
		$notes              = new CapturingNotesManager();
		( new ReflectionProperty( Review_Service::class, 'notes_manager' ) )->setValue( $service, $notes );

		$result = $service->review_document(
			1,
			array(
				'model'  => $model,
				'blocks' => $blocks ?? array(
					self::image( 'img', 12, 'http://example.com/wp-content/uploads/2026/11/chart.png' ),
				),
			)
		);

		return array( $service, $notes, $result );
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
	 * The model is only asked whether it reads images once there is an image
	 * to send, as asking can mean a request to the provider.
	 */
	public function test_no_vision_lookup_without_images(): void {
		list( $service ) = $this->review(
			AI_FEEDBACK_DEFAULT_MODEL,
			array( self::alt_response() ),
			array(
				// Has alt text, and an external image: neither is sent.
				self::image( 'img', 12, 'http://example.com/wp-content/uploads/2026/11/chart.png', 'A chart' ),
				self::image( 'ext', 12, 'http://evil.example/chart.png' ),
			)
		);

		$this->assertSame( array(), $service->vision_checks );
		$this->assertSame( array( array() ), $service->sent );
	}

	/**
	 * With an image to send, the model is asked once.
	 */
	public function test_vision_lookup_with_images(): void {
		list( $service ) = $this->review( AI_FEEDBACK_DEFAULT_MODEL, array( self::alt_response() ) );

		$this->assertSame( array( AI_FEEDBACK_DEFAULT_MODEL ), $service->vision_checks );
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

		// The plugin's own errors never drop the images.
		$this->assertFalse(
			( new \ReflectionMethod( Review_Service::class, 'is_refused_input' ) )->invoke(
				null,
				new WP_Error( 'ai_feedback_model_unavailable', 'The selected model does not take images.', array( 'status' => 400 ) )
			)
		);

		$this->assertTrue(
			( new \ReflectionMethod( Review_Service::class, 'is_refused_input' ) )->invoke(
				null,
				new WP_Error( 'invalid_request', 'This model does not support image input.' )
			)
		);
	}

	/**
	 * An image block for one of the attachments set up for the tests.
	 *
	 * @param  string $client_id Client ID.
	 * @param  int    $id        Attachment ID.
	 * @return array<string, mixed>
	 */
	private static function attachment( string $client_id, int $id ): array {
		return self::image( $client_id, $id, 'http://example.com/wp-content/uploads/' . $GLOBALS['test_attachments'][ $id ]['file'] );
	}

	/**
	 * A GIF is not sent to a provider that doesn't take it, by default to
	 * any, and only that image is left out.
	 */
	public function test_gif_left_out_alone(): void {
		$collector = new Review_Images();
		$images    = $collector->collect( array( self::attachment( 'gif', 30 ), self::attachment( 'png', 12 ) ), 'google' );

		$this->assertSame( array( 'png' ), array_column( $images, 'block_id' ) );
		$this->assertSame(
			array(
				array(
					'block_id' => 'gif',
					'reason'   => Review_Images::SKIP_TYPE,
				),
			),
			$collector->get_skipped()
		);
	}

	/**
	 * A site can send GIFs to a provider it knows takes them, but never a
	 * type outside the four the plugin sends.
	 */
	public function test_types_filter(): void {
		$GLOBALS['test_filter_results'] = array( 'ai_feedback_review_image_types' => array( 'image/gif', 'image/svg+xml' ) );

		$this->assertSame( array( 'image/gif' ), Review_Images::get_provider_types( 'anthropic' ) );
		$images = ( new Review_Images() )->collect( array( self::attachment( 'gif', 30 ) ), 'anthropic' );
		$this->assertSame( array( 'image/gif' ), array_column( $images, 'mime' ) );
	}

	/**
	 * A filter returning something other than a list of types sends no
	 * images rather than failing.
	 */
	public function test_types_filter_garbage(): void {
		foreach ( array( 'image/png', null, array( 42, array( 'image/png' ) ) ) as $garbage ) {
			$GLOBALS['test_filter_results'] = array( 'ai_feedback_review_image_types' => $garbage );
			$this->assertSame( array(), Review_Images::get_provider_types( 'openai' ) );
		}
	}

	/**
	 * The provider is only looked up when a block may be an image to send.
	 */
	public function test_has_candidates(): void {
		$this->assertFalse( Review_Images::has_candidates( array() ) );
		$this->assertFalse(
			Review_Images::has_candidates(
				array(
					array(
						'name'    => 'core/paragraph',
						'content' => 'Text',
					),
					self::image( 'alt', 12, 'http://example.com/wp-content/uploads/2026/11/chart.png', 'A chart' ),
					self::image( 'external', 0, 'http://example.com/chart.png' ),
					self::image( 'no-url', 12, '' ),
				)
			)
		);
		$this->assertTrue( Review_Images::has_candidates( array( self::attachment( 'img', 12 ) ) ) );
	}

	/**
	 * The longest side is capped as well as the width: a long image is sent
	 * from a size that fits, or left out when none does.
	 */
	public function test_tall_image_sent_from_a_size_that_fits(): void {
		$collector = new Review_Images();
		$images    = $collector->collect( array( self::attachment( 'tall', 31 ), self::attachment( 'scroll', 32 ) ), 'anthropic' );

		$this->assertSame( array( 'tall' ), array_column( $images, 'block_id' ) );
		$this->assertSame( base64_encode( ai_feedback_test_png( 87, 1024 ) ), $images[0]['data'] );
		$this->assertSame(
			array(
				array(
					'block_id' => 'scroll',
					'reason'   => Review_Images::SKIP_DIMENSIONS,
				),
			),
			$collector->get_skipped()
		);

		// The limit can be raised per provider.
		$GLOBALS['test_filter_results'] = array( 'ai_feedback_review_image_max_side' => 10000 );
		$this->assertCount( 2, ( new Review_Images() )->collect( array( self::attachment( 'tall', 31 ), self::attachment( 'scroll', 32 ) ), 'anthropic' ) );
	}

	/**
	 * The type comes from the file, not its name: a PNG named .jpg is sent
	 * as a PNG, and a GIF named .png or a text file named .png is left out.
	 */
	public function test_real_type_not_extension(): void {
		$collector = new Review_Images();
		$images    = $collector->collect(
			array( self::attachment( 'photo', 33 ), self::attachment( 'moving', 34 ), self::attachment( 'notes', 35 ) ),
			'openai'
		);

		$this->assertSame( array( 'photo' ), array_column( $images, 'block_id' ) );
		$this->assertSame( 'image/png', $images[0]['mime'] );
		$this->assertSame( array( 'moving', 'notes' ), array_column( $collector->get_skipped(), 'block_id' ) );
		$this->assertSame( array( Review_Images::SKIP_TYPE, Review_Images::SKIP_TYPE ), array_column( $collector->get_skipped(), 'reason' ) );
	}

	/**
	 * The AI's alt text proposals for two images.
	 *
	 * @return string Review response JSON.
	 */
	private static function two_alt_response(): string {
		$item = static fn ( string $id, string $alt ): array => array(
			'block_id'     => $id,
			'category'     => 'design',
			'severity'     => 'important',
			'title'        => 'Describe the ' . $id,
			'feedback'     => 'The ' . $id . ' image has no alt text.',
			'block_change' => array(
				'type'       => 'set_attributes',
				'attributes' => array( 'alt' => $alt ),
			),
		);
		return (string) wp_json_encode(
			array(
				'summary'  => 'Two images.',
				'feedback' => array( $item( 'gif', 'A dancing cat' ), $item( 'img', 'Bar chart of test times' ) ),
			)
		);
	}

	/**
	 * A GIF in a Gemini review costs only its own proposal: the other image
	 * is still sent, and the result says which was left out and why.
	 */
	public function test_one_unsupported_image_keeps_the_others(): void {
		list( $service, $notes, $result ) = $this->review(
			'gemini-2.0-flash',
			array( self::two_alt_response() ),
			array( self::attachment( 'gif', 30 ), self::attachment( 'img', 12 ) )
		);

		$this->assertCount( 1, $service->sent );
		$this->assertSame( array( 'img' ), array_column( $service->sent[0], 'block_id' ) );
		$this->assertArrayNotHasKey( 'block_change', $notes->items[0] );
		$this->assertSame( array( 'alt' => 'Bar chart of test times' ), $notes->items[1]['block_change']['attributes'] ?? null );
		$this->assertSame(
			array(
				array(
					'block_id' => 'gif',
					'reason'   => 'type',
				),
			),
			$result['images_skipped']
		);
	}

	/**
	 * A review with nothing left out has no `images_skipped`, and a text-only
	 * model's images aren't reported as left out.
	 */
	public function test_nothing_skipped_reports_nothing(): void {
		list( , , $result ) = $this->review( 'gpt-4o', array( self::alt_response() ) );
		$this->assertArrayNotHasKey( 'images_skipped', $result );

		$GLOBALS['test_filter_results'] = array( 'ai_feedback_model_supports_vision' => false );
		list( , , $result ) = $this->review( 'gpt-4o', array( self::alt_response() ), array( self::attachment( 'gif', 30 ) ) );
		$this->assertArrayNotHasKey( 'images_skipped', $result );
	}

	/**
	 * A model that refuses images is remembered: the next review sends none
	 * and says why, instead of paying for the failed call again.
	 */
	public function test_refusal_is_remembered(): void {
		list( $service, , $result ) = $this->review(
			'gpt-4o',
			array( new WP_Error( 'prompt_invalid_argument', 'No models found that support text_generation for this prompt.', array( 'status' => 400 ) ), self::alt_response() )
		);
		$this->assertCount( 2, $service->sent );
		$this->assertSame( array( 'refused' ), array_column( $result['images_skipped'], 'reason' ) );
		$this->assertTrue( Image_Refusals::is_refused( 'openai', 'gpt-4o' ) );

		list( $service, $notes, $result ) = $this->review( 'gpt-4o', array( self::alt_response() ) );
		$this->assertSame( array( array() ), $service->sent );
		$this->assertSame( array( 'refused_recently' ), array_column( $result['images_skipped'], 'reason' ) );
		$this->assertArrayNotHasKey( 'block_change', $notes->items[0] );

		// Another model, or the same model once the setting changes, still gets images.
		$this->assertFalse( Image_Refusals::is_refused( 'google', 'gemini-2.0-flash' ) );
		Image_Refusals::clear_on_option_change( 'connectors_ai_openai_api_key' );
		list( $service ) = $this->review( 'gpt-4o', array( self::alt_response() ) );
		$this->assertNotEmpty( $service->sent[0] );
	}

	/**
	 * One author's bad image costs only that review: a 400 about an image
	 * is not remembered, so the next review, by another user, sends its
	 * images again.
	 */
	public function test_bad_image_does_not_affect_other_users(): void {
		$GLOBALS['test_current_user_id'] = 1;
		list( $service, , $result ) = $this->review(
			'gpt-4o',
			array( new WP_Error( 'prompt_client_error', 'Could not process image: the image is invalid.', array( 'status' => 400 ) ), self::alt_response() )
		);
		$this->assertCount( 2, $service->sent );
		$this->assertSame( array( 'refused' ), array_column( $result['images_skipped'], 'reason' ) );
		$this->assertFalse( Image_Refusals::is_refused( 'openai', 'gpt-4o' ) );

		$GLOBALS['test_current_user_id'] = 2;
		list( $service, $notes, $result ) = $this->review( 'gpt-4o', array( self::alt_response() ) );
		$this->assertSame( array( 'img' ), array_column( $service->sent[0], 'block_id' ) );
		$this->assertArrayNotHasKey( 'images_skipped', $result );
		$this->assertSame( array( 'alt' => 'Bar chart of test times before and after' ), $notes->items[0]['block_change']['attributes'] ?? null );
	}

	/**
	 * A remembered refusal expires: once its time passes, images are sent
	 * again.
	 */
	public function test_remembered_refusal_expires(): void {
		$GLOBALS['test_transients'][ Image_Refusals::TRANSIENT ] = array( 'openai|gpt-4o' => time() - 1 );

		list( $service, , $result ) = $this->review( 'gpt-4o', array( self::alt_response() ) );

		$this->assertNotEmpty( $service->sent[0] );
		$this->assertArrayNotHasKey( 'images_skipped', $result );
	}

	/**
	 * A 413 or 415 is not retried: one failed call, then one text call.
	 * Neither is remembered, as either can be about one file, so the next
	 * review sends its images again.
	 */
	public function test_413_and_415_not_retried(): void {
		list( $service ) = $this->review(
			'gpt-4o',
			array( new WP_Error( 'prompt_client_error', 'Request entity too large.', array( 'status' => 413 ) ), self::alt_response() )
		);
		$this->assertCount( 2, $service->sent );
		$this->assertNotEmpty( $service->sent[0] );
		$this->assertSame( array(), $service->sent[1] );
		$this->assertFalse( Image_Refusals::is_refused( 'openai', 'gpt-4o' ) );

		list( $service ) = $this->review(
			'gpt-4o',
			array( new WP_Error( 'prompt_client_error', 'Unsupported Media Type', array( 'status' => 415 ) ), self::alt_response() )
		);
		$this->assertCount( 2, $service->sent );
		$this->assertFalse( Image_Refusals::is_refused( 'openai', 'gpt-4o' ) );

		list( $service ) = $this->review( 'gpt-4o', array( self::alt_response() ) );
		$this->assertSame( array( 'img' ), array_column( $service->sent[0], 'block_id' ) );
	}

	/**
	 * A refusal is only remembered when the text alone then works and the
	 * error says the images were refused: never for an unclear 400, nor
	 * when the text call fails too, nor for an auth failure.
	 */
	public function test_refusal_not_remembered_without_cause(): void {
		// An unclear 400: the text runs alone, but nothing is remembered.
		list( $service ) = $this->review(
			'gpt-4o',
			array( new WP_Error( 'prompt_client_error', 'Invalid request.', array( 'status' => 400 ) ), self::alt_response() )
		);
		$this->assertCount( 2, $service->sent );
		$this->assertFalse( Image_Refusals::is_refused( 'openai', 'gpt-4o' ) );

		// The text call fails too, so the images weren't the problem.
		$this->review(
			'gpt-4o',
			array(
				new WP_Error( 'prompt_client_error', 'Image input is not supported.', array( 'status' => 400 ) ),
				new WP_Error( 'prompt_client_error', 'Invalid request.', array( 'status' => 400 ) ),
			)
		);
		$this->assertFalse( Image_Refusals::is_refused( 'openai', 'gpt-4o' ) );

		// An auth failure, even one naming images, never drops them.
		list( $service ) = $this->review(
			'gpt-4o',
			array( new WP_Error( 'prompt_client_error', 'Your key cannot send images.', array( 'status' => 401 ) ), self::alt_response() )
		);
		$this->assertCount( 1, $service->sent );
		$this->assertFalse( Image_Refusals::is_refused( 'openai', 'gpt-4o' ) );
	}

	/**
	 * The hourly limit is the controller's to charge, once per review: the
	 * text-only run after a refusal doesn't charge it again.
	 */
	public function test_text_retry_does_not_charge_rate_limit(): void {
		$before                    = array( 1 => array( 'ai_feedback_reviews' => array( time() ) ) );
		$GLOBALS['test_user_meta'] = $before;

		$this->review(
			'gpt-4o',
			array( new WP_Error( 'prompt_client_error', 'Image input is not supported.', array( 'status' => 400 ) ), self::alt_response() )
		);

		$this->assertSame( $before, $GLOBALS['test_user_meta'] );
	}
}
