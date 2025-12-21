/**
 * Store actions.
 */
import apiFetch from '@wordpress/api-fetch';
import { TYPES } from './reducer';

/**
 * Fetch settings from the server.
 *
 * @return {Object} Settings object.
 */
export function* fetchSettings() {
	yield { type: TYPES.SET_LOADING_SETTINGS, isLoading: true };

	try {
		const settings = yield apiFetch({
			path: '/ai-feedback/v1/settings',
			method: 'GET',
		});

		return settings;
	} catch (error) {
		yield {
			type: TYPES.REVIEW_ERROR,
			error: {
				code: error.code || 'unknown_error',
				message: error.message || 'Failed to fetch settings',
				data: error.data || null,
			},
		};
		throw error;
	}
}

/**
 * Receive settings (called by resolver).
 *
 * @param {Object} settings Settings object.
 * @return {Object} Action object.
 */
export function receiveSettings(settings) {
	return {
		type: TYPES.SET_SETTINGS,
		settings,
	};
}

/**
 * Update settings on the server.
 *
 * @param {Object} settings Settings to update.
 * @return {Object} Action object.
 */
export function* updateSettings(settings) {
	try {
		const response = yield apiFetch({
			path: '/ai-feedback/v1/settings',
			method: 'POST',
			data: settings,
		});

		return {
			type: TYPES.SET_SETTINGS,
			settings: response,
		};
	} catch (error) {
		yield {
			type: TYPES.REVIEW_ERROR,
			error: {
				code: error.code || 'unknown_error',
				message: error.message || 'Failed to update settings',
				data: error.data || null,
			},
		};
		throw error;
	}
}

/**
 * Start a document review.
 *
 * @param {Object} options            Review options.
 * @param {number} options.postId     Post ID to review.
 * @param {string} options.title      Post title from editor.
 * @param {Array}  options.blocks     Blocks with clientIds and content.
 * @param {string} options.model      AI model to use.
 * @param {Array}  options.focusAreas Focus areas.
 * @param {string} options.targetTone Target tone.
 * @return {Object} Action object.
 */
export function* startReview({
	postId,
	title,
	blocks,
	model,
	focusAreas,
	targetTone,
}) {
	yield { type: TYPES.START_REVIEW };

	try {
		const response = yield apiFetch({
			path: '/ai-feedback/v1/review',
			method: 'POST',
			data: {
				post_id: postId,
				title,
				blocks,
				model,
				focus_areas: focusAreas,
				target_tone: targetTone,
			},
		});

		// Update block metadata with note IDs if block_mapping is present
		if (response.block_mapping && Object.keys(response.block_mapping).length > 0) {
			yield {
				type: TYPES.UPDATE_BLOCK_NOTES,
				blockMapping: response.block_mapping,
			};
		}

		return {
			type: TYPES.REVIEW_SUCCESS,
			review: response,
		};
	} catch (error) {
		return {
			type: TYPES.REVIEW_ERROR,
			error: {
				code: error.code || 'unknown_error',
				message: error.message || 'Review failed',
				data: error.data || null,
			},
		};
	}
}

/**
 * Update block metadata with note IDs.
 * This is a control that dispatches to the block editor store.
 *
 * @param {Object} blockMapping Map of clientId to noteId.
 * @return {Object} Action object.
 */
export function updateBlockNotes(blockMapping) {
	return {
		type: TYPES.UPDATE_BLOCK_NOTES,
		blockMapping,
	};
}

/**
 * Clear error state.
 *
 * @return {Object} Action object.
 */
export function clearError() {
	return {
		type: TYPES.CLEAR_ERROR,
	};
}

/**
 * Set available models.
 *
 * @param {Array} models Available models.
 * @return {Object} Action object.
 */
export function setAvailableModels(models) {
	return {
		type: TYPES.SET_AVAILABLE_MODELS,
		models,
	};
}

/**
 * Set available focus areas.
 *
 * @param {Array} areas Available focus areas.
 * @return {Object} Action object.
 */
export function setAvailableFocusAreas(areas) {
	return {
		type: TYPES.SET_AVAILABLE_FOCUS_AREAS,
		areas,
	};
}

/**
 * Set available tones.
 *
 * @param {Array} tones Available tones.
 * @return {Object} Action object.
 */
export function setAvailableTones(tones) {
	return {
		type: TYPES.SET_AVAILABLE_TONES,
		tones,
	};
}
