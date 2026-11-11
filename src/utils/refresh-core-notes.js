/**
 * Refresh notes held in the core data store.
 */
import { dispatch } from '@wordpress/data';

/**
 * Drop cached entity lists so the Notes panel refetches notes.
 *
 * Invalidates every getEntityRecords query rather than guessing the exact
 * arguments the Notes panel uses; only queries in use refetch.
 */
export function refreshCoreNotes() {
	try {
		dispatch('core').invalidateResolutionForStoreSelector(
			'getEntityRecords'
		);
	} catch (error) {
		// The core data store isn't available; nothing to refresh.
	}
}
