/**
 * External dependencies
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

/**
 * Internal dependencies
 */
import { startReview } from '../actions';
import { TYPES } from '../reducer';

vi.mock('@wordpress/block-editor', () => ({
	store: 'core/block-editor',
}));

const makeBlocks = (count) =>
	Array.from({ length: count }, (_, i) => ({
		clientId: `block-${i}`,
		name: 'core/paragraph',
		content: `Paragraph ${i}`,
	}));

const reviewResponse = (overrides = {}) => ({
	review_id: 'review-1',
	notes: [],
	note_ids: [],
	block_mapping: {},
	note_count: 0,
	summary_text: '',
	summary: { total_notes: 0, by_category: {}, by_severity: {} },
	...overrides,
});

/**
 * Advance the generator, skipping the review stage updates in between.
 *
 * @param {Object} gen   startReview generator.
 * @param {*}      value Value to send in.
 * @return {Object} Next result that isn't a stage update.
 */
function advance(gen, value) {
	let result = gen.next(value);
	while (result.value?.type === TYPES.SET_REVIEW_STAGE) {
		result = gen.next();
	}
	return result;
}

/**
 * Run the startReview generator up to its first review request.
 *
 * @param {Object} options startReview options.
 * @return {Object} Generator and the first apiFetch control.
 */
function startUntilFirstRequest(options) {
	const gen = startReview({ postId: 1, title: 'Title', ...options });

	expect(gen.next().value).toEqual({
		type: TYPES.START_REVIEW,
		isRetry: false,
	});
	// Feedback history request.
	gen.next();

	return gen;
}

describe('startReview', () => {
	let log;
	let error;

	beforeEach(() => {
		log = vi.spyOn(console, 'log').mockImplementation(() => {});
		error = vi.spyOn(console, 'error').mockImplementation(() => {});
	});

	afterEach(() => {
		// The store logs each step of a review for debugging.
		// eslint-disable-next-line vitest/no-standalone-expect
		expect(log).toHaveBeenCalled();
		vi.restoreAllMocks();
	});

	it('sends a single request for small documents', () => {
		const gen = startUntilFirstRequest({
			blocks: makeBlocks(3),
			chunkSize: 20,
		});

		const request = advance(gen, { has_history: false }).value;
		expect(request.request.data.blocks).toHaveLength(3);
		expect(request.request.data).not.toHaveProperty('chunk_index');

		const response = reviewResponse({ summary_text: 'Done.' });
		expect(advance(gen, response)).toEqual({
			done: true,
			value: { type: TYPES.REVIEW_SUCCESS, review: response },
		});
	});

	it('reviews large documents in chunks and reports progress', () => {
		const gen = startUntilFirstRequest({
			blocks: makeBlocks(5),
			chunkSize: 2,
		});

		expect(advance(gen, { has_history: false }).value).toEqual({
			type: TYPES.SET_REVIEW_PROGRESS,
			progress: { current: 1, total: 3 },
		});

		const first = advance(gen).value.request.data;
		expect(first).toMatchObject({ chunk_index: 0, chunk_total: 3 });
		expect(first).not.toHaveProperty('review_id');

		expect(
			advance(
				gen,
				reviewResponse({ summary_text: 'Part one.', note_count: 1 })
			).value
		).toEqual({
			type: TYPES.RECEIVE_PARTIAL_REVIEW,
			review: expect.objectContaining({
				summary_text: 'Part one.',
				note_count: 1,
				chunks: 1,
			}),
		});
		expect(advance(gen).value).toEqual({
			type: TYPES.SET_REVIEW_PROGRESS,
			progress: { current: 2, total: 3 },
		});

		const second = advance(gen).value.request.data;
		expect(second).toMatchObject({
			chunk_index: 1,
			review_id: 'review-1',
		});
		expect(second).not.toHaveProperty('previous_summaries');

		expect(
			advance(
				gen,
				reviewResponse({ summary_text: 'Part two.', note_count: 2 })
			).value
		).toEqual({
			type: TYPES.RECEIVE_PARTIAL_REVIEW,
			review: expect.objectContaining({
				summary_text: 'Part two.',
				note_count: 3,
				chunks: 2,
			}),
		});
		advance(gen);

		const third = advance(gen).value.request.data;
		expect(third).toMatchObject({
			chunk_index: 2,
			blocks: [expect.objectContaining({ clientId: 'block-4' })],
			previous_summaries: ['Part one.', 'Part two.'],
		});

		const result = advance(
			gen,
			reviewResponse({ summary_text: 'Whole document.' })
		);
		// The last part goes straight to REVIEW_SUCCESS.
		expect(result.done).toBe(true);
		expect(result.value.type).toBe(TYPES.REVIEW_SUCCESS);
		expect(result.value.review).toMatchObject({
			summary_text: 'Whole document.',
			chunks: 3,
		});
	});

	it('does not store a partial review for single-request reviews', () => {
		const gen = startUntilFirstRequest({
			blocks: makeBlocks(2),
			chunkSize: 20,
		});

		advance(gen, { has_history: false });
		const result = advance(gen, reviewResponse());
		expect(result.value.type).toBe(TYPES.REVIEW_SUCCESS);
	});

	it('keeps finished chunks when a later chunk fails', () => {
		const gen = startUntilFirstRequest({
			blocks: makeBlocks(4),
			chunkSize: 2,
		});

		advance(gen, { has_history: false });
		advance(gen);
		expect(advance(gen, reviewResponse({ note_count: 2 })).value).toEqual(
			expect.objectContaining({ type: TYPES.RECEIVE_PARTIAL_REVIEW })
		);
		advance(gen);
		advance(gen);

		const partial = gen.throw({
			code: 'prompt_client_error',
			message: 'Down',
		});
		expect(partial.value).toMatchObject({
			type: TYPES.REVIEW_SUCCESS,
			review: { note_count: 2, is_partial: true },
		});

		expect(advance(gen).value).toMatchObject({
			type: TYPES.REVIEW_ERROR,
			error: { code: 'prompt_client_error', message: 'Down' },
		});
		expect(error).toHaveBeenCalled();
	});
});
