/**
 * WordPress data store for AI Feedback.
 */
import { createReduxStore, register, dispatch } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import * as selectors from './selectors';
import * as actions from './actions';
import reducer, { TYPES } from './reducer';

const STORE_NAME = 'ai-feedback/store';

/**
 * Custom controls to handle side effects.
 */
const controls = {
	/**
	 * Control to update block metadata with note IDs.
	 * This dispatches to the block editor store.
	 *
	 * @param {Object} action Action with blockMapping.
	 */
	[TYPES.UPDATE_BLOCK_NOTES](action) {
		const { blockMapping } = action;

		if (!blockMapping || typeof blockMapping !== 'object') {
			return;
		}

		// Update each block's metadata with its noteId
		Object.entries(blockMapping).forEach(([clientId, noteId]) => {
			try {
				console.log( `[AI-Feedback] Updating block ${clientId} with noteId ${noteId}` );
				dispatch(blockEditorStore).updateBlockAttributes( clientId, {
					metadata: {
						...metadata,
						noteId: noteId,
					},
				} );
			} catch (error) {
				// Block might not exist or be editable
				// eslint-disable-next-line no-console
				console.warn(
					`[AI-Feedback] Could not update block ${clientId}:`,
					error.message
				);
			}
		});

		// Trigger notes refresh by invalidating the entity records
		try {
			dispatch('core').invalidateResolution('getEntityRecords', [
				'root',
				'comment',
			]);
		} catch (error) {
			// Core store might not be available
			// eslint-disable-next-line no-console
			console.warn('[AI-Feedback] Could not refresh notes:', error.message);
		}
	},
};

/**
 * Store configuration.
 */
const storeConfig = {
	reducer,
	selectors,
	actions,
	controls,
	resolvers: {
		*getSettings() {
			const settings = yield actions.fetchSettings();
			return actions.receiveSettings(settings);
		},
	},
};

/**
 * Create and register the store.
 */
const store = createReduxStore(STORE_NAME, storeConfig);
register(store);

export { STORE_NAME };
export default store;
