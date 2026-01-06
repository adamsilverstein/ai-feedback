/**
 * Store selectors.
 */

/**
 * Get settings.
 *
 * @param {Object} state Store state.
 * @return {Object} Settings object.
 */
export function getSettings(state) {
	return state.settings;
}

/**
 * Get available AI models.
 *
 * @param {Object} state Store state.
 * @return {Array} Available models.
 */
export function getAvailableModels(state) {
	return state.availableModels;
}

/**
 * Get selected model.
 *
 * @param {Object} state Store state.
 * @return {string} Selected model ID.
 */
export function getSelectedModel(state) {
	return state.settings.defaultModel;
}

/**
 * Get available focus areas.
 *
 * @param {Object} state Store state.
 * @return {Array} Available focus areas.
 */
export function getAvailableFocusAreas(state) {
	return state.availableFocusAreas;
}

/**
 * Get selected focus areas.
 *
 * @param {Object} state Store state.
 * @return {Array} Selected focus areas.
 */
export function getFocusAreas(state) {
	return state.settings.defaultFocusAreas;
}

/**
 * Get available tones.
 *
 * @param {Object} state Store state.
 * @return {Array} Available tones.
 */
export function getAvailableTones(state) {
	return state.availableTones;
}

/**
 * Get target tone.
 *
 * @param {Object} state Store state.
 * @return {string} Target tone.
 */
export function getTargetTone(state) {
	return state.settings.defaultTone;
}

/**
 * Check if a review is in progress.
 *
 * @param {Object} state Store state.
 * @return {boolean} True if reviewing.
 */
export function isReviewing(state) {
	return state.isReviewing;
}

/**
 * Get the last review.
 *
 * @param {Object} state Store state.
 * @return {Object|null} Last review object or null.
 */
export function getLastReview(state) {
	return state.lastReview;
}

/**
 * Get review history.
 *
 * @param {Object} state Store state.
 * @return {Array} Array of review objects.
 */
export function getReviewHistory(state) {
	return state.reviewHistory;
}

/**
 * Get any error that occurred.
 *
 * @param {Object} state Store state.
 * @return {Error|null} Error object or null.
 */
export function getError(state) {
	return state.error;
}

/**
 * Check if settings are loading.
 *
 * @param {Object} state Store state.
 * @return {boolean} True if loading.
 */
export function isLoadingSettings(state) {
	return state.isLoadingSettings;
}

/**
 * Get unresolved notes count from last review.
 *
 * @param {Object} state Store state.
 * @return {number} Count of unresolved notes.
 */
export function getUnresolvedNotesCount(state) {
	if (!state.lastReview || !state.lastReview.notes) {
		return 0;
	}
	return state.lastReview.notes.filter((note) => !note.resolved).length;
}

/**
 * Check if review has errors.
 *
 * @param {Object} state Store state.
 * @return {boolean} True if there's an error.
 */
export function hasError(state) {
	return state.error !== null;
}

/**
 * Check if previous review is loading.
 *
 * @param {Object} state Store state.
 * @return {boolean} True if loading previous review.
 */
export function isLoadingPreviousReview(state) {
	return state.isLoadingPreviousReview;
}

/**
 * Check if previous review has been fetched.
 *
 * @param {Object} state Store state.
 * @return {boolean} True if previous review fetch has completed.
 */
export function hasFetchedPreviousReview(state) {
	return state.hasFetchedPreviousReview;
}

/**
 * Check if there's a previous review available.
 *
 * @param {Object} state Store state.
 * @return {boolean} True if a previous review exists.
 */
export function hasPreviousReview(state) {
	return state.lastReview !== null;
}
