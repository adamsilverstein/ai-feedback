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
	// eslint-disable-next-line no-console
	console.log('[AI-Feedback] Starting review', {
		postId,
		title,
		blocksCount: blocks.length,
		model,
		focusAreas,
		targetTone,
	});

	// eslint-disable-next-line no-console
	console.log(
		'[AI-Feedback] Blocks being sent:',
		blocks.map((b) => ({
			clientId: b.clientId,
			name: b.name,
			contentLength: b.content?.length || 0,
		}))
	);

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

		// eslint-disable-next-line no-console
		console.log('[AI-Feedback] Review API response received:', {
			reviewId: response.review_id,
			postId: response.post_id,
			model: response.model,
			noteCount: response.note_count,
			notesCount: response.notes?.length || 0,
			noteIdsCount: response.note_ids?.length || 0,
			blockMappingKeys: response.block_mapping
				? Object.keys(response.block_mapping)
				: [],
			summaryText: response.summary_text?.substring(0, 100) + '...',
			hasSummary: !!response.summary,
		});

		// Debug: Log full response for inspection
		// eslint-disable-next-line no-console
		console.log('[AI-Feedback] Full response:', response);

		// Update block metadata with note IDs if block_mapping is present
		if (
			response.block_mapping &&
			Object.keys(response.block_mapping).length > 0
		) {
			// eslint-disable-next-line no-console
			console.log(
				'[AI-Feedback] Processing block_mapping:',
				response.block_mapping
			);

			// Directly update block metadata using registry dispatch
			// This stores the noteId in each block's metadata so WordPress can link blocks to notes
			let updatedCount = 0;
			let errorCount = 0;

			Object.entries(response.block_mapping).forEach(
				([clientId, noteId]) => {
					try {
						// Get the current block to access existing metadata
						const block =
							registrySelect(blockEditorStore).getBlock(clientId);

						if (!block) {
							// eslint-disable-next-line no-console
							console.warn(
								`[AI-Feedback] Block ${clientId} not found in editor`
							);
							errorCount++;
							return;
						}

						// Get existing metadata or empty object
						const existingMetadata =
							block.attributes?.metadata || {};

						// eslint-disable-next-line no-console
						console.log(
							`[AI-Feedback] Updating block ${clientId} with noteId ${noteId}`,
							{
								existingMetadata,
								blockName: block.name,
							}
						);

						// Update block attributes with the noteId in metadata
						registryDispatch(
							blockEditorStore
						).updateBlockAttributes(clientId, {
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
				}
			);

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
					registryDispatch('core').invalidateResolution(
						'getEntityRecords',
						['root', 'comment', queryArgs]
					);
				}

				// Also try to invalidate core/notes if it exists.
				registryDispatch('core/notes')?.invalidateResolution(
					'getNotes',
					[postId]
				);
			} catch (e) {
				// Fallback to core comments invalidation if specific store isn't available.
				try {
					const queryArgs = {
						post: postId,
						type: 'note',
						status: 'all',
						per_page: -1,
					};
					registryDispatch('core')?.invalidateResolution(
						'getComments',
						{ post: postId }
					);
					registryDispatch('core')?.invalidateResolution(
						'getEntityRecords',
						['root', 'comment', queryArgs]
					);
				} catch (e2) {
					// Ignore errors if stores aren't initialized yet.
				}
			}

			// Also dispatch to our store for tracking
			yield {
				type: TYPES.UPDATE_BLOCK_NOTES,
				blockMapping: response.block_mapping,
			};
		} else {
			// eslint-disable-next-line no-console
			console.log('[AI-Feedback] No block_mapping in response or empty');
		}

		// eslint-disable-next-line no-console
		console.log(
			'[AI-Feedback] Dispatching REVIEW_SUCCESS with review data'
		);

		return {
			type: TYPES.REVIEW_SUCCESS,
			review: response,
		};
	} catch (error) {
		// eslint-disable-next-line no-console
		console.error('[AI-Feedback] Review failed:', error);

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
