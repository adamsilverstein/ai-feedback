<?php
/**
 * Tests for the house style REST endpoints.
 *
 * @package AI_Feedback\Tests
 */

namespace AI_Feedback\Tests;

use PHPUnit\Framework\TestCase;
use AI_Feedback\House_Style;
use AI_Feedback\House_Style_Controller;
use WP_REST_Request;
use WP_REST_Response;

require_once __DIR__ . '/house-style-mocks.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-house-style-controller.php';

/**
 * Permissions and responses of /house-style.
 */
class HouseStyleControllerTest extends TestCase {

	/**
	 * ID of the acting user.
	 */
	private const USER_ID = 7;

	/**
	 * Post the proposal note is on.
	 */
	private const POST_ID = 42;

	/**
	 * AI reply that proposes a rule.
	 */
	private const NOTE_ID = 300;

	/**
	 * Controller under test.
	 *
	 * @var House_Style_Controller
	 */
	private House_Style_Controller $controller;

	/**
	 * Reset storage and seed an AI reply with a proposed rule.
	 */
	protected function setUp(): void {
		$this->controller                = new House_Style_Controller();
		$GLOBALS['test_options']         = array();
		$GLOBALS['test_user_meta']       = array();
		$GLOBALS['test_current_user_id'] = self::USER_ID;
		$GLOBALS['test_comments']        = array(
			self::NOTE_ID => (object) array(
				'comment_ID'      => (string) self::NOTE_ID,
				'comment_post_ID' => (string) self::POST_ID,
				'comment_type'    => 'note',
			),
			301           => (object) array(
				'comment_ID'      => '301',
				'comment_post_ID' => (string) self::POST_ID,
				'comment_type'    => 'note',
			),
		);
		$GLOBALS['test_comment_meta']    = array(
			self::NOTE_ID => array( House_Style::PROPOSAL_META_KEY => 'Headings use sentence case' ),
		);
	}

	/**
	 * Clean up globals.
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['test_options'],
			$GLOBALS['test_user_meta'],
			$GLOBALS['test_current_user_id'],
			$GLOBALS['test_current_user_can'],
			$GLOBALS['test_comments'],
			$GLOBALS['test_comment_meta']
		);
	}

	/**
	 * Act as a user with only the given capabilities. `edit_post` is granted
	 * for POST_ID when `edit_post` is listed.
	 *
	 * @param string[] $capabilities Capabilities the user has.
	 */
	private function act_as( array $capabilities ): void {
		$GLOBALS['test_current_user_can'] = static function ( string $cap, mixed ...$args ) use ( $capabilities ): bool {
			if ( 'edit_post' === $cap ) {
				return in_array( 'edit_post', $capabilities, true ) && self::POST_ID === ( $args[0] ?? null );
			}
			return in_array( $cap, $capabilities, true );
		};
	}

	/**
	 * Build a request.
	 *
	 * @param  array<string, mixed> $params Parameters.
	 * @return WP_REST_Request
	 */
	private function request( array $params = array() ): WP_REST_Request {
		$request = new WP_REST_Request();
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * Read a response's data.
	 *
	 * @param  mixed $response Response.
	 * @return array<string, mixed>
	 */
	private function data( mixed $response ): array {
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		return $data;
	}

	/**
	 * Anyone who can edit posts can read the rules; others can't.
	 */
	public function test_reading_rules_needs_edit_posts(): void {
		$this->act_as( array( 'edit_posts' ) );
		$this->assertTrue( $this->controller->get_rules_permissions_check() );

		$this->act_as( array( 'read' ) );
		$result = $this->controller->get_rules_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * An author can manage their own rules but not the site's.
	 */
	public function test_author_can_edit_personal_rules_only(): void {
		$this->act_as( array( 'edit_posts' ) );

		$this->assertTrue( $this->controller->edit_rules_permissions_check( $this->request( array( 'scope' => 'user' ) ) ) );
		$this->assertTrue( $this->controller->edit_rules_permissions_check( $this->request() ) );
		$this->assertInstanceOf( \WP_Error::class, $this->controller->edit_rules_permissions_check( $this->request( array( 'scope' => 'site' ) ) ) );
	}

	/**
	 * An administrator can manage site rules.
	 */
	public function test_admin_can_edit_site_rules(): void {
		$this->act_as( array( 'edit_posts', 'manage_options' ) );

		$this->assertTrue( $this->controller->edit_rules_permissions_check( $this->request( array( 'scope' => 'site' ) ) ) );
	}

	/**
	 * A user who can't edit posts can't save personal rules either.
	 */
	public function test_subscriber_cannot_edit_rules(): void {
		$this->act_as( array( 'read' ) );

		$this->assertInstanceOf( \WP_Error::class, $this->controller->edit_rules_permissions_check( $this->request() ) );
	}

	/**
	 * Saving from a proposal needs a real proposal on a post the user can edit.
	 */
	public function test_saving_from_a_proposal_checks_the_note(): void {
		$this->act_as( array( 'edit_posts', 'edit_post' ) );
		$this->assertTrue( $this->controller->edit_rules_permissions_check( $this->request( array( 'note_id' => self::NOTE_ID ) ) ) );

		// A note without a proposal.
		$missing = $this->controller->edit_rules_permissions_check( $this->request( array( 'note_id' => 301 ) ) );
		$this->assertInstanceOf( \WP_Error::class, $missing );
		$this->assertSame( 404, $missing->get_error_data()['status'] );

		// A user who can't edit the note's post.
		$this->act_as( array( 'edit_posts' ) );
		$forbidden = $this->controller->edit_rules_permissions_check( $this->request( array( 'note_id' => self::NOTE_ID ) ) );
		$this->assertInstanceOf( \WP_Error::class, $forbidden );
		$this->assertSame( 'rest_forbidden', $forbidden->get_error_code() );
	}

	/**
	 * Dismissing a proposal has the same checks, and a non-note is rejected.
	 */
	public function test_dismiss_permissions(): void {
		$this->act_as( array( 'edit_posts', 'edit_post' ) );
		$this->assertTrue( $this->controller->proposal_permissions_check( $this->request( array( 'note_id' => self::NOTE_ID ) ) ) );

		$GLOBALS['test_comments'][ self::NOTE_ID ]->comment_type = 'comment';
		$this->assertInstanceOf( \WP_Error::class, $this->controller->proposal_permissions_check( $this->request( array( 'note_id' => self::NOTE_ID ) ) ) );
	}

	/**
	 * GET lists both scopes, the limits and whether the user manages site rules.
	 */
	public function test_get_rules_lists_both_scopes(): void {
		$this->act_as( array( 'edit_posts' ) );
		House_Style::add_rule( House_Style::SCOPE_SITE, 'Use the Oxford comma' );
		House_Style::add_rule( House_Style::SCOPE_USER, 'Use sentence case' );

		$data = $this->data( $this->controller->get_rules() );

		$this->assertSame( array( 'Use the Oxford comma' ), array_column( $data['site'], 'text' ) );
		$this->assertSame( array( 'Use sentence case' ), array_column( $data['user'], 'text' ) );
		$this->assertFalse( $data['can_manage_site'] );
		$this->assertSame( House_Style::MAX_RULES, $data['max_rules'] );
		$this->assertSame( House_Style::MAX_RULE_LENGTH, $data['max_length'] );
	}

	/**
	 * POST adds a rule and returns it with the lists.
	 */
	public function test_create_rule(): void {
		$response = $this->controller->create_rule(
			$this->request(
				array(
					'scope' => 'site',
					'text'  => '<b>Use</b> the Oxford comma',
				)
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$data = $this->data( $response );
		$this->assertSame( 'Use the Oxford comma', $data['rule']['text'] );
		$this->assertSame( array( $data['rule'] ), $data['site'] );
		$this->assertSame( array(), $data['user'] );
	}

	/**
	 * POST with a proposal marks the proposal as saved.
	 */
	public function test_create_rule_from_proposal_marks_it_saved(): void {
		$this->controller->create_rule(
			$this->request(
				array(
					'text'    => 'Headings use sentence case, except proper nouns',
					'note_id' => self::NOTE_ID,
				)
			)
		);

		$this->assertSame(
			array(
				'text'   => 'Headings use sentence case',
				'status' => 'saved',
			),
			House_Style::get_proposal( self::NOTE_ID )
		);
		$this->assertSame( array( 'Headings use sentence case, except proper nouns' ), House_Style::get_prompt_rules( self::USER_ID ) );
	}

	/**
	 * An invalid rule is an error, and the proposal is left as it was.
	 */
	public function test_create_invalid_rule_returns_error(): void {
		$result = $this->controller->create_rule(
			$this->request(
				array(
					'text'    => str_repeat( 'a', House_Style::MAX_RULE_LENGTH + 1 ),
					'note_id' => self::NOTE_ID,
				)
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( '', House_Style::get_proposal( self::NOTE_ID )['status'] ?? null );
	}

	/**
	 * PUT changes a rule and DELETE removes it.
	 */
	public function test_update_and_delete_rule(): void {
		$rule = House_Style::add_rule( House_Style::SCOPE_USER, 'Use sentence case' );
		$this->assertIsArray( $rule );

		$updated = $this->data(
			$this->controller->update_rule(
				$this->request(
					array(
						'id'   => $rule['id'],
						'text' => 'Headings use sentence case',
					)
				)
			)
		);
		$this->assertSame( 'Headings use sentence case', $updated['rule']['text'] );

		$deleted = $this->data( $this->controller->delete_rule( $this->request( array( 'id' => $rule['id'] ) ) ) );
		$this->assertSame( array(), $deleted['user'] );

		$missing = $this->controller->delete_rule( $this->request( array( 'id' => $rule['id'] ) ) );
		$this->assertInstanceOf( \WP_Error::class, $missing );
		$this->assertSame( 404, $missing->get_error_data()['status'] );
	}

	/**
	 * Dismissing a proposal records it, so the thread stops offering it.
	 */
	public function test_dismiss_proposal(): void {
		$data = $this->data( $this->controller->dismiss_proposal( $this->request( array( 'note_id' => self::NOTE_ID ) ) ) );

		$this->assertSame( 'dismissed', $data['proposal']['status'] );
		$this->assertSame( array(), House_Style::get_prompt_rules( self::USER_ID ) );
	}

	/**
	 * A proposal one user saved is still offered to everyone else.
	 */
	public function test_proposal_status_is_per_user(): void {
		House_Style::set_proposal_status( self::NOTE_ID, 'saved' );

		$this->assertSame( 'saved', House_Style::get_proposal( self::NOTE_ID )['status'] ?? null );
		$this->assertSame( '', House_Style::get_proposal( self::NOTE_ID, 8 )['status'] ?? null );
	}

	/**
	 * Dismissing a proposal after saving it keeps it saved.
	 */
	public function test_dismiss_does_not_undo_a_save(): void {
		House_Style::set_proposal_status( self::NOTE_ID, 'saved' );

		$data = $this->data( $this->controller->dismiss_proposal( $this->request( array( 'note_id' => self::NOTE_ID ) ) ) );

		$this->assertSame( 'saved', $data['proposal']['status'] );
	}

	/**
	 * Adding a rule the list already has answers 200 with the existing rule.
	 */
	public function test_create_duplicate_rule_returns_200(): void {
		$first = House_Style::add_rule( House_Style::SCOPE_USER, 'Use sentence case' );
		$this->assertIsArray( $first );

		$response = $this->controller->create_rule( $this->request( array( 'text' => 'use sentence case.' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $first, $this->data( $response )['rule'] );
	}

	/**
	 * A scope other than exactly `site` is never treated as the site scope,
	 * so it needs no more than `edit_posts` and only ever saves a personal
	 * rule. The REST route also rejects it through its `enum`.
	 */
	public function test_unknown_scope_is_never_the_site(): void {
		$this->act_as( array( 'edit_posts' ) );

		foreach ( array( 'SITE', 'network', ' site', '' ) as $index => $scope ) {
			$request = $this->request(
				array(
					'scope' => $scope,
					'text'  => 'Rule ' . $index,
				)
			);
			$this->assertTrue( $this->controller->edit_rules_permissions_check( $request ) );
			$this->controller->create_rule( $request );
		}

		$this->assertSame( array(), House_Style::get_rules( House_Style::SCOPE_SITE ) );
		$this->assertCount( 4, House_Style::get_rules( House_Style::SCOPE_USER ) );
	}
}
