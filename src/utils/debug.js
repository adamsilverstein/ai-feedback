/**
 * Debug logging, enabled with `localStorage.setItem( 'AI_FEEDBACK_DEBUG', 'true' )`.
 */

/**
 * Whether debug logging is on.
 *
 * @return {boolean} True when the AI_FEEDBACK_DEBUG flag is set.
 */
export function isDebugEnabled() {
	try {
		return window.localStorage.getItem('AI_FEEDBACK_DEBUG') === 'true';
	} catch {
		return false;
	}
}

/**
 * Log to the console when debug logging is on.
 *
 * @param {...unknown} args Values to log.
 */
export function debugLog(...args) {
	if (isDebugEnabled()) {
		// eslint-disable-next-line no-console
		console.debug('[AI-Feedback]', ...args);
	}
}
