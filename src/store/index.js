/**
 * WordPress data store for AI Feedback.
 */
import { createReduxStore, register } from '@wordpress/data';
import * as selectors from './selectors';
import * as actions from './actions';
import reducer from './reducer';

const STORE_NAME = 'ai-feedback/store';

/**
 * Store configuration.
 */
const storeConfig = {
	reducer,
	selectors,
	actions,
	controls: {},
	resolvers: {
		*getSettings() {
			const settings = yield actions.fetchSettings();
			return actions.receiveSettings( settings );
		},
	},
};

/**
 * Create and register the store.
 */
const store = createReduxStore( STORE_NAME, storeConfig );
register( store );

export { STORE_NAME };
export default store;
