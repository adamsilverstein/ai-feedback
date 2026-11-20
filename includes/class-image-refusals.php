<?php
/**
 * Image Refusals
 *
 * Remembers, for a while, which models refused a review request with
 * images, so the next review doesn't send the same failing request.
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

use WP_Error;

/**
 * Remembers image refusals per provider and model.
 *
 * Every refusal is kept in one transient, a map of `provider|model` to the
 * time it expires, so all of them can be cleared at once when the model or
 * a provider's connector settings change.
 */
class Image_Refusals {

	/**
	 * Transient holding the refusals.
	 */
	public const TRANSIENT = 'ai_feedback_image_refusals';

	/**
	 * Default time a refusal is remembered, in seconds.
	 */
	public const DEFAULT_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Longest time a refusal may be remembered, in seconds, whatever the
	 * filter returns.
	 */
	public const MAX_TTL = HOUR_IN_SECONDS;

	/**
	 * Whether a model refused images recently.
	 *
	 * @param  string $provider Provider ID, or '' when unknown.
	 * @param  string $model    Model ID.
	 * @return bool
	 */
	public static function is_refused( string $provider, string $model ): bool {
		$expires = self::get_all()[ self::key( $provider, $model ) ] ?? 0;
		return $expires > time();
	}

	/**
	 * Remember that a model refused images.
	 *
	 * @param string $provider Provider ID, or '' when unknown.
	 * @param string $model    Model ID.
	 */
	public static function remember( string $provider, string $model ): void {
		/**
		 * Filters how long a model's refusal of images is remembered, in
		 * seconds. Return 0 to never remember one. At most an hour.
		 *
		 * @param int    $ttl      Seconds. Default 15 minutes.
		 * @param string $provider Provider ID, or '' when unknown.
		 * @param string $model    Model ID.
		 */
		$ttl = min( self::MAX_TTL, (int) apply_filters( 'ai_feedback_image_refusal_ttl', self::DEFAULT_TTL, $provider, $model ) );
		if ( $ttl <= 0 ) {
			return;
		}

		$refusals                                   = self::get_all();
		$refusals[ self::key( $provider, $model ) ] = time() + $ttl;
		// The transient lasts as long as its longest refusal, never forever.
		set_transient( self::TRANSIENT, $refusals, max( $refusals ) - time() );
	}

	/**
	 * Forget every refusal.
	 */
	public static function clear(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Whether a failed request with images means the model doesn't take
	 * images at all, so later reviews, by any user, shouldn't send them.
	 *
	 * The memory is site-wide, so only an error that can't come from one
	 * author's image counts: core finding no configured model for the
	 * prompt (`prompt_invalid_argument`, "No models found ..."). Only called
	 * once the same review without images succeeded, so with the text alone
	 * working this means the model's metadata takes no images.
	 *
	 * Never remembered: a 400, 415 or 422, even one naming images, since at
	 * some providers each can be about one file (corrupt, too large, an
	 * unsupported format, too many); a 413; and every other error. Those
	 * cost only that review's proposals.
	 *
	 * @param  WP_Error $error Error from the request with images.
	 * @return bool
	 */
	public static function is_lasting_refusal( WP_Error $error ): bool {
		$code    = (string) $error->get_error_code();
		$message = (string) $error->get_error_message();

		$lasting = 'prompt_invalid_argument' === $code && str_starts_with( $message, 'No models found' );

		/**
		 * Filters whether a refused request with images is remembered, so
		 * the model is not sent images for a while.
		 *
		 * @param bool     $lasting Whether the refusal is remembered.
		 * @param WP_Error $error   Error from the request with images.
		 */
		return true === apply_filters( 'ai_feedback_remember_image_refusal', $lasting, $error );
	}

	/**
	 * Clear refusals when the site's model or a connector's settings change,
	 * eg. a new API key.
	 *
	 * @param string $option Option name.
	 */
	public static function clear_on_option_change( string $option ): void {
		if ( 'ai_feedback_default_model' === $option || 1 === preg_match( '/^connectors_ai_.+_api_key$/', $option ) ) {
			self::clear();
		}
	}

	/**
	 * Clear refusals when a user picks another model. Every refusal is
	 * cleared, not only that user's model: the worst case is one more
	 * failed call.
	 *
	 * @param int|array<int> $meta_id   Meta ID, or IDs when deleted.
	 * @param int            $object_id User ID.
	 * @param string         $meta_key  Meta key.
	 */
	public static function clear_on_meta_change( int|array $meta_id, int $object_id, string $meta_key ): void {
		if ( 'ai_feedback_default_model' === $meta_key ) {
			self::clear();
		}
	}

	/**
	 * Hook the clearing of refusals to settings changes.
	 */
	public static function register(): void {
		foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $hook ) {
			add_action( $hook, array( self::class, 'clear_on_option_change' ) );
		}
		foreach ( array( 'added_user_meta', 'updated_user_meta', 'deleted_user_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'clear_on_meta_change' ), 10, 3 );
		}
	}

	/**
	 * Refusals that haven't expired, keyed by `provider|model`.
	 *
	 * @return array<string, int> Expiry times.
	 */
	private static function get_all(): array {
		$stored = get_transient( self::TRANSIENT );
		$now    = time();
		$valid  = array();
		foreach ( is_array( $stored ) ? $stored : array() as $key => $expires ) {
			if ( is_string( $key ) && is_int( $expires ) && $expires > $now ) {
				$valid[ $key ] = $expires;
			}
		}
		return $valid;
	}

	/**
	 * Key for a provider and model.
	 *
	 * @param  string $provider Provider ID.
	 * @param  string $model    Model ID.
	 * @return string Key.
	 */
	private static function key( string $provider, string $model ): string {
		return $provider . '|' . $model;
	}
}
