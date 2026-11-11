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

describe('SET_SUGGESTION_STATUS', () => {
	it('updates the matching note', () => {
		const state = {
			...initialState,
			lastReview: { notes: [{ id: 1 }, { id: 2 }] },
		};

		const next = reducer(state, {
			type: TYPES.SET_SUGGESTION_STATUS,
			noteId: 2,
			status: 'pending',
		});

		expect(next.lastReview.notes).toEqual([
			{ id: 1 },
			{ id: 2, suggestion_status: 'pending' },
		]);
	});

	it('ignores the action without a review', () => {
		expect(
			reducer(initialState, {
				type: TYPES.SET_SUGGESTION_STATUS,
				noteId: 2,
				status: null,
			})
		).toBe(initialState);
	});
});
