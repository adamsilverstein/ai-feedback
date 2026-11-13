/**
 * Store selectors.
 */
import { historyReviewKey } from './reducer';

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
 * Get a model's display name.
 *
 * @param {Object} state   Store state.
 * @param {string} modelId Model ID.
 * @return {string} Model name, or the ID when the model is not listed.
 */
export function getModelName(state, modelId) {
	return (
		state.availableModels?.find((model) => model.id === modelId)?.name ||
		modelId
	);
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
 * Get available locales.
 *
 * @param {Object} state Store state.
 * @return {Array} Available locales.
 */
export function getAvailableLocales(state) {
	return state.availableLocales;
}

/**
 * Get feedback locale.
 *
 * @param {Object} state Store state.
 * @return {string} Feedback locale.
 */
export function getFeedbackLocale(state) {
	return state.settings.feedbackLocale;
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
 * Get the current review stage key.
 *
 * @param {Object} state Store state.
 * @return {string|null} Stage key, or null when no review is running.
 */
export function getReviewStage(state) {
	return state.reviewStage;
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

const EMPTY_HISTORY = [];

/**
 * Get a post's review history, newest first.
 *
 * Resolved from the server on first access.
 *
 * @param {Object} state  Store state.
 * @param {number} postId Post ID.
 * @return {Array} History entries.
 */
export function getReviewHistory(state, postId) {
	return state.reviewHistories[postId] || EMPTY_HISTORY;
}

/**
 * Get a past review with its notes.
 *
 * Resolved from the server on first access.
 *
 * @param {Object} state    Store state.
 * @param {number} postId   Post ID.
 * @param {string} reviewId Review ID.
 * @return {Object|null|undefined} Review, null if not found, undefined while loading.
 */
export function getHistoryReview(state, postId, reviewId) {
	return state.historyReviews[historyReviewKey(postId, reviewId)];
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
	return state.lastReview.notes.filter((note) => !note.is_resolved).length;
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

/**
 * Get how many reviews in a row failed because the AI service was down.
 *
 * @param {Object} state Store state.
 * @return {number} Consecutive unavailable failures.
 */
export function getUnavailableRetries(state) {
	return state.unavailableRetries;
}

/**
 * Get progress of a review split into several requests.
 *
 * @param {Object} state Store state.
 * @return {Object|null} `{ current, total }`, or null for a single-request review.
 */
export function getReviewProgress(state) {
	return state.reviewProgress;
}

/**
 * Get the number of blocks reviewed per request.
 *
 * @param {Object} state Store state.
 * @return {number} Chunk size.
 */
export function getChunkSize(state) {
	return state.settings.chunkSize;
}

/**
 * Whether a note's quick fix was applied in this session.
 *
 * @param {Object} state  Store state.
 * @param {number} noteId Note ID.
 * @return {boolean} True if the fix was applied.
 */
export function isFixApplied(state, noteId) {
	return !!state.appliedFixes?.[noteId];
}
