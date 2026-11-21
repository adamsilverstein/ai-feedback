/**
 * Tests for reading suggestion statuses, including a reply's edit held by
 * its thread's root note.
 */
import { describe, expect, it, vi } from 'vitest';
import {
	selectHeldSuggestionStatus,
	selectSuggestionStatus,
} from './useSuggestionStatus';

vi.mock('./useReplyThreads', () => ({
	getNotesQuery: (postId) => ({ post: postId }),
}));

/**
 * A registry `select` serving loaded note records.
 *
 * @param {Object[]|null} notes Note records, or null before they load.
 * @return {(store: string) => Object} Select.
 */
const selectWith = (notes) => (store) =>
	store === 'core/editor'
		? { getCurrentPostId: () => 42 }
		: { getEntityRecords: () => notes };

const root = (payload, status = '') => ({
	id: 39,
	meta: { _wp_suggestion: payload, _wp_suggestion_status: status },
});

describe('selectSuggestionStatus', () => {
	it('reads a note’s own suggestion', () => {
		const select = selectWith([root('{"schemaVersion":2}', 'applied')]);

		expect(selectSuggestionStatus(select, { id: 39 })).toBe('applied');
	});

	it('reads a reply’s edit from the root note that holds it', () => {
		const select = selectWith([
			root('{"schemaVersion":2,"aiFeedbackEditOwner":40}', 'pending'),
			{ id: 40, meta: {} },
		]);

		expect(selectSuggestionStatus(select, { id: 40 })).toBe('pending');
		// The root note's own fix is not the suggestion.
		expect(
			selectSuggestionStatus(select, {
				id: 39,
				suggestion_status: 'pending',
			})
		).toBe(null);
		expect(selectSuggestionStatus(select, { id: 41 })).toBe(null);
	});

	it('falls back to the review response until the notes load', () => {
		expect(
			selectSuggestionStatus(selectWith(null), {
				id: 39,
				suggestion_status: 'pending',
			})
		).toBe('pending');
	});
});

describe('selectHeldSuggestionStatus', () => {
	it('reads the suggestion a note holds, whoever’s edit it is', () => {
		const select = selectWith([
			root('{"schemaVersion":2,"aiFeedbackEditOwner":40}', 'pending'),
			{ id: 40, meta: {} },
		]);

		expect(selectHeldSuggestionStatus(select, { id: 39 })).toBe('pending');
		expect(selectHeldSuggestionStatus(select, { id: 40 })).toBe(null);
	});

	it('falls back to the review response until the notes load', () => {
		expect(
			selectHeldSuggestionStatus(selectWith(null), {
				id: 39,
				suggestion_status: 'pending',
			})
		).toBe('pending');
	});
});
