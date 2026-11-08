/**
 * WordPress data store for AI Feedback.
 */
import { createReduxStore, register, select } from '@wordpress/data';
import { apiFetch, controls } from '@wordpress/data-controls';
import { store as editorStore } from '@wordpress/editor';
import * as selectors from './selectors';
import * as actions from './actions';
import reducer from './reducer';

const STORE_NAME = 'ai-feedback/store';

/**
 * Store configuration.
 *
 * Note: Block metadata updates (storing noteId in blocks) are handled
 * directly in the startReview action using registryDispatch/registrySelect.
 *
 * The controls from @wordpress/data-controls are required to handle
 * apiFetch promises in generator functions (yield apiFetch(...)).
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

		/**
		 * Resolver for getLastReview - fetches previous review from database on first access.
		 *
		 * This resolver is called automatically when getLastReview selector is accessed
		 * and the data hasn't been fetched yet.
		 */
		*getLastReview() {
			// Get the current post ID from the editor store.
			const postId = select(editorStore).getCurrentPostId();

			if (!postId) {
				// eslint-disable-next-line no-console
				console.log(
					'[AI-Feedback] No post ID available, skipping previous review fetch'
				);
				return actions.receivePreviousReview(null);
			}

			// Fetch the previous review.
			// eslint-disable-next-line no-console
			console.log(
				'[AI-Feedback] Fetching previous review for post:',
				postId
			);

			// Use the generator action to fetch.
			const result = yield* actions.fetchPreviousReview(postId);
			return result;
		},

		*getReviewHistory() {
			const postId = select(editorStore).getCurrentPostId();
			if (!postId) {
				return actions.receiveReviewHistory([]);
			}

			try {
				const response = yield apiFetch({
					path: `/ai-feedback/v1/history/post/${postId}`,
				});
				return actions.receiveReviewHistory(response.history || []);
			} catch (error) {
				return actions.receiveReviewHistory([]);
			}
		},

		*getHistoryReview(postId, reviewId) {
			try {
				const review = yield apiFetch({
					path: `/ai-feedback/v1/history/post/${postId}/review/${reviewId}`,
				});
				return actions.receiveHistoryReview(reviewId, review);
			} catch (error) {
				return actions.receiveHistoryReview(reviewId, null);
			}
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
