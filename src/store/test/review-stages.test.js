/**
 * Internal dependencies
 */
jest.mock('@wordpress/block-editor', () => ({ store: 'core/block-editor' }));

import reducer, { initialState, TYPES } from '../reducer';
import { getReviewStage } from '../selectors';
import { setReviewStage, startReview } from '../actions';
import {
	getReviewStages,
	getStageProgress,
	STAGE_ANALYZING,
	STAGE_ATTACHING,
	STAGE_PREPARING,
} from '../review-stages';

describe('review stages', () => {
	it('lists stages in order with increasing progress ranges', () => {
		const stages = getReviewStages();

		expect(stages.map((stage) => stage.key)).toEqual([
			STAGE_PREPARING,
			STAGE_ANALYZING,
			STAGE_ATTACHING,
		]);

		stages.forEach((stage) => {
			expect(stage.label).toEqual(expect.any(String));
			expect(stage.start).toBeLessThan(stage.end);
		});

		stages.slice(1).forEach((stage, index) => {
			expect(stage.start).toBeGreaterThanOrEqual(stages[index].end);
		});
	});

	it('starts at the stage start and never reaches the stage end', () => {
		const [, analyzing] = getReviewStages();

		expect(getStageProgress(analyzing, 0)).toBe(analyzing.start);
		expect(getStageProgress(analyzing, 10000)).toBeGreaterThan(
			analyzing.start
		);
		expect(getStageProgress(analyzing, 10 * 60 * 1000)).toBeLessThan(
			analyzing.end
		);
	});

	it('keeps progress moving forward over time', () => {
		const [, analyzing] = getReviewStages();
		const samples = [0, 1000, 5000, 20000, 60000].map((ms) =>
			getStageProgress(analyzing, ms)
		);

		samples.slice(1).forEach((value, index) => {
			expect(value).toBeGreaterThan(samples[index]);
		});
	});

	it('returns 0 without a stage', () => {
		expect(getStageProgress(undefined, 1000)).toBe(0);
	});
});

describe('review stage reducer', () => {
	it('enters the preparing stage when a review starts', () => {
		const state = reducer(initialState, { type: TYPES.START_REVIEW });

		expect(getReviewStage(state)).toBe(STAGE_PREPARING);
	});

	it('moves through stages while reviewing', () => {
		let state = reducer(initialState, { type: TYPES.START_REVIEW });
		state = reducer(state, setReviewStage(STAGE_ANALYZING));
		expect(getReviewStage(state)).toBe(STAGE_ANALYZING);

		state = reducer(state, setReviewStage(STAGE_ATTACHING));
		expect(getReviewStage(state)).toBe(STAGE_ATTACHING);
	});

	it.each([
		[{ type: TYPES.REVIEW_SUCCESS, review: { notes: [] } }],
		[{ type: TYPES.REVIEW_ERROR, error: { message: 'Nope' } }],
	])('clears the stage when the review ends (%j)', (action) => {
		let state = reducer(initialState, { type: TYPES.START_REVIEW });
		state = reducer(state, setReviewStage(STAGE_ANALYZING));
		state = reducer(state, action);

		expect(getReviewStage(state)).toBeNull();
	});

	it('ignores stage updates when no review is running', () => {
		const state = reducer(initialState, setReviewStage(STAGE_ANALYZING));

		expect(getReviewStage(state)).toBeNull();
	});
});

describe('startReview stage transitions', () => {
	it('dispatches stages in order around the review request', () => {
		const generator = startReview({
			postId: 1,
			title: 'Title',
			blocks: [{ clientId: 'abc', name: 'core/paragraph', content: 'x' }],
			model: 'model',
			focusAreas: [],
			targetTone: 'professional',
			locale: 'auto',
		});

		// Silence the action's debug logging.
		jest.spyOn(console, 'log').mockImplementation(() => {});

		const stageActions = [];
		let step = generator.next();

		// Feed canned responses: no history, then a review without notes.
		while (!step.done) {
			const yielded = step.value;
			let input;

			if (yielded?.type === TYPES.START_REVIEW) {
				stageActions.push(STAGE_PREPARING);
			} else if (yielded?.type === TYPES.SET_REVIEW_STAGE) {
				stageActions.push(yielded.stage);
			} else if (yielded?.request?.path?.includes('feedback-history')) {
				input = { has_history: false, notes: [], total: 0 };
			} else if (yielded?.request?.path === '/ai-feedback/v1/review') {
				input = { review_id: 'r1', notes: [], block_mapping: {} };
			}

			step = generator.next(input);
		}

		expect(stageActions).toEqual([
			STAGE_PREPARING,
			STAGE_ANALYZING,
			STAGE_ATTACHING,
		]);
		expect(step.value.type).toBe(TYPES.REVIEW_SUCCESS);

		// eslint-disable-next-line no-console
		console.log.mockRestore();
	});

	it('ends with REVIEW_ERROR when the review request fails', () => {
		jest.spyOn(console, 'log').mockImplementation(() => {});
		jest.spyOn(console, 'error').mockImplementation(() => {});

		const generator = startReview({
			postId: 1,
			title: 'Title',
			blocks: [{ clientId: 'abc', name: 'core/paragraph', content: 'x' }],
		});

		let step = generator.next();
		while (!step.done) {
			const yielded = step.value;
			if (yielded?.request?.path === '/ai-feedback/v1/review') {
				step = generator.throw({ code: 'boom', message: 'Failed' });
				continue;
			}
			step = generator.next(
				yielded?.request?.path?.includes('feedback-history')
					? { has_history: false, notes: [], total: 0 }
					: undefined
			);
		}

		expect(step.value).toEqual({
			type: TYPES.REVIEW_ERROR,
			error: { code: 'boom', message: 'Failed', data: null },
		});

		// The reducer clears the stage on that action.
		let state = reducer(initialState, { type: TYPES.START_REVIEW });
		state = reducer(state, setReviewStage(STAGE_ANALYZING));
		state = reducer(state, step.value);
		expect(getReviewStage(state)).toBeNull();

		// eslint-disable-next-line no-console
		console.log.mockRestore();
		// eslint-disable-next-line no-console
		console.error.mockRestore();
	});
});
