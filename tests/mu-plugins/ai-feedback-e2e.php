<?php
/**
 * Plugin Name: AI Feedback E2E Helpers
 * Description: Lets Playwright run reviews without a real AI provider. Only loaded by wp-env.
 *
 * Requests that carry the `ai_feedback_e2e` cookie get the plugin's built-in
 * mock AI response and skip the per-user review rate limit. Requests without
 * the cookie, such as manual testing in the same environment, and any site
 * that is not a local environment are untouched.
 *
 * @package AI_Feedback
 */

declare( strict_types=1 );

if ( empty( $_COOKIE['ai_feedback_e2e'] ) || 'local' !== wp_get_environment_type() ) {
	return;
}

if ( ! defined( 'AI_FEEDBACK_MOCK_MODE' ) ) {
	define( 'AI_FEEDBACK_MOCK_MODE', true );
}

add_filter(
	'rest_pre_dispatch',
	static function ( mixed $result, WP_REST_Server $server, WP_REST_Request $request ): mixed {
		$user_id = get_current_user_id();
		if ( $user_id && '/ai-feedback/v1/review' === $request->get_route() ) {
			delete_user_meta( $user_id, 'ai_feedback_reviews' );
		}
		return $result;
	},
	10,
	3
);
