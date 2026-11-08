/**
 * Store reducer.
 */

/**
 * Initial state.
 */
export const initialState = {
	settings: {
		defaultModel: 'claude-sonnet-4',
		defaultFocusAreas: ['content', 'tone', 'flow'],
		defaultTone: 'professional',
		feedbackLocale: 'auto',
	},
	availableModels: [],
	availableFocusAreas: [],
	availableTones: [],
	availableLocales: [],
	isReviewing: false,
	lastReview: null,
	reviewHistories: {},
	historyReviews: {},
	error: null,
	isLoadingSettings: false,
	isLoadingPreviousReview: false,
	hasFetchedPreviousReview: false,
};

/**
 * Action types.
 */
export const TYPES = {
	SET_SETTINGS: 'SET_SETTINGS',
	UPDATE_SETTINGS: 'UPDATE_SETTINGS',
	SET_AVAILABLE_MODELS: 'SET_AVAILABLE_MODELS',
	SET_AVAILABLE_FOCUS_AREAS: 'SET_AVAILABLE_FOCUS_AREAS',
	SET_AVAILABLE_TONES: 'SET_AVAILABLE_TONES',
	SET_AVAILABLE_LOCALES: 'SET_AVAILABLE_LOCALES',
	START_REVIEW: 'START_REVIEW',
	REVIEW_SUCCESS: 'REVIEW_SUCCESS',
	REVIEW_ERROR: 'REVIEW_ERROR',
	ADD_TO_HISTORY: 'ADD_TO_HISTORY',
	CLEAR_ERROR: 'CLEAR_ERROR',
	SET_LOADING_SETTINGS: 'SET_LOADING_SETTINGS',
	UPDATE_BLOCK_NOTES: 'UPDATE_BLOCK_NOTES',
	FETCHING_PREVIOUS_REVIEW: 'FETCHING_PREVIOUS_REVIEW',
	RECEIVE_PREVIOUS_REVIEW: 'RECEIVE_PREVIOUS_REVIEW',
	RECEIVE_REVIEW_HISTORY: 'RECEIVE_REVIEW_HISTORY',
	RECEIVE_HISTORY_REVIEW: 'RECEIVE_HISTORY_REVIEW',
};

/**
 * Maximum history entries kept, matching Review_History::MAX_ENTRIES.
 */
export const MAX_HISTORY_ENTRIES = 10;

/**
 * Reduce a review response to a history entry.
 *
 * @param {Object} review Review response from the API.
 * @return {Object} History entry.
 */
export function toHistoryEntry(review) {
	return {
		review_id: review.review_id,
		timestamp: review.timestamp,
		model: review.model,
		note_count: review.note_count ?? 0,
		notes_failed: !!review.notes_error,
		summary_text: review.summary_text || '',
		summary: {
			by_severity: review.summary?.by_severity || {},
			by_category: review.summary?.by_category || {},
		},
	};
}

/**
 * Merge history entries, newest first, without duplicates and capped.
 *
 * @param {Array} entries Entries in priority order; the first copy of a review wins.
 * @return {Array} Merged history.
 */
export function mergeHistory(entries) {
	const seen = new Set();
	return entries
		.filter((entry) => {
			if (!entry?.review_id || seen.has(entry.review_id)) {
				return false;
			}
			seen.add(entry.review_id);
			return true;
		})
		.sort((a, b) => (b.timestamp || '').localeCompare(a.timestamp || ''))
		.slice(0, MAX_HISTORY_ENTRIES);
}

/**
 * Add a review to its post's history.
 *
 * @param {Object} histories History entries keyed by post ID.
 * @param {Object} review    Review response.
 * @return {Object} New histories.
 */
function addReviewToHistories(histories, review) {
	if (!review?.post_id) {
		return histories;
	}
	return {
		...histories,
		[review.post_id]: mergeHistory([
			toHistoryEntry(review),
			...(histories[review.post_id] || []),
		]),
	};
}

/**
 * Cache key for a past review.
 *
 * @param {number} postId   Post ID.
 * @param {string} reviewId Review ID.
 * @return {string} Key.
 */
export function historyReviewKey(postId, reviewId) {
	return `${postId}:${reviewId}`;
}

/**
 * Reducer function.
 *
 * @param {Object} state  Current state.
 * @param {Object} action Action object.
 * @return {Object} New state.
 */
export default function reducer(state = initialState, action) {
	switch (action.type) {
		case TYPES.SET_SETTINGS:
			return {
				...state,
				settings: {
					defaultModel: action.settings.default_model,
					defaultFocusAreas: action.settings.default_focus_areas,
					defaultTone: action.settings.default_tone,
					feedbackLocale: action.settings.feedback_locale || 'auto',
				},
				availableModels: action.settings.available_models || [],
				availableFocusAreas:
					action.settings.available_focus_areas || [],
				availableTones: action.settings.available_tones || [],
				availableLocales: action.settings.available_locales || [],
				isLoadingSettings: false,
			};

		case TYPES.UPDATE_SETTINGS:
			return {
				...state,
				settings: {
					...state.settings,
					...action.settings,
				},
			};

		case TYPES.SET_AVAILABLE_MODELS:
			return {
				...state,
				availableModels: action.models,
			};

		case TYPES.SET_AVAILABLE_FOCUS_AREAS:
			return {
				...state,
				availableFocusAreas: action.areas,
			};

		case TYPES.SET_AVAILABLE_TONES:
			return {
				...state,
				availableTones: action.tones,
			};

		case TYPES.SET_AVAILABLE_LOCALES:
			return {
				...state,
				availableLocales: action.locales,
			};

		case TYPES.START_REVIEW:
			return {
				...state,
				isReviewing: true,
				error: null,
			};

		case TYPES.REVIEW_SUCCESS:
			return {
				...state,
				isReviewing: false,
				lastReview: action.review,
				reviewHistories: addReviewToHistories(
					state.reviewHistories,
					action.review
				),
			};

		case TYPES.REVIEW_ERROR:
			return {
				...state,
				isReviewing: false,
				error: action.error,
			};

		case TYPES.ADD_TO_HISTORY:
			return {
				...state,
				reviewHistories: addReviewToHistories(
					state.reviewHistories,
					action.review
				),
			};

		case TYPES.RECEIVE_REVIEW_HISTORY:
			// Keep entries added this session in case they finished before
			// the fetch returned.
			return {
				...state,
				reviewHistories: {
					...state.reviewHistories,
					[action.postId]: mergeHistory([
						...(state.reviewHistories[action.postId] || []),
						...(action.history || []),
					]),
				},
			};

		case TYPES.RECEIVE_HISTORY_REVIEW:
			return {
				...state,
				historyReviews: {
					...state.historyReviews,
					[historyReviewKey(action.postId, action.reviewId)]:
						action.review,
				},
			};

		case TYPES.CLEAR_ERROR:
			return {
				...state,
				error: null,
			};

		case TYPES.SET_LOADING_SETTINGS:
			return {
				...state,
				isLoadingSettings: action.isLoading,
			};

		case TYPES.FETCHING_PREVIOUS_REVIEW:
			return {
				...state,
				isLoadingPreviousReview: true,
			};

		case TYPES.RECEIVE_PREVIOUS_REVIEW:
			// Only update lastReview if we don't already have one from the current session.
			// This prevents overwriting a fresh review with stale data.
			if (state.lastReview && !state.lastReview.is_persisted) {
				return {
					...state,
					isLoadingPreviousReview: false,
					hasFetchedPreviousReview: true,
				};
			}
			return {
				...state,
				isLoadingPreviousReview: false,
				hasFetchedPreviousReview: true,
				lastReview: action.review,
			};

		default:
			return state;
	}
}
