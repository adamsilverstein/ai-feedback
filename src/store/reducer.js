/**
 * Store reducer.
 */
import { STAGE_PREPARING } from './review-stages';

/**
 * Initial state.
 */
export const initialState = {
	settings: {
		defaultModel: 'claude-sonnet-5-5',
		defaultFocusAreas: ['content', 'tone', 'flow'],
		defaultTone: 'professional',
		feedbackLocale: 'auto',
		chunkSize: 20,
	},
	availableModels: [],
	availableFocusAreas: [],
	availableTones: [],
	availableLocales: [],
	isReviewing: false,
	reviewStage: null,
	unavailableRetries: 0,
	reviewProgress: null,
	lastReview: null,
	reviewHistories: {},
	historyReviews: {},
	error: null,
	isLoadingSettings: false,
	isLoadingPreviousReview: false,
	hasFetchedPreviousReview: false,
	appliedFixes: {},
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
	RECEIVE_PARTIAL_REVIEW: 'RECEIVE_PARTIAL_REVIEW',
	REVIEW_SUCCESS: 'REVIEW_SUCCESS',
	REVIEW_ERROR: 'REVIEW_ERROR',
	ADD_TO_HISTORY: 'ADD_TO_HISTORY',
	CLEAR_ERROR: 'CLEAR_ERROR',
	SET_LOADING_SETTINGS: 'SET_LOADING_SETTINGS',
	UPDATE_BLOCK_NOTES: 'UPDATE_BLOCK_NOTES',
	FETCHING_PREVIOUS_REVIEW: 'FETCHING_PREVIOUS_REVIEW',
	RECEIVE_PREVIOUS_REVIEW: 'RECEIVE_PREVIOUS_REVIEW',
	SET_REVIEW_STAGE: 'SET_REVIEW_STAGE',
	SET_REVIEW_PROGRESS: 'SET_REVIEW_PROGRESS',
	RECEIVE_REVIEW_HISTORY: 'RECEIVE_REVIEW_HISTORY',
	RECEIVE_HISTORY_REVIEW: 'RECEIVE_HISTORY_REVIEW',
	SET_NOTE_RESOLVED: 'SET_NOTE_RESOLVED',
	SET_FIX_APPLIED: 'SET_FIX_APPLIED',
	SET_SUGGESTION_STATUS: 'SET_SUGGESTION_STATUS',
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
 * Keep note dismissals made while a multi-part review was still running.
 *
 * Each part replaces the review with one merged from the raw responses,
 * which would otherwise undo a dismissal made in the sidebar.
 *
 * @param {Object|null} review   New review.
 * @param {Object|null} previous Review it replaces.
 * @return {Object|null} Review with the earlier note state kept.
 */
function keepNoteState(review, previous) {
	if (!previous?.is_in_progress || !previous.notes || !review?.notes) {
		return review;
	}
	const previousNotes = new Map(
		previous.notes.map((note) => [note.id, note])
	);
	return {
		...review,
		notes: review.notes.map((note) => {
			const old = previousNotes.get(note.id);
			if (!old) {
				return note;
			}
			return {
				...note,
				...(old.is_resolved !== undefined && {
					is_resolved: old.is_resolved,
				}),
				...(old.status !== undefined && { status: old.status }),
			};
		}),
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
					chunkSize: action.settings.chunk_size || 20,
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
				reviewStage: STAGE_PREPARING,
				reviewProgress: null,
				error: null,
				// A review the user starts resets the automatic retry count.
				unavailableRetries: action.isRetry
					? state.unavailableRetries
					: 0,
			};

		case TYPES.SET_REVIEW_STAGE:
			// Ignore late stage updates once the review has finished.
			if (!state.isReviewing) {
				return state;
			}
			return {
				...state,
				reviewStage: action.stage,
			};

		case TYPES.SET_REVIEW_PROGRESS:
			return {
				...state,
				reviewProgress: action.progress,
			};

		case TYPES.RECEIVE_PARTIAL_REVIEW:
			// Ignore parts that arrive after the review has finished.
			if (!state.isReviewing) {
				return state;
			}
			return {
				...state,
				lastReview: {
					...keepNoteState(action.review, state.lastReview),
					is_in_progress: true,
				},
			};

		case TYPES.REVIEW_SUCCESS:
			return {
				...state,
				isReviewing: false,
				reviewStage: null,
				unavailableRetries: 0,
				reviewProgress: null,
				lastReview: keepNoteState(action.review, state.lastReview),
				reviewHistories: addReviewToHistories(
					state.reviewHistories,
					action.review
				),
			};

		case TYPES.REVIEW_ERROR:
			return {
				...state,
				isReviewing: false,
				reviewStage: null,
				reviewProgress: null,
				error: action.error,
				// A review that stops early must not stay marked as running.
				lastReview: state.lastReview?.is_in_progress
					? {
							...state.lastReview,
							is_in_progress: false,
							is_partial: true,
						}
					: state.lastReview,
				unavailableRetries:
					action.error?.code === 'ai_service_unavailable'
						? state.unavailableRetries + 1
						: 0,
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

		case TYPES.SET_NOTE_RESOLVED:
			if (!state.lastReview?.notes) {
				return state;
			}
			return {
				...state,
				lastReview: {
					...state.lastReview,
					notes: state.lastReview.notes.map((note) => {
						let updated = note;
						if (note.id === action.noteId) {
							updated = {
								...updated,
								is_resolved: action.isResolved,
							};
						}
						// Mirror the Notes panel thread status on its top-level note.
						if (action.threadId && note.id === action.threadId) {
							updated = {
								...updated,
								status: action.threadResolved
									? 'approved'
									: 'hold',
							};
						}
						return updated;
					}),
				},
			};

		case TYPES.SET_FIX_APPLIED: {
			const appliedFixes = { ...state.appliedFixes };
			if (action.isApplied) {
				appliedFixes[action.noteId] = true;
			} else {
				delete appliedFixes[action.noteId];
			}
			return { ...state, appliedFixes };
		}

		case TYPES.SET_SUGGESTION_STATUS:
			if (!state.lastReview?.notes) {
				return state;
			}
			return {
				...state,
				lastReview: {
					...state.lastReview,
					notes: state.lastReview.notes.map((note) =>
						note.id === action.noteId
							? { ...note, suggestion_status: action.status }
							: note
					),
				},
			};

		default:
			return state;
	}
}
