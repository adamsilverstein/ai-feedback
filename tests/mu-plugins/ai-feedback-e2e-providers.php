<?php
/**
 * Plugin Name: AI Feedback E2E Fake Providers
 * Description: Two fake AI providers in the real core AI client, for model pinning tests. Only loaded by wp-env.
 *
 * Requests that carry both the `ai_feedback_e2e` cookie and the
 * `ai_feedback_e2e_providers` cookie skip the plugin's mock mode and send
 * reviews through the core AI client to two fake providers registered here:
 * "anthropic", which the default model belongs to, and "e2eother". The
 * cookie's value picks what "anthropic" lists:
 *
 * - `missing`: only another model, while "e2eother" lists the default model.
 * - `listed`:  the default model.
 *
 * Loads before ai-feedback-e2e.php, so its mock mode stays off.
 *
 * @package AI_Feedback
 */

declare( strict_types=1 );

$ai_feedback_e2e_listing = isset( $_COOKIE['ai_feedback_e2e_providers'] ) ? sanitize_key( wp_unslash( $_COOKIE['ai_feedback_e2e_providers'] ) ) : '';
if (
	empty( $_COOKIE['ai_feedback_e2e'] )
	|| ! in_array( $ai_feedback_e2e_listing, array( 'missing', 'listed' ), true )
	|| 'local' !== wp_get_environment_type()
) {
	return;
}

define( 'AI_FEEDBACK_MOCK_MODE', false );
define( 'AI_FEEDBACK_E2E_PROVIDERS', $ai_feedback_e2e_listing );

add_action(
	'init',
	static function (): void {
		if ( ! class_exists( \WordPress\AiClient\AiClient::class ) ) {
			return;
		}
		require_once __DIR__ . '/ai-feedback-e2e/fake-providers.php';
		$registry = \WordPress\AiClient\AiClient::defaultRegistry();
		// The other provider first: core falls back to the first one it finds.
		foreach ( array( AI_Feedback_E2E_Other_Provider::class, AI_Feedback_E2E_Anthropic_Provider::class ) as $provider ) {
			if ( ! $registry->hasProvider( $provider::metadata()->getId() ) ) {
				$registry->registerProvider( $provider );
			}
		}
	},
	0
);
