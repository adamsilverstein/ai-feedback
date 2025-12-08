/**
 * Store reducer.
 */

/**
 * Initial state.
 */
export const initialState = {
	settings: {
		defaultModel: 'claude-sonnet-4',
		defaultFocusAreas: [ 'content', 'tone', 'flow' ],
		defaultTone: 'professional',
	},
	availableModels: [],
	availableFocusAreas: [],
	availableTones: [],
	isReviewing: false,
	lastReview: null,
	reviewHistory: [],
	error: null,
	isLoadingSettings: false,
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
	START_REVIEW: 'START_REVIEW',
	REVIEW_SUCCESS: 'REVIEW_SUCCESS',
	REVIEW_ERROR: 'REVIEW_ERROR',
	ADD_TO_HISTORY: 'ADD_TO_HISTORY',
	CLEAR_ERROR: 'CLEAR_ERROR',
	SET_LOADING_SETTINGS: 'SET_LOADING_SETTINGS',
};

/**
 * Reducer function.
 *
 * @param {Object} state  Current state.
 * @param {Object} action Action object.
 * @return {Object} New state.
 */
export default function reducer( state = initialState, action ) {
	switch ( action.type ) {
		case TYPES.SET_SETTINGS:
			return {
				...state,
				settings: {
					defaultModel: action.settings.default_model,
					defaultFocusAreas: action.settings.default_focus_areas,
					defaultTone: action.settings.default_tone,
				},
				availableModels: action.settings.available_models || [],
				availableFocusAreas: action.settings.available_focus_areas || [],
				availableTones: action.settings.available_tones || [],
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
				reviewHistory: [ action.review, ...state.reviewHistory ],
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
				reviewHistory: [ action.review, ...state.reviewHistory ],
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

		default:
			return state;
	}
}
