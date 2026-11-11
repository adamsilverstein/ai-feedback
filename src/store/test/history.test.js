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

const makeReview = (id, timestamp = '2026-11-08 10:00:00', extra = {}) => ({
	review_id: id,
	post_id: 42,
	model: 'test-model',
	note_count: 2,
	notes: [{ id: 1 }],
	block_mapping: { abc: 1 },
	summary: {
		total_notes: 2,
		by_severity: { critical: 1, important: 1, suggestion: 0 },
		by_category: { content: 2 },
	},
	summary_text: 'Solid draft.',
	timestamp,
	...extra,
});

const ts = (i) => `2026-11-08 10:${String(i).padStart(2, '0')}:00`;

describe('review history store', () => {
	it('reduces a review to a history entry', () => {
		expect(toHistoryEntry(makeReview('a'))).toEqual({
			review_id: 'a',
			timestamp: '2026-11-08 10:00:00',
			model: 'test-model',
			note_count: 2,
			notes_failed: false,
			summary_text: 'Solid draft.',
			summary: {
				by_severity: { critical: 1, important: 1, suggestion: 0 },
				by_category: { content: 2 },
			},
		});
	});

	it('flags reviews whose notes failed', () => {
		expect(
			toHistoryEntry(makeReview('a', ts(0), { notes_error: 'x' }))
				.notes_failed
		).toBe(true);
	});

	it('adds successful reviews to their post, newest first', () => {
		let state = reducer(initialState, {
			type: TYPES.RECEIVE_REVIEW_HISTORY,
			postId: 42,
			history: [toHistoryEntry(makeReview('old', ts(1)))],
		});
		state = reducer(state, {
			type: TYPES.REVIEW_SUCCESS,
			review: makeReview('new', ts(2)),
		});

		expect(getReviewHistory(state, 42).map((e) => e.review_id)).toEqual([
			'new',
			'old',
		]);
		expect(getReviewHistory(state, 7)).toEqual([]);
	});

	it('keeps session reviews when a slower fetch arrives', () => {
		let state = reducer(initialState, {
			type: TYPES.REVIEW_SUCCESS,
			review: makeReview('new', ts(2)),
		});
		state = reducer(state, {
			type: TYPES.RECEIVE_REVIEW_HISTORY,
			postId: 42,
			history: [toHistoryEntry(makeReview('old', ts(1)))],
		});

		expect(getReviewHistory(state, 42).map((e) => e.review_id)).toEqual([
			'new',
			'old',
		]);
	});

	it('caps history and drops duplicates', () => {
		let state = initialState;
		for (let i = 0; i < MAX_HISTORY_ENTRIES + 2; i++) {
			state = reducer(state, {
				type: TYPES.REVIEW_SUCCESS,
				review: makeReview(`r${i}`, ts(i)),
			});
		}
		state = reducer(state, {
			type: TYPES.RECEIVE_REVIEW_HISTORY,
			postId: 42,
			history: [toHistoryEntry(makeReview('r11', ts(11)))],
		});

		const ids = getReviewHistory(state, 42).map((e) => e.review_id);
		expect(ids).toHaveLength(MAX_HISTORY_ENTRIES);
		expect(ids[0]).toBe('r11');
		expect(new Set(ids).size).toBe(ids.length);
	});

	it('stores fetched past reviews by post and ID', () => {
		const state = reducer(initialState, {
			type: TYPES.RECEIVE_HISTORY_REVIEW,
			postId: 42,
			reviewId: 'a',
			review: makeReview('a'),
		});

		expect(getHistoryReview(state, 42, 'a').review_id).toBe('a');
		expect(getHistoryReview(state, 7, 'a')).toBeUndefined();
	});
});

describe('reducer keeps unrelated cases', () => {
	it('clears errors', () => {
		const state = reducer(
			{ ...initialState, error: { code: 'x' } },
			{ type: TYPES.CLEAR_ERROR }
		);
		expect(state.error).toBeNull();
	});

	it('receives the previous review', () => {
		const state = reducer(initialState, {
			type: TYPES.RECEIVE_PREVIOUS_REVIEW,
			review: { review_id: 'p' },
		});
		expect(state.lastReview.review_id).toBe('p');
		expect(state.hasFetchedPreviousReview).toBe(true);
	});
});
