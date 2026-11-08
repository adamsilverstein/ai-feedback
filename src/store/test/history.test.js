/**
 * Internal dependencies
 */
import reducer, {
	initialState,
	TYPES,
	MAX_HISTORY_ENTRIES,
	toHistoryEntry,
} from '../reducer';
import { getReviewHistory, getHistoryReview } from '../selectors';

const makeReview = (id, count = 2) => ({
	review_id: id,
	post_id: 42,
	model: 'test-model',
	note_count: count,
	notes: [{ id: 1 }],
	block_mapping: { abc: 1 },
	summary: {
		total_notes: count,
		by_severity: { critical: 1, important: 1, suggestion: 0 },
		by_category: { content: 2 },
	},
	summary_text: 'Solid draft.',
	timestamp: '2026-11-08 10:00:00',
});

describe('review history store', () => {
	it('reduces a review to a history entry', () => {
		expect(toHistoryEntry(makeReview('a'))).toEqual({
			review_id: 'a',
			timestamp: '2026-11-08 10:00:00',
			model: 'test-model',
			note_count: 2,
			summary_text: 'Solid draft.',
			summary: {
				by_severity: { critical: 1, important: 1, suggestion: 0 },
				by_category: { content: 2 },
			},
		});
	});

	it('prepends successful reviews, newest first', () => {
		let state = reducer(initialState, {
			type: TYPES.RECEIVE_REVIEW_HISTORY,
			history: [toHistoryEntry(makeReview('old'))],
		});
		state = reducer(state, {
			type: TYPES.REVIEW_SUCCESS,
			review: makeReview('new'),
		});

		expect(getReviewHistory(state).map((e) => e.review_id)).toEqual([
			'new',
			'old',
		]);
	});

	it('caps history and drops duplicates', () => {
		let state = initialState;
		for (let i = 0; i < MAX_HISTORY_ENTRIES + 2; i++) {
			state = reducer(state, {
				type: TYPES.REVIEW_SUCCESS,
				review: makeReview(`r${i}`),
			});
		}
		state = reducer(state, {
			type: TYPES.ADD_TO_HISTORY,
			review: makeReview(`r${MAX_HISTORY_ENTRIES + 1}`),
		});

		const ids = getReviewHistory(state).map((e) => e.review_id);
		expect(ids).toHaveLength(MAX_HISTORY_ENTRIES);
		expect(ids[0]).toBe(`r${MAX_HISTORY_ENTRIES + 1}`);
		expect(new Set(ids).size).toBe(ids.length);
	});

	it('stores fetched past reviews by ID', () => {
		const state = reducer(initialState, {
			type: TYPES.RECEIVE_HISTORY_REVIEW,
			reviewId: 'a',
			review: makeReview('a'),
		});

		expect(getHistoryReview(state, 42, 'a').review_id).toBe('a');
		expect(getHistoryReview(state, 42, 'b')).toBeUndefined();
	});
});
