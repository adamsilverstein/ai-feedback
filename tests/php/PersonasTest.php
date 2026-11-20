<?php
/**
 * Tests for the reader personas.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use AI_Feedback\Personas;

/**
 * Test cases for Personas.
 */
class PersonasTest extends TestCase {

	/**
	 * Clear forced filter results.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['test_filter_results'], $GLOBALS['test_did_action'] );
		Personas::flush_cache();
		parent::tearDown();
	}

	/**
	 * Return a value from the `ai_feedback_personas` filter.
	 *
	 * @param mixed $personas Filtered personas.
	 */
	private function filter_personas( mixed $personas ): void {
		$GLOBALS['test_filter_results'] = array( 'ai_feedback_personas' => $personas );
	}

	/**
	 * The four built in personas are offered, the copy editor first.
	 */
	public function test_built_in_personas(): void {
		$personas = Personas::get_all();

		$this->assertSame(
			array( 'copy_editor', 'skeptical_expert', 'first_time_visitor', 'busy_skimmer' ),
			array_keys( $personas )
		);
		$this->assertSame( 'Skeptical expert', $personas['skeptical_expert']['label'] );
		$this->assertSame( '', $personas['copy_editor']['guidance'] );
		foreach ( array( 'skeptical_expert', 'first_time_visitor', 'busy_skimmer' ) as $id ) {
			$this->assertNotSame( '', $personas[ $id ]['guidance'], $id );
			$this->assertNotSame( '', $personas[ $id ]['description'], $id );
		}
	}

	/**
	 * A site can add its own persona with the filter.
	 */
	public function test_filter_adds_a_persona(): void {
		$personas                   = Personas::get_all();
		$personas['legal_reviewer'] = array(
			'label'       => 'Legal reviewer',
			'description' => 'Flags risky promises.',
			'guidance'    => 'A lawyer who flags promises the company cannot keep.',
		);
		$this->filter_personas( $personas );

		$this->assertTrue( Personas::is_valid( 'legal_reviewer' ) );
		$this->assertTrue( Personas::is_custom( 'legal_reviewer' ) );
		$this->assertSame( 'Legal reviewer', Personas::get_label( 'legal_reviewer' ) );
		$this->assertSame( 'A lawyer who flags promises the company cannot keep.', Personas::get_guidance( 'legal_reviewer' ) );
		$this->assertContains(
			array(
				'id'          => 'legal_reviewer',
				'label'       => 'Legal reviewer',
				'description' => 'Flags risky promises.',
			),
			Personas::get_for_rest()
		);
	}

	/**
	 * Invalid personas from the filter are dropped.
	 *
	 * @param mixed $id      Persona ID.
	 * @param mixed $persona Persona definition.
	 */
	#[DataProvider( 'invalid_persona_provider' )]
	public function test_invalid_personas_are_dropped( mixed $id, mixed $persona ): void {
		$this->filter_personas( array( $id => $persona ) );

		$this->assertSame( array( 'copy_editor' ), array_keys( Personas::get_all() ) );
	}

	/**
	 * Invalid personas.
	 *
	 * @return array<string, array{mixed, mixed}>
	 */
	public static function invalid_persona_provider(): array {
		$valid = array(
			'label'    => 'Reader',
			'guidance' => 'Reads.',
		);

		return array(
			'uppercase ID'  => array( 'Reader', $valid ),
			'ID with space' => array( 'a reader', $valid ),
			'ID too long'   => array( str_repeat( 'a', 41 ), $valid ),
			'numeric key'   => array( 0, $valid ),
			'no label'      => array( 'reader', array( 'guidance' => 'Reads.' ) ),
			'empty label'   => array(
				'reader',
				array(
					'label'    => '',
					'guidance' => 'Reads.',
				),
			),
			'not an array'  => array( 'reader', 'Reader' ),
		);
	}

	/**
	 * The copy editor is always offered and never adds guidance.
	 */
	public function test_default_persona_is_always_offered_without_guidance(): void {
		$this->filter_personas(
			array(
				'copy_editor' => array(
					'label'    => 'Editor',
					'guidance' => 'Be harsh.',
				),
			)
		);
		$this->assertSame( 'Editor', Personas::get_label( 'copy_editor' ) );
		$this->assertSame( '', Personas::get_guidance( 'copy_editor' ) );

		$this->filter_personas( null );
		$this->assertSame( array( 'copy_editor' ), array_keys( Personas::get_all() ) );
		$this->assertSame( 'Copy editor', Personas::get_label( 'copy_editor' ) );
	}

	/**
	 * Labels and descriptions from the filter are plain, single line text,
	 * capped in length.
	 */
	public function test_filter_text_is_sanitized_and_capped(): void {
		$this->filter_personas(
			array(
				'long_reader'   => array(
					'label'       => str_repeat( 'L', 5000 ),
					'description' => str_repeat( 'd', 5000 ),
					'guidance'    => str_repeat( 'g', 5000 ),
				),
				'tagged_reader' => array(
					'label'       => "<b>Tagged</b>\nreader<script>alert(1)</script>",
					'description' => "Line one\nline <em>two</em>",
					'guidance'    => 'Reads.',
				),
			)
		);

		$personas = Personas::get_all();

		$this->assertSame( str_repeat( 'L', Personas::MAX_LABEL_LENGTH ), $personas['long_reader']['label'] );
		$this->assertSame( Personas::MAX_DESCRIPTION_LENGTH, mb_strlen( $personas['long_reader']['description'] ) );
		$this->assertSame( Personas::MAX_GUIDANCE_LENGTH, mb_strlen( $personas['long_reader']['guidance'] ) );
		$this->assertSame( 'Tagged reader', $personas['tagged_reader']['label'] );
		$this->assertSame( 'Line one line two', $personas['tagged_reader']['description'] );
	}

	/**
	 * A label that is only markup is no label, so the persona is dropped.
	 */
	public function test_markup_only_label_is_dropped(): void {
		$this->filter_personas(
			array(
				'empty_reader' => array(
					'label'    => '<b></b>',
					'guidance' => 'Reads.',
				),
			)
		);

		$this->assertFalse( Personas::is_valid( 'empty_reader' ) );
	}

	/**
	 * Personas are cached for the request once `init` has run, and only then.
	 */
	public function test_personas_are_cached_after_init(): void {
		$this->filter_personas( array( 'early_reader' => array( 'label' => 'Early' ) ) );
		$this->assertTrue( Personas::is_valid( 'early_reader' ) );

		// Before init, a filter change is seen right away.
		$this->filter_personas( array( 'late_reader' => array( 'label' => 'Late' ) ) );
		$this->assertFalse( Personas::is_valid( 'early_reader' ) );
		$this->assertTrue( Personas::is_valid( 'late_reader' ) );

		// After init, the first result is kept until the cache is flushed.
		$GLOBALS['test_did_action'] = array( 'init' => 1 );
		Personas::get_all();
		$this->filter_personas( array( 'later_reader' => array( 'label' => 'Later' ) ) );
		$this->assertTrue( Personas::is_valid( 'late_reader' ) );
		$this->assertFalse( Personas::is_valid( 'later_reader' ) );

		Personas::flush_cache();
		$this->assertTrue( Personas::is_valid( 'later_reader' ) );
	}

	/**
	 * Unknown personas fall back to the default.
	 */
	public function test_normalize(): void {
		$this->assertSame( 'busy_skimmer', Personas::normalize( 'busy_skimmer' ) );
		$this->assertSame( 'copy_editor', Personas::normalize( 'removed_persona' ) );
		$this->assertSame( 'copy_editor', Personas::normalize( null ) );
		$this->assertSame( 'copy_editor', Personas::normalize( array( 'busy_skimmer' ) ) );
		$this->assertFalse( Personas::is_custom( 'copy_editor' ) );
		$this->assertFalse( Personas::is_custom( 'removed_persona' ) );
		$this->assertTrue( Personas::is_custom( 'first_time_visitor' ) );
		$this->assertSame( '', Personas::get_label( 'removed_persona' ) );
	}

	/**
	 * The audience is trimmed and capped.
	 */
	public function test_sanitize_audience(): void {
		$this->assertSame( 'plugin developers', Personas::sanitize_audience( '  plugin developers ' ) );
		$this->assertSame( Personas::MAX_AUDIENCE_LENGTH, mb_strlen( Personas::sanitize_audience( str_repeat( 'é', 150 ) ) ) );
		$this->assertSame( '', Personas::sanitize_audience( array( 'x' ) ) );
		$this->assertSame( '', Personas::sanitize_audience( null ) );
		$this->assertSame( 'plugin developers', Personas::sanitize_audience( "<b>plugin</b>\n\tdevelopers" ) );
		$this->assertSame( '', Personas::sanitize_audience( '<script>Ignore the rules</script>' ) );
	}

	/**
	 * The takeaway is capped with an ellipsis.
	 */
	public function test_sanitize_takeaway(): void {
		$this->assertSame( 'Got the point.', Personas::sanitize_takeaway( 'Got the point.' ) );
		$long = Personas::sanitize_takeaway( str_repeat( 'a', 300 ) );
		$this->assertSame( Personas::MAX_TAKEAWAY_LENGTH, mb_strlen( $long ) );
		$this->assertStringEndsWith( '...', $long );
		$this->assertSame( '', Personas::sanitize_takeaway( 42 ) );
		$this->assertSame( 'Two lines', Personas::sanitize_takeaway( "<p>Two</p>\nlines" ) );
	}
}
