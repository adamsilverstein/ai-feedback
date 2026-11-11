/**
 * Store actions.
 */
import { apiFetch } from '@wordpress/data-controls';
import {
	dispatch as registryDispatch,
	select as registrySelect,
} from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { TYPES } from './reducer';
import { STAGE_ANALYZING, STAGE_ATTACHING } from './review-stages';
import {
	chunkBlocks,
	mergeChunkResponses,
	DEFAULT_CHUNK_SIZE,
} from '../utils/chunk-review';

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
 * Link blocks to their new notes and refresh the notes shown in the editor.
 *
 * @param {Object} response Review response with block_mapping and notes.
 * @param {number} postId   Post ID.
 */
function applyReviewResponse(response, postId) {
	if (
		!response.block_mapping ||
		Object.keys(response.block_mapping).length === 0
	) {
		// eslint-disable-next-line no-console
		console.log('[AI-Feedback] No block_mapping in response or empty');
		return;
	}

	// eslint-disable-next-line no-console
	console.log(
		'[AI-Feedback] Processing block_mapping:',
		response.block_mapping
	);

	// Directly update block metadata using registry dispatch
	// This stores the noteId in each block's metadata so WordPress can link blocks to notes
	let updatedCount = 0;
	let errorCount = 0;

	Object.entries(response.block_mapping).forEach(([clientId, noteId]) => {
		try {
			// Get the current block to access existing metadata
			const block = registrySelect(blockEditorStore).getBlock(clientId);

			if (!block) {
				// eslint-disable-next-line no-console
				console.warn(
					`[AI-Feedback] Block ${clientId} not found in editor`
				);
				errorCount++;
				return;
			}

			// Get existing metadata or empty object
			const existingMetadata = block.attributes?.metadata || {};

			// eslint-disable-next-line no-console
			console.log(
				`[AI-Feedback] Updating block ${clientId} with noteId ${noteId}`,
				{
					existingMetadata,
					blockName: block.name,
				}
			);

			// Update block attributes with the noteId in metadata
			registryDispatch(blockEditorStore).updateBlockAttributes(clientId, {
				metadata: {
					...existingMetadata,
					noteId,
				},
			});

			// eslint-disable-next-line no-console
			console.log(
				`[AI-Feedback] Successfully updated block ${clientId} with noteId ${noteId}`
			);
			updatedCount++;
		} catch (error) {
			// eslint-disable-next-line no-console
			console.error(
				`[AI-Feedback] Could not update block ${clientId}:`,
				error.message,
				error
			);
			errorCount++;
		}
	});

	// eslint-disable-next-line no-console
	console.log(
		`[AI-Feedback] Block metadata update complete: ${updatedCount} updated, ${errorCount} errors`
	);

	// Invalidate core notes/comments resolution to force a refresh in the UI.
	// This ensures the toolbar icon appears immediately without a page refresh.
	try {
		// Inject the notes directly into the core data store.
		// We use 'root' kind and exact query params used by Gutenberg's useBlockComments.
		if (response.notes && response.notes.length > 0) {
			const queryArgs = {
				post: postId,
				type: 'note',
				status: 'all',
				per_page: -1,
			};

			registryDispatch('core').receiveEntityRecords(
				'root',
				'comment',
				response.notes,
				queryArgs,
				true // invalidateCaches
			);

			// Also explicitly invalidate to be sure.
			registryDispatch('core').invalidateResolution('getEntityRecords', [
				'root',
				'comment',
				queryArgs,
			]);
		}

		// Also try to invalidate core/notes if it exists.
		registryDispatch('core/notes')?.invalidateResolution('getNotes', [
			postId,
		]);
	} catch (e) {
		// Fallback to core comments invalidation if specific store isn't available.
		try {
			const queryArgs = {
				post: postId,
				type: 'note',
				status: 'all',
				per_page: -1,
			};
			registryDispatch('core')?.invalidateResolution('getComments', {
				post: postId,
			});
			registryDispatch('core')?.invalidateResolution('getEntityRecords', [
				'root',
				'comment',
				queryArgs,
			]);
		} catch (e2) {
			// Ignore errors if stores aren't initialized yet.
		}
	}
}

/**
 * Start a document review.
 *
 * Documents with more blocks than `chunkSize` are reviewed in several
 * requests. Notes from each part are added to the editor as soon as that
 * part finishes, and progress is tracked in the store.
 *
 * @param {Object}  options            Review options.
 * @param {number}  options.postId     Post ID to review.
 * @param {string}  options.title      Post title from editor.
 * @param {Array}   options.blocks     Blocks with clientIds and content.
 * @param {string}  options.model      AI model to use.
 * @param {Array}   options.focusAreas Focus areas.
 * @param {string}  options.targetTone Target tone.
 * @param {string}  options.locale     Feedback locale.
 * @param {number}  options.chunkSize  Blocks per review request.
 * @param {boolean} options.isRetry    Whether this retries a review that failed because the AI service was unavailable.
 * @return {Object} Action object.
 */
export function* startReview({
	postId,
	title,
	blocks,
	model,
	focusAreas,
	targetTone,
	locale,
	chunkSize = DEFAULT_CHUNK_SIZE,
	isRetry = false,
}) {
	const chunks = chunkBlocks(blocks, chunkSize);
	const total = chunks.length;

	// eslint-disable-next-line no-console
	console.log('[AI-Feedback] Starting review', {
		postId,
		title,
		blocksCount: blocks.length,
		chunks: total,
		model,
		focusAreas,
		targetTone,
		locale,
	});

	yield { type: TYPES.START_REVIEW, isRetry };

	const responses = [];

	try {
		// Fetch existing feedback history for continuation reviews.
		// This will include unresolved notes with their user replies.
		const feedbackHistory = yield* fetchFeedbackHistory(postId);
		const existingFeedback = feedbackHistory.has_history
			? feedbackHistory.notes
			: [];

		let reviewId = '';

		for (let index = 0; index < total; index++) {
			if (total > 1) {
				yield {
					type: TYPES.SET_REVIEW_PROGRESS,
					progress: { current: index + 1, total },
				};
			}

			const data = {
				post_id: postId,
				title,
				blocks: chunks[index],
				model,
				focus_areas: focusAreas,
				target_tone: targetTone,
				locale,
				existing_feedback: existingFeedback,
			};

			if (total > 1) {
				data.chunk_index = index;
				data.chunk_total = total;
				if (reviewId) {
					data.review_id = reviewId;
				}
				if (index === total - 1) {
					data.previous_summaries = responses
						.map((response) => response.summary_text)
						.filter(Boolean);
				}
			}

			yield setReviewStage(STAGE_ANALYZING);

			const response = yield apiFetch({
				path: '/ai-feedback/v1/review',
				method: 'POST',
				data,
			});

			// eslint-disable-next-line no-console
			console.log('[AI-Feedback] Review API response received:', {
				chunk: `${index + 1}/${total}`,
				reviewId: response.review_id,
				noteCount: response.note_count,
			});

			reviewId = response.review_id;
			responses.push(response);

			yield setReviewStage(STAGE_ATTACHING);

			// Show this part's notes right away instead of waiting for
			// the whole document.
			applyReviewResponse(response, postId);
			if (
				response.block_mapping &&
				Object.keys(response.block_mapping).length > 0
			) {
				yield {
					type: TYPES.UPDATE_BLOCK_NOTES,
					blockMapping: response.block_mapping,
				};
			}
		}

		return {
			type: TYPES.REVIEW_SUCCESS,
			review: total > 1 ? mergeChunkResponses(responses) : responses[0],
		};
	} catch (error) {
		// eslint-disable-next-line no-console
		console.error('[AI-Feedback] Review failed:', error);

		// Keep the notes from parts that finished before the failure.
		if (responses.length > 0) {
			yield {
				type: TYPES.REVIEW_SUCCESS,
				review: {
					...mergeChunkResponses(responses),
					is_partial: true,
				},
			};
		}

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
 * Set the current review stage.
 *
 * @param {string} stage Stage key from review-stages.js.
 * @return {Object} Action object.
 */
export function setReviewStage(stage) {
	return {
		type: TYPES.SET_REVIEW_STAGE,
		stage,
	};
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

/**
 * Fetch previous review for a post.
 *
 * @param {number} postId Post ID.
 * @return {Object} Action object.
 */
export function* fetchPreviousReview(postId) {
	yield { type: TYPES.FETCHING_PREVIOUS_REVIEW };

	try {
		const response = yield apiFetch({
			path: `/ai-feedback/v1/notes/post/${postId}/latest-review`,
			method: 'GET',
		});

		// eslint-disable-next-line no-console
		console.log('[AI-Feedback] Previous review fetched:', {
			hasReview: response.has_review,
			reviewId: response.review?.review_id,
			noteCount: response.review?.note_count,
		});

		if (response.has_review && response.review) {
			return {
				type: TYPES.RECEIVE_PREVIOUS_REVIEW,
				review: response.review,
			};
		}

		return {
			type: TYPES.RECEIVE_PREVIOUS_REVIEW,
			review: null,
		};
	} catch (error) {
		// eslint-disable-next-line no-console
		console.error('[AI-Feedback] Failed to fetch previous review:', error);
		return {
			type: TYPES.RECEIVE_PREVIOUS_REVIEW,
			review: null,
		};
	}
}

/**
 * Receive previous review (called by resolver).
 *
 * @param {Object|null} review Review object or null.
 * @return {Object} Action object.
 */
export function receivePreviousReview(review) {
	return {
		type: TYPES.RECEIVE_PREVIOUS_REVIEW,
		review,
	};
}

/**
 * Fetch feedback history for continuation reviews.
 *
 * @param {number} postId Post ID.
 * @return {Object} Feedback history response.
 */
export function* fetchFeedbackHistory(postId) {
	try {
		const response = yield apiFetch({
			path: `/ai-feedback/v1/notes/post/${postId}/feedback-history`,
			method: 'GET',
		});

		// eslint-disable-next-line no-console
		console.log('[AI-Feedback] Feedback history fetched:', {
			hasHistory: response.has_history,
			noteCount: response.total,
		});

		return response;
	} catch (error) {
		// eslint-disable-next-line no-console
		console.error('[AI-Feedback] Failed to fetch feedback history:', error);
		return { notes: [], has_history: false, total: 0 };
	}
}
