<?php
/**
 * Tests for the site setting that keeps a post's images on the site
 * (Review_Images::OPTION), and for the filter that wins over it.
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
 * Records what each AI call gets and answers with an alt text proposal.
 */
class ImageSettingReviewService extends Review_Service {

	/**
	 * Images sent with each call, in order.
	 *
	 * @var list<array<mixed>>
	 */
	public array $sent = array();

	/**
	 * Prompt of each call, in order.
	 *
	 * @var list<string>
	 */
	public array $prompts = array();

	/**
	 * Models asked whether they read images, in order.
	 *
	 * @var list<string>
	 */
	public array $vision_checks = array();

	/**
	 * Models whose provider was looked up, in order.
	 *
	 * @var list<string>
	 */
	public array $provider_lookups = array();

	/**
	 * Record the model, then look up its provider as usual.
	 *
	 * @param  string $model Model ID.
	 * @return string
	 */
	protected function get_model_provider( string $model ): string {
		$this->provider_lookups[] = $model;
		return parent::get_model_provider( $model );
	}

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
	 * Record the call and propose alt text for the image.
	 *
	 * @param  string       $prompt             User prompt.
	 * @param  string       $system_instruction System instruction.
	 * @param  string       $model              Model to use.
	 * @param  array<mixed> $images             Images to attach.
	 * @return string|WP_Error Response.
	 */
	protected function call_ai( string $prompt, string $system_instruction, string $model, array $images = array() ): string|WP_Error {
		$this->sent[]    = $images;
		$this->prompts[] = $prompt;
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
}

/**
 * Notes manager that keeps the parsed items instead of creating notes.
 */
class ImageSettingNotesManager extends Notes_Manager {

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
 * Test cases for the image setting, on and off, and the filter over it.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class ReviewImagesSettingTest extends TestCase {

	/**
	 * Uploads directory for the test.
	 *
	 * @var string
	 */
	private string $uploads = '';

	/**
	 * Create an uploads directory with one image.
	 */
	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/fixtures/fake-attachments.php';

		$this->uploads = sys_get_temp_dir() . '/ai-feedback-uploads-' . uniqid();
		mkdir( $this->uploads . '/2026/11', 0777, true );
		// A real PNG header, so the file reads as an image by its contents too.
		file_put_contents(
			$this->uploads . '/2026/11/chart.png',
			"\x89PNG\r\n\x1a\n" . pack( 'N', 13 ) . 'IHDR' . pack( 'NN', 800, 400 ) . "\x08\x02\x00\x00\x00" . pack( 'N', 0 )
		);

		$GLOBALS['test_options']     = array();
		$GLOBALS['test_uploads_dir'] = $this->uploads;
		$GLOBALS['test_attachments'] = array(
			12 => array(
				'file' => '2026/11/chart.png',
				'mime' => 'image/png',
			),
		);
		$GLOBALS['test_posts']       = array(
			12 => new \WP_Post(
				(object) array(
					'ID'        => 12,
					'post_type' => 'attachment',
				)
			),
			1  => new \WP_Post(
				(object) array(
					'ID'         => 1,
					'post_type'  => 'post',
					'post_title' => 'Post',
				)
			),
		);
	}

	/**
	 * Remove the uploads directory.
	 */
	protected function tearDown(): void {
		unlink( $this->uploads . '/2026/11/chart.png' );
		rmdir( $this->uploads . '/2026/11' );
		rmdir( $this->uploads . '/2026' );
		rmdir( $this->uploads );
		unset( $GLOBALS['test_options'], $GLOBALS['test_filter_results'] );
		parent::tearDown();
	}

	/**
	 * Image blocks without alt text, all showing the same Media Library image.
	 *
	 * @param  string ...$client_ids Client IDs.
	 * @return list<array<string, mixed>>
	 */
	private static function images( string ...$client_ids ): array {
		return array_values(
			array_map(
				static fn ( string $client_id ): array => array(
					'clientId'   => $client_id,
					'name'       => 'core/image',
					'content'    => '',
					'attributes' => array(
						'alt' => '',
						'url' => 'http://example.com/wp-content/uploads/2026/11/chart.png',
						'id'  => 12,
					),
				),
				$client_ids
			)
		);
	}

	/**
	 * Run a review of one image without alt text.
	 *
	 * @return array{0: ImageSettingReviewService, 1: ImageSettingNotesManager, 2: array<mixed>}
	 */
	private function review(): array {
		define( 'AI_FEEDBACK_MOCK_MODE', false );
		$service = new ImageSettingReviewService();
		$notes   = new ImageSettingNotesManager();
		( new ReflectionProperty( Review_Service::class, 'notes_manager' ) )->setValue( $service, $notes );

		$result = $service->review_document(
			1,
			array(
				'model'  => 'gpt-4o',
				'blocks' => self::images( 'img' ),
			)
		);
		$this->assertIsArray( $result );

		return array( $service, $notes, $result );
	}

	/**
	 * A site that never saved the setting sends images.
	 */
	public function test_images_are_sent_by_default(): void {
		$this->assertTrue( Review_Images::is_enabled() );
		$this->assertSame( array( 'img' ), array_column( ( new Review_Images() )->collect( self::images( 'img' ) ), 'block_id' ) );
	}

	/**
	 * With the setting on, images are sent.
	 */
	public function test_setting_on_sends_images(): void {
		$GLOBALS['test_options'] = array( Review_Images::OPTION => '1' );

		$this->assertTrue( Review_Images::is_enabled() );
		$this->assertSame( array( 'img' ), array_column( ( new Review_Images() )->collect( self::images( 'img' ) ), 'block_id' ) );
	}

	/**
	 * With the setting off, no image is collected.
	 */
	public function test_setting_off_collects_no_images(): void {
		$GLOBALS['test_options'] = array( Review_Images::OPTION => '0' );

		$this->assertFalse( Review_Images::is_enabled() );
		$this->assertSame( array(), ( new Review_Images() )->collect( self::images( 'img' ) ) );
	}

	/**
	 * The filter turns images on for a site whose setting is off.
	 */
	public function test_filter_wins_over_setting_off(): void {
		$GLOBALS['test_options']        = array( Review_Images::OPTION => '0' );
		$GLOBALS['test_filter_results'] = array( 'ai_feedback_max_review_images' => 2 );

		$images = ( new Review_Images() )->collect( self::images( 'a', 'b', 'c' ) );

		$this->assertSame( array( 'a', 'b' ), array_column( $images, 'block_id' ) );
	}

	/**
	 * The filter turns images off for a site whose setting is on.
	 */
	public function test_filter_wins_over_setting_on(): void {
		$GLOBALS['test_options']        = array( Review_Images::OPTION => '1' );
		$GLOBALS['test_filter_results'] = array( 'ai_feedback_max_review_images' => 0 );

		$this->assertSame( array(), ( new Review_Images() )->collect( self::images( 'img' ) ) );
	}

	/**
	 * With the setting on, a review shows the model the image and keeps its
	 * alt text.
	 */
	public function test_review_sends_image_with_setting_on(): void {
		list( $service, $notes ) = $this->review();

		$this->assertSame( array( 'img' ), array_column( $service->sent[0], 'block_id' ) );
		$this->assertStringContainsString( 'image attached', $service->prompts[0] );
		$change = $notes->items[0]['block_change'] ?? null;
		$this->assertIsArray( $change );
		$this->assertSame( array( 'alt' => 'Bar chart of test times before and after' ), $change['attributes'] ?? null );
	}

	/**
	 * With the setting off, a review sends no image, never looks up the
	 * model's provider or asks whether it reads images, drops alt text the model made up, and says
	 * nothing about images left out.
	 */
	public function test_review_sends_no_image_with_setting_off(): void {
		$GLOBALS['test_options'] = array( Review_Images::OPTION => '0' );

		list( $service, $notes, $result ) = $this->review();

		$this->assertSame( array( array() ), $service->sent );
		$this->assertSame( array(), $service->vision_checks );
		$this->assertSame( array(), $service->provider_lookups );
		$this->assertStringNotContainsString( 'image attached', $service->prompts[0] );
		$this->assertSame( 'Add alt text', $notes->items[0]['title'] );
		$this->assertArrayNotHasKey( 'block_change', $notes->items[0] );
		$this->assertArrayNotHasKey( 'images_skipped', $result );
	}
}
