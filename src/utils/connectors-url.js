/**
 * URL of the WordPress 7.0 Connectors admin screen, where AI provider
 * credentials are configured. Localized from PHP via wp_localize_script;
 * falls back to the default admin path if the global is missing.
 *
 * @return {string} Connectors admin URL.
 */
export function getConnectorsUrl() {
	const localized = window.aiFeedbackData?.connectorsUrl;
	return localized || '/wp-admin/options-general.php?page=connectors';
}
