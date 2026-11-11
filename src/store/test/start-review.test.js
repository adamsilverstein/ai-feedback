/**
 * External dependencies
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

/**
 * Internal dependencies
 */
import { fetchPreviousReview, startReview } from '../actions';
import { TYPES } from '../reducer';
import {
	removeOrphanedSuggestions,
	suggestQuickFixes,
} from '../../utils/suggestions';

vi.mock('@wordpress/block-editor', () => ({
	store: 'core/block-editor',
}));
vi.mock('../../utils/suggestions', () => ({
	removeOrphanedSuggestions: vi.fn(() => Promise.resolve(0)),
	suggestQuickFixes: vi.fn(() => Promise.resolve(0)),
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
		vi.clearAllMocks();
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

	it('turns the quick fixes from every chunk into suggestions', () => {
		const gen = startUntilFirstRequest({
			blocks: makeBlocks(4),
			chunkSize: 2,
		});
		const first = { id: 1, quick_fix: { find: 'a', replace: 'b' } };
		const second = { id: 2 };

		advance(gen, { has_history: false });
		advance(gen);
		advance(gen, reviewResponse({ notes: [first] }));
		advance(gen);

		expect(suggestQuickFixes).not.toHaveBeenCalled();
		const result = advance(gen, reviewResponse({ notes: [second] }));

		expect(result.value.type).toBe(TYPES.REVIEW_SUCCESS);
		expect(suggestQuickFixes).toHaveBeenCalledTimes(1);
		expect(suggestQuickFixes).toHaveBeenCalledWith([first, second]);
	});

	it('logs instead of rejecting when adding suggestions fails', async () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
		suggestQuickFixes.mockReturnValueOnce(Promise.reject(new Error('x')));
		const gen = startUntilFirstRequest({ blocks: makeBlocks(1) });

		advance(gen, { has_history: false });
		expect(advance(gen, reviewResponse()).value.type).toBe(
			TYPES.REVIEW_SUCCESS
		);

		await vi.waitFor(() => expect(warn).toHaveBeenCalled());
		warn.mockRestore();
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
			advance(gen, reviewResponse({ summary_text: 'Part one.' })).value
		).toEqual({
			type: TYPES.SET_REVIEW_PROGRESS,
			progress: { current: 2, total: 3 },
		});

		const second = advance(gen).value.request.data;
		expect(second).toMatchObject({
			chunk_index: 1,
			review_id: 'review-1',
		});
		expect(second).not.toHaveProperty('previous_summaries');

		advance(gen, reviewResponse({ summary_text: 'Part two.' }));

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
		expect(result.done).toBe(true);
		expect(result.value.type).toBe(TYPES.REVIEW_SUCCESS);
		expect(result.value.review).toMatchObject({
			summary_text: 'Whole document.',
			chunks: 3,
		});
	});

	it('keeps finished chunks when a later chunk fails', () => {
		const gen = startUntilFirstRequest({
			blocks: makeBlocks(4),
			chunkSize: 2,
		});

		advance(gen, { has_history: false });
		advance(gen);
		const note = { id: 1, quick_fix: { find: 'a', replace: 'b' } };
		advance(gen, reviewResponse({ note_count: 2, notes: [note] }));
		advance(gen);

		const partial = gen.throw({
			code: 'prompt_client_error',
			message: 'Down',
		});
		expect(partial.value).toMatchObject({
			type: TYPES.REVIEW_SUCCESS,
			review: { note_count: 2, is_partial: true },
		});
		// Fixes from the parts that finished still become suggestions.
		expect(suggestQuickFixes).toHaveBeenCalledWith([note]);

		expect(advance(gen).value).toMatchObject({
			type: TYPES.REVIEW_ERROR,
			error: { code: 'prompt_client_error', message: 'Down' },
		});
		expect(error).toHaveBeenCalled();
	});
});

describe('fetchPreviousReview', () => {
	beforeEach(() => {
		vi.spyOn(console, 'log').mockImplementation(() => {});
		vi.clearAllMocks();
	});

	afterEach(() => {
		vi.restoreAllMocks();
	});

	it.each([
		['with', { has_review: true, review: reviewResponse() }],
		['without', { has_review: false, review: null }],
	])(
		'cleans up orphaned suggestions for the post %s a review',
		(label, response) => {
			const gen = fetchPreviousReview(42);

			expect(gen.next().value).toEqual({
				type: TYPES.FETCHING_PREVIOUS_REVIEW,
			});
			gen.next();
			const result = gen.next(response);

			expect(result.value.type).toBe(TYPES.RECEIVE_PREVIOUS_REVIEW);
			expect(removeOrphanedSuggestions).toHaveBeenCalledWith(42);
		}
	);
});
