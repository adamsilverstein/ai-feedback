<?php
/**
 * Tests for Review_Service caching functionality.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Review_Service;
use WP_Error;

/**
 * Testable subclass of Review_Service that exposes cache methods.
 */
class TestableCacheReviewService extends Review_Service
{
	/**
	 * Constructor that skips parent dependencies.
	 */
	public function __construct()
	{
		// Skip parent constructor to avoid loading dependencies.
	}

	/**
	 * Expose get_cached_review for testing using reflection.
	 *
	 * @param  string $cache_key Cache key.
	 * @return array|null Cached review data or null.
	 */
	public function test_get_cached_review( string $cache_key ): ?array
	{
		$method = new \ReflectionMethod( Review_Service::class, 'get_cached_review' );
		$method->setAccessible( true );
		return $method->invoke( $this, $cache_key );
	}

	/**
	 * Expose cache_review for testing using reflection.
	 *
	 * @param  string $cache_key Cache key.
	 * @param  array  $review    Review data to cache.
	 * @return void
	 */
	public function test_cache_review( string $cache_key, array $review ): void
	{
		$method = new \ReflectionMethod( Review_Service::class, 'cache_review' );
		$method->setAccessible( true );
		$method->invoke( $this, $cache_key, $review );
	}
}

/**
 * Test cases for caching methods in Review_Service.
 */
class ReviewServiceCacheTest extends TestCase
{

	/**
	 * Review service instance.
	 *
	 * @var TestableCacheReviewService
	 */
	private TestableCacheReviewService $service;

	/**
	 * Set up test environment before each test.
	 */
	protected function setUp(): void
	{
		parent::setUp();

		// Clear transient storage.
		$GLOBALS['test_transients'] = array();

		// Create service instance.
		$this->service = new TestableCacheReviewService();

		// Mock WordPress constants.
		if (! defined('HOUR_IN_SECONDS')) {
			define('HOUR_IN_SECONDS', 3600);
		}
	}

	/**
	 * Test generate_cache_key() produces consistent keys for identical input.
	 */
	public function test_generate_cache_key_consistency(): void
	{
		$blocks = array(
			array(
				'clientId' => 'abc123',
				'name'     => 'core/paragraph',
				'content'  => 'Test content',
			),
		);

		$options = array(
			'model'       => 'claude-sonnet-4',
			'focus_areas' => array( 'clarity' ),
			'target_tone' => 'professional',
			'post_title'  => 'Test Post',
		);

		$key1 = $this->service->generate_cache_key($blocks, $options);
		$key2 = $this->service->generate_cache_key($blocks, $options);

		$this->assertEquals($key1, $key2, 'Cache keys should be identical for same input');
		$this->assertStringStartsWith('ai_feedback_review_', $key1, 'Cache key should have proper prefix');
	}

	/**
	 * Test generate_cache_key() produces different keys when content changes.
	 */
	public function test_generate_cache_key_content_change(): void
	{
		$blocks1 = array(
			array(
				'clientId' => 'abc123',
				'name'     => 'core/paragraph',
				'content'  => 'Original content',
			),
		);

		$blocks2 = array(
			array(
				'clientId' => 'abc123',
				'name'     => 'core/paragraph',
				'content'  => 'Modified content',
			),
		);

		$options = array(
			'model'      => 'claude-sonnet-4',
			'post_title' => 'Test Post',
		);

		$key1 = $this->service->generate_cache_key($blocks1, $options);
		$key2 = $this->service->generate_cache_key($blocks2, $options);

		$this->assertNotEquals($key1, $key2, 'Cache keys should differ when content changes');
	}

	/**
	 * Test generate_cache_key() produces different keys when model changes.
	 */
	public function test_generate_cache_key_model_change(): void
	{
		$blocks = array(
			array(
				'clientId' => 'abc123',
				'name'     => 'core/paragraph',
				'content'  => 'Test content',
			),
		);

		$options1 = array(
			'model'      => 'claude-sonnet-4',
			'post_title' => 'Test Post',
		);

		$options2 = array(
			'model'      => 'claude-opus-4',
			'post_title' => 'Test Post',
		);

		$key1 = $this->service->generate_cache_key($blocks, $options1);
		$key2 = $this->service->generate_cache_key($blocks, $options2);

		$this->assertNotEquals($key1, $key2, 'Cache keys should differ when model changes');
	}

	/**
	 * Test generate_cache_key() produces different keys when post_title changes.
	 */
	public function test_generate_cache_key_title_change(): void
	{
		$blocks = array(
			array(
				'clientId' => 'abc123',
				'name'     => 'core/paragraph',
				'content'  => 'Test content',
			),
		);

		$options1 = array(
			'model'      => 'claude-sonnet-4',
			'post_title' => 'Original Title',
		);

		$options2 = array(
			'model'      => 'claude-sonnet-4',
			'post_title' => 'Modified Title',
		);

		$key1 = $this->service->generate_cache_key($blocks, $options1);
		$key2 = $this->service->generate_cache_key($blocks, $options2);

		$this->assertNotEquals($key1, $key2, 'Cache keys should differ when post title changes');
	}

	/**
	 * Test generate_cache_key() produces different keys when focus_areas change.
	 */
	public function test_generate_cache_key_focus_areas_change(): void
	{
		$blocks = array(
			array(
				'clientId' => 'abc123',
				'name'     => 'core/paragraph',
				'content'  => 'Test content',
			),
		);

		$options1 = array(
			'model'       => 'claude-sonnet-4',
			'focus_areas' => array( 'clarity' ),
			'post_title'  => 'Test Post',
		);

		$options2 = array(
			'model'       => 'claude-sonnet-4',
			'focus_areas' => array( 'clarity', 'tone' ),
			'post_title'  => 'Test Post',
		);

		$key1 = $this->service->generate_cache_key($blocks, $options1);
		$key2 = $this->service->generate_cache_key($blocks, $options2);

		$this->assertNotEquals($key1, $key2, 'Cache keys should differ when focus areas change');
	}

	/**
	 * Test generate_cache_key() produces different keys when target_tone changes.
	 */
	public function test_generate_cache_key_target_tone_change(): void
	{
		$blocks = array(
			array(
				'clientId' => 'abc123',
				'name'     => 'core/paragraph',
				'content'  => 'Test content',
			),
		);

		$options1 = array(
			'model'       => 'claude-sonnet-4',
			'target_tone' => 'professional',
			'post_title'  => 'Test Post',
		);

		$options2 = array(
			'model'       => 'claude-sonnet-4',
			'target_tone' => 'casual',
			'post_title'  => 'Test Post',
		);

		$key1 = $this->service->generate_cache_key($blocks, $options1);
		$key2 = $this->service->generate_cache_key($blocks, $options2);

		$this->assertNotEquals($key1, $key2, 'Cache keys should differ when target tone changes');
	}

	/**
	 * Test generate_cache_key() produces different keys when clientId changes.
	 */
	public function test_generate_cache_key_client_id_change(): void
	{
		$blocks1 = array(
			array(
				'clientId' => 'abc123',
				'name'     => 'core/paragraph',
				'content'  => 'Test content',
			),
		);

		$blocks2 = array(
			array(
				'clientId' => 'xyz789',
				'name'     => 'core/paragraph',
				'content'  => 'Test content',
			),
		);

		$options = array(
			'model'      => 'claude-sonnet-4',
			'post_title' => 'Test Post',
		);

		$key1 = $this->service->generate_cache_key($blocks1, $options);
		$key2 = $this->service->generate_cache_key($blocks2, $options);

		$this->assertNotEquals($key1, $key2, 'Cache keys should differ when clientId changes');
	}

	/**
	 * Test generate_cache_key() produces different keys when block count changes.
	 */
	public function test_generate_cache_key_block_count_change(): void
	{
		$blocks1 = array(
			array(
				'clientId' => 'abc123',
				'name'     => 'core/paragraph',
				'content'  => 'First block',
			),
		);

		$blocks2 = array(
			array(
				'clientId' => 'abc123',
				'name'     => 'core/paragraph',
				'content'  => 'First block',
			),
			array(
				'clientId' => 'def456',
				'name'     => 'core/paragraph',
				'content'  => 'Second block',
			),
		);

		$options = array(
			'model'      => 'claude-sonnet-4',
			'post_title' => 'Test Post',
		);

		$key1 = $this->service->generate_cache_key($blocks1, $options);
		$key2 = $this->service->generate_cache_key($blocks2, $options);

		$this->assertNotEquals($key1, $key2, 'Cache keys should differ when block count changes');
	}

	/**
	 * Test generate_cache_key() handles empty blocks array.
	 */
	public function test_generate_cache_key_empty_blocks(): void
	{
		$blocks = array();

		$options = array(
			'model'      => 'claude-sonnet-4',
			'post_title' => 'Test Post',
		);

		$key = $this->service->generate_cache_key($blocks, $options);

		$this->assertIsString($key, 'Cache key should be a string even with empty blocks');
		$this->assertStringStartsWith('ai_feedback_review_', $key, 'Cache key should have proper prefix');
	}

	/**
	 * Test generate_cache_key() handles missing optional fields.
	 */
	public function test_generate_cache_key_missing_optional_fields(): void
	{
		$blocks = array(
			array(
				'clientId' => 'abc123',
				'name'     => 'core/paragraph',
				'content'  => 'Test content',
			),
		);

		$options = array(); // No optional fields.

		$key = $this->service->generate_cache_key($blocks, $options);

		$this->assertIsString($key, 'Cache key should be generated with default values');
		$this->assertStringStartsWith('ai_feedback_review_', $key, 'Cache key should have proper prefix');
	}

	/**
	 * Test generate_cache_key() handles blocks with missing fields.
	 */
	public function test_generate_cache_key_blocks_missing_fields(): void
	{
		$blocks = array(
			array(
				'clientId' => 'abc123',
				// Missing 'name' and 'content'.
			),
			array(
				'name'    => 'core/paragraph',
				'content' => 'Test content',
				// Missing 'clientId'.
			),
		);

		$options = array(
			'model'      => 'claude-sonnet-4',
			'post_title' => 'Test Post',
		);

		$key = $this->service->generate_cache_key($blocks, $options);

		$this->assertIsString($key, 'Cache key should handle blocks with missing fields');
		$this->assertStringStartsWith('ai_feedback_review_', $key, 'Cache key should have proper prefix');
	}

	/**
	 * Test cache miss returns null when cache doesn't exist.
	 */
	public function test_cache_miss(): void
	{
		$cache_key = 'ai_feedback_review_test_miss';

		$result = $this->service->test_get_cached_review( $cache_key );

		$this->assertNull( $result, 'Cache miss should return null' );
	}

	/**
	 * Test cache hit returns cached data.
	 */
	public function test_cache_hit(): void
	{
		$cache_key = 'ai_feedback_review_test_hit';

		// Create cache data.
		$review_data = array(
			'review_id'    => 'test-uuid-123',
			'model'        => 'claude-sonnet-4',
			'summary'      => 'Great article!',
			'summary_text' => 'This is a well-written article.',
			'feedback'     => array(
				array(
					'block_id' => 'abc123',
					'text'     => 'Consider adding more details.',
					'severity' => 'suggestion',
				),
			),
			'cached_at'    => time(),
		);

		// Cache the review.
		$this->service->test_cache_review( $cache_key, $review_data );

		// Retrieve from cache.
		$result = $this->service->test_get_cached_review( $cache_key );

		$this->assertIsArray( $result, 'Cache hit should return array' );
		$this->assertEquals( $review_data['review_id'], $result['review_id'], 'Review ID should match' );
		$this->assertEquals( $review_data['model'], $result['model'], 'Model should match' );
		$this->assertEquals( $review_data['summary'], $result['summary'], 'Summary should match' );
		$this->assertEquals( $review_data['feedback'], $result['feedback'], 'Feedback should match' );
	}

	/**
	 * Test cache expiration deletes expired cache.
	 */
	public function test_cache_expiration(): void
	{
		$cache_key = 'ai_feedback_review_test_expired';

		// Create expired cache data (more than 1 hour old).
		$review_data = array(
			'review_id'    => 'test-uuid-expired',
			'model'        => 'claude-sonnet-4',
			'summary'      => 'Old review',
			'summary_text' => 'This is an old review.',
			'feedback'     => array(),
			'cached_at'    => time() - (HOUR_IN_SECONDS + 100), // Expired by 100 seconds.
		);

		// Manually set the transient (bypass cache_review to test expiration).
		set_transient( $cache_key, $review_data, HOUR_IN_SECONDS );

		// Attempt to retrieve from cache.
		$result = $this->service->test_get_cached_review( $cache_key );

		$this->assertNull( $result, 'Expired cache should return null' );

		// Verify cache was deleted.
		$transient_exists = get_transient( $cache_key );
		$this->assertFalse( $transient_exists, 'Expired cache should be deleted' );
	}

	/**
	 * Test cache with invalid structure is deleted.
	 */
	public function test_cache_invalid_structure(): void
	{
		$cache_key = 'ai_feedback_review_test_invalid';

		// Create invalid cache data (missing required fields).
		$invalid_data = array(
			'review_id' => 'test-uuid-invalid',
			// Missing 'summary', 'feedback', 'cached_at'.
		);

		// Manually set the transient.
		set_transient( $cache_key, $invalid_data, HOUR_IN_SECONDS );

		// Attempt to retrieve from cache.
		$result = $this->service->test_get_cached_review( $cache_key );

		$this->assertNull( $result, 'Invalid cache structure should return null' );

		// Verify cache was deleted.
		$transient_exists = get_transient( $cache_key );
		$this->assertFalse( $transient_exists, 'Invalid cache should be deleted' );
	}

	/**
	 * Test cache stores all required fields.
	 */
	public function test_cache_stores_required_fields(): void
	{
		$cache_key = 'ai_feedback_review_test_fields';

		// Create comprehensive review data.
		$review_data = array(
			'review_id'    => 'test-uuid-fields',
			'model'        => 'claude-opus-4',
			'summary'      => 'Excellent content',
			'summary_text' => 'The article is well-structured and informative.',
			'feedback'     => array(
				array(
					'block_id' => 'block-1',
					'text'     => 'Great introduction',
					'severity' => 'praise',
				),
				array(
					'block_id' => 'block-2',
					'text'     => 'Add more examples',
					'severity' => 'suggestion',
				),
			),
			'cached_at'    => time(),
		);

		// Cache the review.
		$this->service->test_cache_review( $cache_key, $review_data );

		// Verify transient contains all fields.
		$cached = get_transient( $cache_key );

		$this->assertIsArray( $cached, 'Cached data should be an array' );
		$this->assertArrayHasKey( 'review_id', $cached, 'Cache should have review_id' );
		$this->assertArrayHasKey( 'model', $cached, 'Cache should have model' );
		$this->assertArrayHasKey( 'summary', $cached, 'Cache should have summary' );
		$this->assertArrayHasKey( 'summary_text', $cached, 'Cache should have summary_text' );
		$this->assertArrayHasKey( 'feedback', $cached, 'Cache should have feedback' );
		$this->assertArrayHasKey( 'cached_at', $cached, 'Cache should have cached_at timestamp' );
	}
}
