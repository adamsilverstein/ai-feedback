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
 * Test cases for caching methods in Review_Service.
 */
class ReviewServiceCacheTest extends TestCase
{

	/**
	 * Mock transient storage.
	 *
	 * @var array
	 */
	private static array $transients = array();

	/**
	 * Review service instance.
	 *
	 * @var Review_Service
	 */
	private Review_Service $service;

	/**
	 * Set up test environment before each test.
	 */
	protected function setUp(): void
	{
		parent::setUp();

		// Clear transient storage.
		self::$transients = array();

		// Create service instance.
		$this->service = new Review_Service();

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
}

// Mock WordPress transient functions for testing.
if (! function_exists('get_transient')) {
	/**
	 * Mock get_transient for testing.
	 *
	 * @param  string $transient Transient name.
	 * @return mixed Transient value or false if not found.
	 */
	function get_transient(string $transient)
	{
		return ReviewServiceCacheTest::$transients[ $transient ] ?? false;
	}
}

if (! function_exists('set_transient')) {
	/**
	 * Mock set_transient for testing.
	 *
	 * @param  string $transient  Transient name.
	 * @param  mixed  $value      Transient value.
	 * @param  int    $expiration Expiration time in seconds.
	 * @return bool Always true.
	 */
	function set_transient(string $transient, $value, int $expiration): bool
	{
		ReviewServiceCacheTest::$transients[ $transient ] = $value;
		return true;
	}
}

if (! function_exists('delete_transient')) {
	/**
	 * Mock delete_transient for testing.
	 *
	 * @param  string $transient Transient name.
	 * @return bool Always true.
	 */
	function delete_transient(string $transient): bool
	{
		unset(ReviewServiceCacheTest::$transients[ $transient ]);
		return true;
	}
}

if (! function_exists('wp_json_encode')) {
	/**
	 * Mock wp_json_encode for testing.
	 *
	 * @param  mixed $data    Data to encode.
	 * @param  int   $options Optional. JSON encode options.
	 * @param  int   $depth   Optional. Maximum depth.
	 * @return string|false JSON string or false on failure.
	 */
	function wp_json_encode($data, int $options = 0, int $depth = 512)
	{
		return json_encode($data, $options, $depth);
	}
}

if (! function_exists('wp_generate_uuid4')) {
	/**
	 * Mock wp_generate_uuid4 for testing.
	 *
	 * @return string UUID v4 string.
	 */
	function wp_generate_uuid4(): string
	{
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand(0, 0xffff),
			mt_rand(0, 0xffff),
			mt_rand(0, 0xffff),
			mt_rand(0, 0x0fff) | 0x4000,
			mt_rand(0, 0x3fff) | 0x8000,
			mt_rand(0, 0xffff),
			mt_rand(0, 0xffff),
			mt_rand(0, 0xffff)
		);
	}
}

if (! function_exists('current_time')) {
	/**
	 * Mock current_time for testing.
	 *
	 * @param  string $type Type of time to retrieve.
	 * @return string|int Current time.
	 */
	function current_time(string $type)
	{
		if ('mysql' === $type) {
			return gmdate('Y-m-d H:i:s');
		}
		return time();
	}
}
