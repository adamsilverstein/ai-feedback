/**
 * Internal dependencies
 */
import { describe, it, expect } from 'vitest';
import reducer, { initialState, TYPES } from '../reducer';

const unavailable = {
	type: TYPES.REVIEW_ERROR,
	error: { code: 'ai_service_unavailable', message: 'Down' },
};

describe('reducer unavailableRetries', () => {
	it('counts consecutive unavailable failures across retries', () => {
		let state = reducer(initialState, { type: TYPES.START_REVIEW });
		state = reducer(state, unavailable);
		expect(state.unavailableRetries).toBe(1);

		state = reducer(state, { type: TYPES.START_REVIEW, isRetry: true });
		state = reducer(state, unavailable);
		expect(state.unavailableRetries).toBe(2);
	});

	it('resets when the user starts a new review', () => {
		const state = reducer(
			{ ...initialState, unavailableRetries: 3 },
			{ type: TYPES.START_REVIEW }
		);
		expect(state.unavailableRetries).toBe(0);
	});

	it('resets after a success or a different error', () => {
		const failing = { ...initialState, unavailableRetries: 2 };

		expect(
			reducer(failing, { type: TYPES.REVIEW_SUCCESS, review: {} })
				.unavailableRetries
		).toBe(0);
		expect(
			reducer(failing, {
				type: TYPES.REVIEW_ERROR,
				error: { code: 'rate_limit_exceeded' },
			}).unavailableRetries
		).toBe(0);
	});
});

describe('reducer partial reviews', () => {
	const previous = { review_id: 'old', notes: [{ id: 1 }] };
	const reviewing = reducer(
		{ ...initialState, lastReview: previous },
		{ type: TYPES.START_REVIEW }
	);
	const partOne = {
		review_id: 'new',
		notes: [{ id: 10, is_resolved: false, status: 'hold' }],
		chunks: 1,
	};

	it('shows finished parts while the review keeps running', () => {
		const state = reducer(reviewing, {
			type: TYPES.RECEIVE_PARTIAL_REVIEW,
			review: partOne,
		});

		expect(state.lastReview).toEqual({ ...partOne, is_in_progress: true });
		expect(state.isReviewing).toBe(true);
		expect(state.reviewStage).toBe(reviewing.reviewStage);
		expect(state.reviewHistories).toBe(reviewing.reviewHistories);
	});

	it('ignores parts that arrive after the review finished', () => {
		const done = { ...initialState, lastReview: previous };
		expect(
			reducer(done, {
				type: TYPES.RECEIVE_PARTIAL_REVIEW,
				review: partOne,
			})
		).toBe(done);
	});

	it('clears the in-progress flag on success', () => {
		let state = reducer(reviewing, {
			type: TYPES.RECEIVE_PARTIAL_REVIEW,
			review: partOne,
		});
		state = reducer(state, {
			type: TYPES.REVIEW_SUCCESS,
			review: { ...partOne, chunks: 2 },
		});

		expect(state.lastReview.is_in_progress).toBeUndefined();
		expect(state.lastReview.chunks).toBe(2);
		expect(state.isReviewing).toBe(false);
	});

	it('keeps dismissals made between parts', () => {
		let state = reducer(reviewing, {
			type: TYPES.RECEIVE_PARTIAL_REVIEW,
			review: partOne,
		});
		state = reducer(state, {
			type: TYPES.SET_NOTE_RESOLVED,
			noteId: 10,
			isResolved: true,
			threadId: 10,
			threadResolved: true,
		});
		state = reducer(state, {
			type: TYPES.RECEIVE_PARTIAL_REVIEW,
			review: {
				...partOne,
				notes: [...partOne.notes, { id: 11 }],
			},
		});
		expect(state.lastReview.notes).toEqual([
			{ id: 10, is_resolved: true, status: 'approved' },
			{ id: 11 },
		]);

		state = reducer(state, {
			type: TYPES.REVIEW_SUCCESS,
			review: { ...partOne, notes: [...partOne.notes, { id: 11 }] },
		});
		expect(state.lastReview.notes[0]).toMatchObject({
			is_resolved: true,
			status: 'approved',
		});
	});

	it('marks a running review incomplete when it stops early', () => {
		let state = reducer(reviewing, {
			type: TYPES.RECEIVE_PARTIAL_REVIEW,
			review: partOne,
		});
		state = reducer(state, {
			type: TYPES.REVIEW_ERROR,
			error: { code: 'unknown_error', message: 'Stopped' },
		});

		expect(state.lastReview).toMatchObject({
			review_id: 'new',
			is_in_progress: false,
			is_partial: true,
		});
	});

	it('leaves a finished review alone on a later error', () => {
		const state = reducer(reviewing, {
			type: TYPES.REVIEW_ERROR,
			error: { code: 'unknown_error', message: 'Down' },
		});
		expect(state.lastReview).toBe(previous);
	});
});
