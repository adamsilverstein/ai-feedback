<?php
/**
 * Tests for the review endpoint's arguments.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\Review_Controller;

require_once dirname( __DIR__, 2 ) . '/includes/class-review-controller.php';

/**
 * Test cases for the review endpoint's schema.
 */
class ReviewControllerArgsTest extends TestCase {

	/**
	 * A block's text keeps its newlines, which stand for line breaks the
	 * editor matches a quick fix against; `text-field` would fold them
	 * into spaces.
	 */
	public function test_block_content_keeps_newlines(): void {
		$controller = ( new \ReflectionClass( Review_Controller::class ) )->newInstanceWithoutConstructor();
		$args       = ( new \ReflectionMethod( Review_Controller::class, 'get_create_review_args' ) )->invoke( $controller );

		$format = $args;
		foreach ( array( 'blocks', 'items', 'properties', 'content', 'format' ) as $key ) {
			$format = is_array( $format ) ? ( $format[ $key ] ?? null ) : null;
		}
		$this->assertSame( 'textarea-field', $format );
	}
}
