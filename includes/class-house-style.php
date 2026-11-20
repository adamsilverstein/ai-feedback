<?php
/**
 * House style rules
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

use WP_Error;

/**
 * Stores the short style rules that reviews and replies follow.
 *
 * Site rules live in an option and apply to everyone. Personal rules live
 * in the user's preferences and apply to that user's reviews and replies.
 * Rules are user-written text that ends up in prompts, so they are stored
 * as single-line plain text, capped in count and length, and checked again
 * whenever they are read.
 */
class House_Style {

	/**
	 * Option holding the site rules.
	 */
	public const SITE_OPTION = 'ai_feedback_house_style';

	/**
	 * User preference holding a user's personal rules.
	 */
	public const USER_META_KEY = 'ai_feedback_personal_house_style';

	/**
	 * Scope of the site rules.
	 */
	public const SCOPE_SITE = 'site';

	/**
	 * Scope of a user's personal rules.
	 */
	public const SCOPE_USER = 'user';

	/**
	 * Most rules each scope holds.
	 */
	public const MAX_RULES = 20;

	/**
	 * Most characters in a rule.
	 */
	public const MAX_RULE_LENGTH = 150;

	/**
	 * Comment meta holding the rule an AI reply proposes.
	 */
	public const PROPOSAL_META_KEY = 'feedback_house_rule';

	/**
	 * Comment meta recording whether a proposed rule was saved or dismissed.
	 */
	public const PROPOSAL_STATUS_META_KEY = 'feedback_house_rule_status';

	/**
	 * Values of PROPOSAL_STATUS_META_KEY.
	 */
	public const PROPOSAL_STATUSES = array( 'saved', 'dismissed' );

	/**
	 * Make a rule single-line plain text.
	 *
	 * Tags, line breaks and control characters are removed, so a rule can't
	 * add markup or start a new section of a prompt.
	 *
	 * @param  mixed $text Rule text.
	 * @return string Clean text, or an empty string when nothing is left.
	 */
	public static function sanitize_rule( mixed $text ): string {
		if ( ! is_string( $text ) ) {
			return '';
		}

		$text = wp_strip_all_tags( $text );
		$text = (string) preg_replace( '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $text );
		$text = sanitize_text_field( $text );
		$text = (string) preg_replace( '/\s+/u', ' ', $text );

		// A leading list marker would read as another item in the prompt list.
		return trim( (string) preg_replace( '/^[\s\-*•]+/u', '', $text ) );
	}

	/**
	 * Whether a rule is longer than MAX_RULE_LENGTH.
	 *
	 * @param  string $text Sanitized rule text.
	 * @return bool
	 */
	public static function is_too_long( string $text ): bool {
		return mb_strlen( $text ) > self::MAX_RULE_LENGTH;
	}

	/**
	 * Get the rules of a scope.
	 *
	 * @param  string $scope   SCOPE_SITE or SCOPE_USER.
	 * @param  int    $user_id User whose personal rules to read. Defaults to the current user.
	 * @return list<array{id: string, text: string}> Rules, oldest first.
	 */
	public static function get_rules( string $scope, int $user_id = 0 ): array {
		if ( self::SCOPE_SITE === $scope ) {
			$stored = get_option( self::SITE_OPTION, array() );
		} else {
			$user_id = $user_id > 0 ? $user_id : get_current_user_id();
			$stored  = $user_id > 0 ? get_user_meta( $user_id, self::USER_META_KEY, true ) : array();
		}

		return self::normalize( $stored );
	}

	/**
	 * Add a rule.
	 *
	 * Adding a rule the scope already has returns that rule.
	 *
	 * @param  string $scope SCOPE_SITE, or SCOPE_USER for the current user.
	 * @param  mixed  $text  Rule text.
	 * @return array{id: string, text: string}|WP_Error The rule, or an error.
	 */
	public static function add_rule( string $scope, mixed $text ): array|WP_Error {
		$text = self::validate_text( $text );
		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$rules    = self::get_rules( $scope );
		$existing = self::find_by_text( $rules, $text );
		if ( null !== $existing ) {
			return $existing;
		}

		if ( count( $rules ) >= self::MAX_RULES ) {
			return new WP_Error(
				'house_style_full',
				sprintf(
					/* translators: %d: maximum number of rules. */
					__( 'There can be at most %d rules. Remove one to add another.', 'fresh-eyes' ),
					self::MAX_RULES
				),
				array( 'status' => 400 )
			);
		}

		$rule    = array(
			'id'   => wp_generate_uuid4(),
			'text' => $text,
		);
		$rules[] = $rule;
		self::save( $scope, $rules );

		return $rule;
	}

	/**
	 * Change a rule's text.
	 *
	 * @param  string $scope SCOPE_SITE, or SCOPE_USER for the current user.
	 * @param  string $id    Rule ID.
	 * @param  mixed  $text  New text.
	 * @return array{id: string, text: string}|WP_Error The rule, or an error.
	 */
	public static function update_rule( string $scope, string $id, mixed $text ): array|WP_Error {
		$text = self::validate_text( $text );
		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$rules = self::get_rules( $scope );
		$index = self::find_index( $rules, $id );
		if ( null === $index ) {
			return self::not_found();
		}

		$duplicate = self::find_by_text( $rules, $text );
		if ( null !== $duplicate && $duplicate['id'] !== $id ) {
			return new WP_Error(
				'house_style_duplicate',
				__( 'That rule is already in the list.', 'fresh-eyes' ),
				array( 'status' => 400 )
			);
		}

		$rules[ $index ]['text'] = $text;
		self::save( $scope, $rules );

		return $rules[ $index ];
	}

	/**
	 * Remove a rule.
	 *
	 * @param  string $scope SCOPE_SITE, or SCOPE_USER for the current user.
	 * @param  string $id    Rule ID.
	 * @return true|WP_Error True, or an error when there is no such rule.
	 */
	public static function delete_rule( string $scope, string $id ): bool|WP_Error {
		$rules = self::get_rules( $scope );
		$index = self::find_index( $rules, $id );
		if ( null === $index ) {
			return self::not_found();
		}

		array_splice( $rules, $index, 1 );
		self::save( $scope, $rules );

		return true;
	}

	/**
	 * Get the rules a user's reviews and replies follow: the site rules,
	 * then the user's own, without repeats.
	 *
	 * @param  int $user_id User ID. Zero for site rules only.
	 * @return list<string> Rule texts.
	 */
	public static function get_prompt_rules( int $user_id ): array {
		$rules = array_column( self::get_rules( self::SCOPE_SITE ), 'text' );
		if ( $user_id > 0 ) {
			$rules = array_merge( $rules, array_column( self::get_rules( self::SCOPE_USER, $user_id ), 'text' ) );
		}

		/**
		 * Filters the house style rules added to a user's review and reply prompts.
		 *
		 * Returned rules are sanitized and capped like saved ones.
		 *
		 * @param list<string> $rules   Rule texts, site rules first.
		 * @param int          $user_id User the prompt is for.
		 */
		$filtered = apply_filters( 'ai_feedback_house_style_rules', $rules, $user_id );

		return self::clean_list( is_array( $filtered ) ? $filtered : $rules, 2 * self::MAX_RULES );
	}

	/**
	 * Whether a user's reviews already follow a rule with this text.
	 *
	 * @param  string $text    Rule text.
	 * @param  int    $user_id User ID.
	 * @return bool
	 */
	public static function has_rule( string $text, int $user_id ): bool {
		$key = self::compare_key( $text );
		foreach ( self::get_prompt_rules( $user_id ) as $rule ) {
			if ( self::compare_key( $rule ) === $key ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Sanitize a list of rule texts, dropping empty, too long and repeated ones.
	 *
	 * @param  array<mixed> $rules Rule texts.
	 * @param  int          $limit Most rules to keep.
	 * @return list<string>
	 */
	public static function clean_list( array $rules, int $limit ): array {
		$clean = array();
		foreach ( $rules as $rule ) {
			$text = self::sanitize_rule( $rule );
			if ( '' === $text || self::is_too_long( $text ) ) {
				continue;
			}
			$clean[ self::compare_key( $text ) ] = $text;
			if ( count( $clean ) >= $limit ) {
				break;
			}
		}
		return array_values( $clean );
	}

	/**
	 * Get the rule an AI reply proposed, with whether it was acted on.
	 *
	 * @param  int $comment_id AI reply ID.
	 * @return array{text: string, status: string}|null Proposal, or null when there is none.
	 */
	public static function get_proposal( int $comment_id ): ?array {
		$text = self::sanitize_rule( get_comment_meta( $comment_id, self::PROPOSAL_META_KEY, true ) );
		if ( '' === $text || self::is_too_long( $text ) ) {
			return null;
		}

		$status = get_comment_meta( $comment_id, self::PROPOSAL_STATUS_META_KEY, true );

		return array(
			'text'   => $text,
			'status' => in_array( $status, self::PROPOSAL_STATUSES, true ) ? $status : '',
		);
	}

	/**
	 * Record that a proposed rule was saved or dismissed.
	 *
	 * @param  int    $comment_id AI reply ID.
	 * @param  string $status     One of PROPOSAL_STATUSES.
	 */
	public static function set_proposal_status( int $comment_id, string $status ): void {
		if ( in_array( $status, self::PROPOSAL_STATUSES, true ) ) {
			update_comment_meta( $comment_id, self::PROPOSAL_STATUS_META_KEY, $status );
		}
	}

	/**
	 * Register the personal data exporter for personal rules.
	 *
	 * @param  array<string, mixed> $exporters Registered exporters.
	 * @return array<string, mixed>
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters[ self::USER_META_KEY ] = array(
			'exporter_friendly_name' => __( 'Fresh Eyes house style', 'fresh-eyes' ),
			'callback'               => array( self::class, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * Register the personal data eraser for personal rules.
	 *
	 * @param  array<string, mixed> $erasers Registered erasers.
	 * @return array<string, mixed>
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers[ self::USER_META_KEY ] = array(
			'eraser_friendly_name' => __( 'Fresh Eyes house style', 'fresh-eyes' ),
			'callback'             => array( self::class, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * Export a user's personal rules.
	 *
	 * @param  string $email_address User email address.
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public static function export_personal_data( string $email_address ): array {
		$user  = get_user_by( 'email', $email_address );
		$items = array();
		if ( $user ) {
			foreach ( self::get_rules( self::SCOPE_USER, (int) $user->ID ) as $rule ) {
				$items[] = array(
					'group_id'    => 'ai-feedback-house-style',
					'group_label' => __( 'Fresh Eyes house style', 'fresh-eyes' ),
					'item_id'     => 'ai-feedback-house-style-' . $rule['id'],
					'data'        => array(
						array(
							'name'  => __( 'Rule', 'fresh-eyes' ),
							'value' => $rule['text'],
						),
					),
				);
			}
		}

		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Erase a user's personal rules.
	 *
	 * @param  string $email_address User email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public static function erase_personal_data( string $email_address ): array {
		$user    = get_user_by( 'email', $email_address );
		$removed = $user && array() !== self::get_rules( self::SCOPE_USER, (int) $user->ID );
		if ( $removed ) {
			delete_user_meta( (int) $user->ID, self::USER_META_KEY );
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Sanitize rule text and check its length.
	 *
	 * @param  mixed $text Rule text.
	 * @return string|WP_Error Clean text, or an error.
	 */
	private static function validate_text( mixed $text ): string|WP_Error {
		$text = self::sanitize_rule( $text );
		if ( '' === $text ) {
			return new WP_Error(
				'house_style_empty',
				__( 'A rule needs some text.', 'fresh-eyes' ),
				array( 'status' => 400 )
			);
		}
		if ( self::is_too_long( $text ) ) {
			return new WP_Error(
				'house_style_too_long',
				sprintf(
					/* translators: %d: maximum number of characters. */
					__( 'Rules can be at most %d characters.', 'fresh-eyes' ),
					self::MAX_RULE_LENGTH
				),
				array( 'status' => 400 )
			);
		}
		return $text;
	}

	/**
	 * Check stored rules, dropping any that are malformed.
	 *
	 * @param  mixed $stored Stored value.
	 * @return list<array{id: string, text: string}>
	 */
	private static function normalize( mixed $stored ): array {
		$rules = array();
		foreach ( is_array( $stored ) ? $stored : array() as $rule ) {
			if ( ! is_array( $rule ) || ! is_string( $rule['id'] ?? null ) || ! preg_match( '/^[a-z0-9-]{1,64}$/', $rule['id'] ) ) {
				continue;
			}
			$text = self::sanitize_rule( $rule['text'] ?? null );
			if ( '' === $text || self::is_too_long( $text ) ) {
				continue;
			}
			$rules[] = array(
				'id'   => $rule['id'],
				'text' => $text,
			);
			if ( count( $rules ) >= self::MAX_RULES ) {
				break;
			}
		}
		return $rules;
	}

	/**
	 * Save the rules of a scope.
	 *
	 * @param string                                $scope SCOPE_SITE, or SCOPE_USER for the current user.
	 * @param list<array{id: string, text: string}> $rules Rules.
	 */
	private static function save( string $scope, array $rules ): void {
		if ( self::SCOPE_SITE === $scope ) {
			update_option( self::SITE_OPTION, $rules, false );
			return;
		}
		User_Preferences::set( self::USER_META_KEY, $rules );
	}

	/**
	 * Find a rule by text, ignoring case and spacing.
	 *
	 * @param  list<array{id: string, text: string}> $rules Rules.
	 * @param  string                                $text  Text.
	 * @return array{id: string, text: string}|null
	 */
	private static function find_by_text( array $rules, string $text ): ?array {
		$key = self::compare_key( $text );
		foreach ( $rules as $rule ) {
			if ( self::compare_key( $rule['text'] ) === $key ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Find a rule's position by ID.
	 *
	 * @param  list<array{id: string, text: string}> $rules Rules.
	 * @param  string                                $id    Rule ID.
	 * @return int|null
	 */
	private static function find_index( array $rules, string $id ): ?int {
		foreach ( $rules as $index => $rule ) {
			if ( $rule['id'] === $id ) {
				return $index;
			}
		}
		return null;
	}

	/**
	 * Key used to compare rules, ignoring case and trailing punctuation.
	 *
	 * @param  string $text Rule text.
	 * @return string
	 */
	private static function compare_key( string $text ): string {
		return rtrim( mb_strtolower( $text ), " .!\t" );
	}

	/**
	 * Error for a rule ID that is not in the list.
	 *
	 * @return WP_Error
	 */
	private static function not_found(): WP_Error {
		return new WP_Error(
			'house_style_not_found',
			__( 'That rule no longer exists.', 'fresh-eyes' ),
			array( 'status' => 404 )
		);
	}
}
