<?php
/**
 * Per-user review preferences
 *
 * @package AI_Feedback
 */

namespace AI_Feedback;

/**
 * Reads and saves the review preferences a user picks in the editor sidebar.
 *
 * Each preference is user meta stored under the name of the site option
 * that holds its site-wide default.
 */
class User_Preferences {

	/**
	 * Gets a preference: the user's own value, else the site default.
	 *
	 * @param  string   $key           Option name, also used as the user meta key.
	 * @param  mixed    $default_value Value when neither the user nor the site has one.
	 * @param  int|null $user_id       User ID. Defaults to the current user.
	 * @return mixed Preference value.
	 */
	public static function get( string $key, mixed $default_value, ?int $user_id = null ): mixed {
		$user_id = $user_id ?? get_current_user_id();

		if ( $user_id > 0 ) {
			$value = get_user_meta( $user_id, $key, true );
			// Unset meta reads as an empty string.
			if ( '' !== $value ) {
				return $value;
			}
		}

		return get_option( $key, $default_value );
	}

	/**
	 * Saves a preference for the current user.
	 *
	 * @param string $key   Option name, also used as the user meta key.
	 * @param mixed  $value Preference value.
	 */
	public static function set( string $key, mixed $value ): void {
		update_user_meta( get_current_user_id(), $key, $value );
	}
}
