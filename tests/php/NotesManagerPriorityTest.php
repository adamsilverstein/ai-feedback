<?php
/**
 * Tests for priority handling in Notes_Manager.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use AI_Feedback\Notes_Manager;

/**
 * Priority must survive the round trip through note meta so the editor
 * can show priority badges and sort notes returned by the review endpoint.
 */
class NotesManagerPriorityTest extends TestCase {

	/**
	 * Notes manager instance.
	 *
	 * @var Notes_Manager
	 */
	private Notes_Manager $manager;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->manager                = new Notes_Manager();
		$GLOBALS['test_comment_meta'] = array();
	}

	/**
	 * Clean up global meta store.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['test_comment_meta'] );
		parent::tearDown();
	}

	/**
	 * Call a private Notes_Manager method.
	 *
	 * @param string $method Method name.
	 * @param mixed  ...$args Arguments.
	 * @return mixed Method return value.
	 */
	private function call_private( string $method, ...$args ): mixed {
		$reflection = new ReflectionMethod( Notes_Manager::class, $method );
		return $reflection->invoke( $this->manager, ...$args );
	}

	/**
	 * An integer priority is stored as note meta.
	 */
	public function test_build_note_meta_stores_priority(): void {
		$meta = $this->call_private(
			'build_note_meta',
			array(
				'category' => 'content',
				'severity' => 'critical',
				'priority' => 155,
			),
			array()
		);

		$this->assertIsArray( $meta );
		$this->assertSame( 155, $meta['feedback_priority'] );
	}

	/**
	 * Items without a priority do not get a priority meta key.
	 */
	public function test_build_note_meta_omits_missing_priority(): void {
		$meta = $this->call_private(
			'build_note_meta',
			array(
				'category' => 'content',
				'severity' => 'critical',
			),
			array()
		);

		$this->assertIsArray( $meta );
		$this->assertArrayNotHasKey( 'feedback_priority', $meta );
	}

	/**
	 * Numeric meta strings are returned as integers.
	 */
	public function test_get_int_meta_casts_numeric_strings(): void {
		$GLOBALS['test_comment_meta'] = array(
			7 => array(
				'feedback_priority' => '120',
				'block_index'       => '0',
			),
		);

		$this->assertSame( 120, $this->call_private( 'get_int_meta', 7, 'feedback_priority' ) );
		$this->assertSame( 0, $this->call_private( 'get_int_meta', 7, 'block_index' ) );
	}

	/**
	 * Missing or non-numeric meta returns null.
	 */
	public function test_get_int_meta_returns_null_for_missing_values(): void {
		$GLOBALS['test_comment_meta'] = array(
			8 => array( 'feedback_priority' => 'abc' ),
		);

		$this->assertNull( $this->call_private( 'get_int_meta', 8, 'feedback_priority' ) );
		$this->assertNull( $this->call_private( 'get_int_meta', 8, 'block_index' ) );
	}
}
