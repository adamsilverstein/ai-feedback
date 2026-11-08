/**
 * HTTP statuses that mean the AI provider rejected the site's credentials
 * or account, which the user fixes on the Connectors screen.
 */
const CREDENTIAL_STATUSES = [401, 402, 403];

const CREDENTIAL_PHRASES = ['credit', 'billing', 'api key', 'quota'];

/**
 * Classify a review error so the panel can offer the right next step.
 *
 * Core AI Client errors carry the provider's HTTP status in `data.status`
 * under codes such as `prompt_client_error`, so match on status and message
 * rather than on a specific code.
 *
 * @param {Object} error Error object with code, message, and data.
 * @return {'credentials'|'rate_limit'|'unavailable'|null} Error kind, or null when unknown.
 */
export function getErrorKind(error) {
	const status = Number(error?.data?.status);
	const message =
		typeof error?.message === 'string' ? error.message.toLowerCase() : '';

	if (
		CREDENTIAL_STATUSES.includes(status) ||
		CREDENTIAL_PHRASES.some((phrase) => message.includes(phrase))
	) {
		return 'credentials';
	}

	if (error?.code === 'ai_service_unavailable') {
		return 'unavailable';
	}

	if (error?.code === 'rate_limit_exceeded' || status === 429) {
		return 'rate_limit';
	}

	return null;
}
