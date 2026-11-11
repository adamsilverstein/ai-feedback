jest.mock('@wordpress/block-editor', () => ({
	store: 'core/block-editor',
}));

/**
 * Internal dependencies
 */
import { startReview } from '../actions';
import { TYPES } from '../reducer';

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
 * Run the startReview generator up to its first review request.
 *
 * @param {Object} options startReview options.
 * @return {Object} Generator and the first apiFetch control.
 */
function startUntilFirstRequest(options) {
	const gen = startReview({ postId: 1, title: 'Title', ...options });

	expect(gen.next().value).toEqual({ type: TYPES.START_REVIEW });
	// Feedback history request.
	gen.next();

	return gen;
}

describe('startReview', () => {
	afterEach(() => {
		// The store logs each step of a review for debugging.
		// eslint-disable-next-line jest/no-standalone-expect
		expect(console).toHaveLogged();
	});

	it('sends a single request for small documents', () => {
		const gen = startUntilFirstRequest({
			blocks: makeBlocks(3),
			chunkSize: 20,
		});

		const request = gen.next({ has_history: false }).value;
		expect(request.request.data.blocks).toHaveLength(3);
		expect(request.request.data).not.toHaveProperty('chunk_index');

		const response = reviewResponse({ summary_text: 'Done.' });
		expect(gen.next(response)).toEqual({
			done: true,
			value: { type: TYPES.REVIEW_SUCCESS, review: response },
		});
	});

	it('reviews large documents in chunks and reports progress', () => {
		const gen = startUntilFirstRequest({
			blocks: makeBlocks(5),
			chunkSize: 2,
		});

		expect(gen.next({ has_history: false }).value).toEqual({
			type: TYPES.SET_REVIEW_PROGRESS,
			progress: { current: 1, total: 3 },
		});

		const first = gen.next().value.request.data;
		expect(first).toMatchObject({ chunk_index: 0, chunk_total: 3 });
		expect(first).not.toHaveProperty('review_id');

		expect(
			gen.next(reviewResponse({ summary_text: 'Part one.' })).value
		).toEqual({
			type: TYPES.SET_REVIEW_PROGRESS,
			progress: { current: 2, total: 3 },
		});

		const second = gen.next().value.request.data;
		expect(second).toMatchObject({
			chunk_index: 1,
			review_id: 'review-1',
		});
		expect(second).not.toHaveProperty('previous_summaries');

		gen.next(reviewResponse({ summary_text: 'Part two.' }));

		const third = gen.next().value.request.data;
		expect(third).toMatchObject({
			chunk_index: 2,
			blocks: [expect.objectContaining({ clientId: 'block-4' })],
			previous_summaries: ['Part one.', 'Part two.'],
		});

		const result = gen.next(
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

		gen.next({ has_history: false });
		gen.next();
		gen.next(reviewResponse({ note_count: 2 }));
		gen.next();

		const partial = gen.throw({
			code: 'prompt_client_error',
			message: 'Down',
		});
		expect(partial.value).toMatchObject({
			type: TYPES.REVIEW_SUCCESS,
			review: { note_count: 2, is_partial: true },
		});

		expect(gen.next().value).toMatchObject({
			type: TYPES.REVIEW_ERROR,
			error: { code: 'prompt_client_error', message: 'Down' },
		});
		expect(console).toHaveErrored();
	});
});
