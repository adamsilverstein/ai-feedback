<?php
/**
 * Tests for persona reviews in Review_Service.
 *
 * Runs in separate processes because each test defines AI_FEEDBACK_MOCK_MODE.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use AI_Feedback\Notes_Manager;
use AI_Feedback\Review_Service;
use ReflectionProperty;
use WP_Error;

/**
 * Notes manager that records the review data instead of creating notes.
 */
class Persona_Review_Notes_Manager extends Notes_Manager {

	/**
	 * Review data passed in.
	 *
	 * @var array<mixed>
	 */
	public array $review_data = array();

	/**
	 * Record the review data and skip note creation.
	 *
	 * @param  array<int, array<string, mixed>> $feedback_items Feedback items.
	 * @param  int                              $post_id        Post ID.
	 * @param  array<string, mixed>             $review_data    Review data.
	 * @return array<string, mixed>|WP_Error Always an error.
	 */
	public function create_notes_from_feedback( array $feedback_items, int $post_id, array $review_data = array() ): array|WP_Error {
		$this->review_data = $review_data;
		return new WP_Error( 'skipped', 'Notes skipped.' );
	}
}

/**
 * Test cases for persona reviews.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class ReviewServicePersonaTest extends TestCase {

	/**
	 * Run a mock review.
	 *
	 * @param  array<string, mixed>         $options Review options.
	 * @param  Persona_Review_Notes_Manager $notes   Notes manager.
	 * @return array<mixed> Review result.
	 */
	private function review( array $options, Persona_Review_Notes_Manager $notes ): array {
		if ( ! defined( 'AI_FEEDBACK_MOCK_MODE' ) ) {
			define( 'AI_FEEDBACK_MOCK_MODE', true );
		}
		$service = new Review_Service();
		( new ReflectionProperty( Review_Service::class, 'notes_manager' ) )->setValue( $service, $notes );

		$result = $service->review_document(
			1,
			$options + array(
				'post_title' => 'Personas',
				'blocks'     => array(
					array(
						'clientId' => 'block-1',
						'name'     => 'core/paragraph',
						'content'  => 'Our plugin is 40% faster.',
					),
				),
			)
		);
		$this->assertIsArray( $result );
		return $result;
	}

	/**
	 * A persona review tags its notes and returns the persona's takeaway.
	 */
	public function test_persona_review_returns_takeaway(): void {
		$notes  = new Persona_Review_Notes_Manager();
		$result = $this->review( array( 'persona' => 'skeptical_expert' ), $notes );

		$this->assertSame( 'skeptical_expert', $notes->review_data['persona'] ?? null );
		$this->assertSame( 'skeptical_expert', $result['persona'] ?? null );
		$this->assertSame( 'Skeptical expert', $result['persona_label'] ?? null );
		$this->assertSame( 'Mock takeaway from the Skeptical expert.', $result['persona_takeaway'] ?? null );
	}

	/**
	 * A default review returns no persona fields, as before.
	 */
	public function test_default_review_has_no_persona_fields(): void {
		$notes  = new Persona_Review_Notes_Manager();
		$result = $this->review( array( 'persona' => 'not_a_persona' ), $notes );

		$this->assertSame( 'copy_editor', $notes->review_data['persona'] ?? null );
		$this->assertArrayNotHasKey( 'persona', $result );
		$this->assertArrayNotHasKey( 'persona_takeaway', $result );
	}
}
