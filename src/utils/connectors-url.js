/**
 * URL of the WordPress 7.0 Connectors admin screen, where AI provider
 * credentials are configured. Localized from PHP via wp_localize_script;
 * falls back to the default admin path if the global is missing.
 *
 * The fallback assumes the standard `/wp-admin/` location, which can be
 * wrong on installs that customize WP_ADMIN_DIR or use a subdirectory
 * setup. We surface a console warning in that case so the misconfigured
 * environment is easy to spot rather than failing silently.
 *
 * @return {string} Connectors admin URL.
 */
export function getConnectorsUrl() {
	const localized = window.aiFeedbackData?.connectorsUrl;
	if (localized) {
		return localized;
	}

	// eslint-disable-next-line no-console
	console.warn(
		'[AI Feedback] aiFeedbackData.connectorsUrl is missing; falling back to the default admin path.'
	);
	return '/wp-admin/options-general.php?page=connectors';
}
