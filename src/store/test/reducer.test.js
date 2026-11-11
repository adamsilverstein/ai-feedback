/**
 * Internal dependencies
 */
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
