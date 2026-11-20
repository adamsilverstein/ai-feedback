/**
 * External dependencies
 */
import { describe, it, expect, vi } from 'vitest';
import { renderHook } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { createRegistry, RegistryProvider } from '@wordpress/data';

/**
 * Internal dependencies
 */
import useFeedbackProgress from '../useFeedbackProgress';

vi.mock('@wordpress/editor', () => ({ store: 'core/editor' }));

const review = {
	notes: [
		{ id: 1, parent: 0, status: 'hold' },
		{ id: 2, parent: 0, status: 'hold', is_resolved: true },
		{ id: 3, parent: 0, status: 'hold' },
	],
};

/**
 * Run the hook against a registry holding note records.
 *
 * @param {Object}  options           Options.
 * @param {Array}   options.records   Note records.
 * @param {boolean} options.isSettled Whether the records finished loading.
 * @return {Object} Progress counts.
 */
function progressWith({ records, isSettled }) {
	const registry = createRegistry();
	registry.registerStore('core/editor', {
		reducer: (state = {}) => state,
		selectors: { getCurrentPostId: () => 7 },
	});
	registry.registerStore('core', {
		reducer: (state = {}) => state,
		selectors: {
			getEntityRecords: () => records,
			hasFinishedResolution: () => isSettled,
			isResolving: () => !isSettled,
		},
	});

	const { result } = renderHook(() => useFeedbackProgress(review), {
		wrapper: ({ children }) => (
			<RegistryProvider value={registry}>{children}</RegistryProvider>
		),
	});
	return result.current;
}

describe('useFeedbackProgress', () => {
	// Note 1's thread was resolved in the Notes panel, note 3 was deleted.
	const records = [
		{ id: 1, parent: 0, status: 'approved', meta: {} },
		{ id: 2, parent: 0, status: 'hold', meta: {} },
	];

	it('reads thread status and deletions from loaded note records', () => {
		expect(progressWith({ records, isSettled: true })).toEqual({
			addressed: 2,
			total: 2,
		});
	});

	it('ignores a list that is still loading, which may be partial', () => {
		expect(progressWith({ records, isSettled: false })).toEqual({
			addressed: 1,
			total: 3,
		});
	});
});
