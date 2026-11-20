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
 * in user meta and apply to that user's reviews and replies. Both belong to
 * one site: on multisite, personal rules are kept under a site-prefixed meta
 * key (as `update_user_option()` does), since a house style belongs to a
 * publication. That differs from the other User_Preferences, which follow
 * the user across the network.
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
	 * User meta holding a user's personal rules, after the site's table
	 * prefix. See user_meta_key().
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
	 * Comment meta recording, per user, whether a proposed rule was saved or
	 * dismissed: user ID => status.
	 */
	public const PROPOSAL_STATUS_META_KEY = 'feedback_house_rule_status';

	/**
	 * Values of PROPOSAL_STATUS_META_KEY.
	 */
	public const PROPOSAL_STATUSES = array( 'saved', 'dismissed' );

	/**
	 * Emoji, skin tone modifiers and the emoji presentation selector, as
	 * regex class contents, for keeping zero-width joiners between emoji.
	 */
	private const EMOJI_CHARS = '\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}';

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
		// A zero-width joiner only stays between two emoji, where it joins
		// them into one (a family, a profession); anywhere else it goes with
		// the other invisible characters.
		$emoji = '[' . self::EMOJI_CHARS . ']';
		$text  = (string) preg_replace( '/(?<!' . $emoji . ')\x{200D}|\x{200D}(?!' . $emoji . ')/u', ' ', $text );
		$text  = (string) preg_replace( '/(?:(?!\x{200D})[\p{Cc}\p{Cf}\p{Zl}\p{Zp}])+/u', ' ', $text );
		$text  = sanitize_text_field( $text );
		$text  = (string) preg_replace( '/\s+/u', ' ', $text );

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
			$stored  = $user_id > 0 ? get_user_meta( $user_id, self::user_meta_key(), true ) : array();
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

		$rule            = array(
			'id'   => $id,
			'text' => $text,
		);
		$rules[ $index ] = $rule;
		self::save( $scope, $rules );

		return $rule;
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

		return self::clean_list( $filtered, 2 * self::MAX_RULES );
	}

	/**
	 * Whether a user's reviews already follow a rule with this text.
	 *
	 * @param  string $text    Rule text.
	 * @param  int    $user_id User ID.
	 * @return bool
	 */
	public static function has_rule( string $text, int $user_id ): bool {
		return self::list_has_rule( self::get_prompt_rules( $user_id ), $text );
	}

	/**
	 * Whether a list of rule texts has one matching this text.
	 *
	 * @param  mixed  $rules Rule texts, such as get_prompt_rules() returns.
	 * @param  string $text  Rule text.
	 * @return bool
	 */
	public static function list_has_rule( mixed $rules, string $text ): bool {
		$key = self::compare_key( $text );
		foreach ( is_array( $rules ) ? $rules : array() as $rule ) {
			if ( is_string( $rule ) && self::compare_key( $rule ) === $key ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Sanitize a list of rule texts, dropping empty, too long and repeated ones.
	 *
	 * @param  mixed $rules Rule texts. Anything but an array gives no rules.
	 * @param  int   $limit Most rules to keep.
	 * @return list<string>
	 */
	public static function clean_list( mixed $rules, int $limit ): array {
		$clean = array();
		foreach ( is_array( $rules ) ? $rules : array() as $rule ) {
			$text = self::sanitize_rule( $rule );
			if ( '' === $text || self::is_too_long( $text ) ) {
				continue;
			}
			$key = self::compare_key( $text );
			if ( ! isset( $clean[ $key ] ) ) {
				$clean[ $key ] = $text;
			}
			if ( count( $clean ) >= $limit ) {
				break;
			}
		}
		return array_values( $clean );
	}

	/**
	 * Get the rule an AI reply proposed, with what a user did about it.
	 *
	 * Each user who can see the thread decides for themselves, since a
	 * personal rule one user saves does not apply to anyone else.
	 *
	 * @param  int $comment_id AI reply ID.
	 * @param  int $user_id    User. Defaults to the current user.
	 * @return array{text: string, status: string}|null Proposal, or null when there is none.
	 */
	public static function get_proposal( int $comment_id, int $user_id = 0 ): ?array {
		$text = self::sanitize_rule( get_comment_meta( $comment_id, self::PROPOSAL_META_KEY, true ) );
		if ( '' === $text || self::is_too_long( $text ) ) {
			return null;
		}

		$user_id  = $user_id > 0 ? $user_id : get_current_user_id();
		$statuses = self::get_proposal_statuses( $comment_id );

		return array(
			'text'   => $text,
			'status' => $statuses[ $user_id ] ?? '',
		);
	}

	/**
	 * Record that a user saved or dismissed a proposed rule.
	 *
	 * Dismissing never undoes a save.
	 *
	 * @param  int    $comment_id AI reply ID.
	 * @param  string $status     One of PROPOSAL_STATUSES.
	 * @param  int    $user_id    User. Defaults to the current user.
	 */
	public static function set_proposal_status( int $comment_id, string $status, int $user_id = 0 ): void {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		if ( $user_id <= 0 || ! in_array( $status, self::PROPOSAL_STATUSES, true ) ) {
			return;
		}

		$statuses = self::get_proposal_statuses( $comment_id );
		if ( 'dismissed' === $status && 'saved' === ( $statuses[ $user_id ] ?? '' ) ) {
			return;
		}

		$statuses[ $user_id ] = $status;
		update_comment_meta( $comment_id, self::PROPOSAL_STATUS_META_KEY, wp_slash( $statuses ) );
	}

	/**
	 * Read the stored proposal statuses, dropping malformed entries.
	 *
	 * @param  int $comment_id AI reply ID.
	 * @return array<int, string> Status by user ID.
	 */
	private static function get_proposal_statuses( int $comment_id ): array {
		$stored   = get_comment_meta( $comment_id, self::PROPOSAL_STATUS_META_KEY, true );
		$statuses = array();
		foreach ( is_array( $stored ) ? $stored : array() as $user_id => $status ) {
			if ( is_int( $user_id ) && $user_id > 0 && in_array( $status, self::PROPOSAL_STATUSES, true ) ) {
				$statuses[ $user_id ] = $status;
			}
		}
		return $statuses;
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
			foreach ( self::get_user_rule_keys( (int) $user->ID ) as $key => $site_name ) {
				foreach ( self::normalize( get_user_meta( (int) $user->ID, $key, true ) ) as $rule ) {
					$items[] = array(
						'group_id'    => 'ai-feedback-house-style',
						'group_label' => __( 'Fresh Eyes house style', 'fresh-eyes' ),
						'item_id'     => 'ai-feedback-house-style-' . $key . '-' . $rule['id'],
						'data'        => array(
							array(
								'name'  => __( 'Site', 'fresh-eyes' ),
								'value' => $site_name,
							),
							array(
								'name'  => __( 'Rule', 'fresh-eyes' ),
								'value' => $rule['text'],
							),
						),
					);
				}
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
		$removed = false;
		if ( $user ) {
			foreach ( array_keys( self::get_user_rule_keys( (int) $user->ID ) ) as $key ) {
				if ( array() !== self::normalize( get_user_meta( (int) $user->ID, $key, true ) ) ) {
					$removed = true;
				}
				delete_user_meta( (int) $user->ID, $key );
			}
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * User meta key of personal rules on a site: the site's table prefix,
	 * then USER_META_KEY, as `update_user_option()` stores per-site options.
	 *
	 * @param  int|null $site_id Site ID. Defaults to the current site.
	 * @return string Meta key.
	 */
	public static function user_meta_key( ?int $site_id = null ): string {
		global $wpdb;
		$prefix = is_object( $wpdb ) && method_exists( $wpdb, 'get_blog_prefix' ) ? $wpdb->get_blog_prefix( $site_id ) : '';
		return ( is_string( $prefix ) ? $prefix : '' ) . self::USER_META_KEY;
	}

	/**
	 * Every user meta key holding the user's personal rules, for the privacy
	 * tools, with the site each belongs to.
	 *
	 * Found from the user's own meta rather than their site memberships, so
	 * rules on a site they have left, or one that is archived or deleted,
	 * are still exported and erased. Includes an unprefixed key, as saved
	 * before rules were per site.
	 *
	 * @param  int $user_id User ID.
	 * @return array<string, string> Site name by meta key; empty when the site is unknown.
	 */
	private static function get_user_rule_keys( int $user_id ): array {
		global $wpdb;
		$base    = is_object( $wpdb ) && isset( $wpdb->base_prefix ) && is_string( $wpdb->base_prefix ) ? $wpdb->base_prefix : '';
		$pattern = '/^(?:' . preg_quote( $base, '/' ) . '(?:(\d+)_)?)?' . preg_quote( self::USER_META_KEY, '/' ) . '$/';

		$keys = array();
		$meta = get_user_meta( $user_id );
		foreach ( array_keys( is_array( $meta ) ? $meta : array() ) as $key ) {
			if ( ! is_string( $key ) || ! preg_match( $pattern, $key, $match ) ) {
				continue;
			}
			$is_legacy    = self::USER_META_KEY === $key && '' !== $base;
			$site_id      = isset( $match[1] ) && '' !== $match[1] ? (int) $match[1] : ( $is_legacy ? 0 : ( function_exists( 'get_main_site_id' ) ? get_main_site_id() : 1 ) );
			$keys[ $key ] = $site_id > 0 ? self::site_name( $site_id ) : '';
		}
		return $keys;
	}

	/**
	 * A site's name, for the export.
	 *
	 * @param  int $site_id Site ID.
	 * @return string Name, or an empty string when it can't be found.
	 */
	private static function site_name( int $site_id ): string {
		$name = function_exists( 'get_blog_option' )
			? get_blog_option( $site_id, 'blogname' )
			: ( get_current_blog_id() === $site_id ? get_bloginfo( 'name' ) : '' );
		return is_string( $name ) ? $name : '';
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
		$rules   = array();
		$dropped = 0;
		foreach ( is_array( $stored ) ? $stored : array() as $rule ) {
			if ( ! is_array( $rule ) || ! is_string( $rule['id'] ?? null ) || ! preg_match( '/^[a-z0-9-]{1,64}$/', $rule['id'] ) ) {
				++$dropped;
				continue;
			}
			$text = self::sanitize_rule( $rule['text'] ?? null );
			if ( '' === $text || self::is_too_long( $text ) || count( $rules ) >= self::MAX_RULES ) {
				++$dropped;
				continue;
			}
			$rules[] = array(
				'id'   => $rule['id'],
				'text' => $text,
			);
		}

		if ( $dropped > 0 ) {
			Logger::debug( sprintf( 'House_Style: ignored %d stored rules that were malformed, too long or over the limit.', $dropped ) );
		}
		return $rules;
	}

	/**
	 * Save the rules of a scope.
	 *
	 * The whole list is written back, so of two changes saved at the same
	 * moment the later wins. Lists are short and edited by hand, which keeps
	 * that unlikely.
	 *
	 * @param string                                $scope SCOPE_SITE, or SCOPE_USER for the current user.
	 * @param list<array{id: string, text: string}> $rules Rules.
	 */
	private static function save( string $scope, array $rules ): void {
		if ( self::SCOPE_SITE === $scope ) {
			update_option( self::SITE_OPTION, $rules, false );
			return;
		}
		// update_user_meta() unslashes, which would drop backslashes from rules.
		update_user_meta( get_current_user_id(), self::user_meta_key(), wp_slash( $rules ) );
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
	 * Key used to compare rules, ignoring case and closing punctuation.
	 *
	 * @param  string $text Rule text.
	 * @return string
	 */
	private static function compare_key( string $text ): string {
		return rtrim( mb_strtolower( $text ), " .!?;:,\t" );
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
