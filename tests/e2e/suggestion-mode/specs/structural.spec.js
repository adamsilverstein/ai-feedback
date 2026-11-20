/**
 * Suggestion Mode: a review's move and remove become Gutenberg structural
 * suggestions, which the author accepts or rejects from the Notes sidebar.
 *
 * Uses the mock AI from tests/mu-plugins/ai-feedback-e2e.php: a review of
 * this post moves "The real opening" above "Background", removes "This
 * repeats section two" and adds a heading above "Next steps". The insert
 * stays with Preview and Apply in the Fresh Eyes sidebar.
 */
const { test, expect } = require('../../fixtures');
const {
	blockTexts,
	decideSuggestion,
	drainCron,
	getSuggestionMeta,
	openDraft,
} = require('../utils');

const HEADING = 'Why this matters';
const PARAGRAPHS = [
	'Background on the project.',
	'The real opening of the piece.',
	'This repeats section two.',
	'Next steps follow.',
];
const MOVE = 'Lead with the real opening';
const REMOVE = 'Cut the repetition';

/**
 * Open the fixture post, review it and wait for both suggestions.
 *
 * @param {Object} fixtures Playwright fixtures.
 * @return {Promise<{move: number, remove: number}>} Note IDs of the two suggestions.
 */
async function reviewPost(fixtures) {
	const { editor, aiFeedback } = fixtures;
	await openDraft(
		fixtures,
		'Structural suggestions',
		[
			`<!-- wp:heading --><h2 class="wp-block-heading">${HEADING}</h2><!-- /wp:heading -->`,
			...PARAGRAPHS.map(
				(text) =>
					`<!-- wp:paragraph --><p>${text}</p><!-- /wp:paragraph -->`
			),
		].join(''),
		5
	);
	await aiFeedback.openSidebar();
	const response = await aiFeedback.runReview();
	expect((await response.json()).note_ids).toHaveLength(3);

	// The move is made and tagged, the removal only tagged.
	await expect
		.poll(() => blockTexts(editor))
		.toEqual([
			HEADING,
			PARAGRAPHS[1],
			PARAGRAPHS[0],
			PARAGRAPHS[2],
			PARAGRAPHS[3],
		]);
	// Suggestions are added one after another.
	await expect
		.poll(() => suggestionMarkers(editor))
		.toEqual({
			[PARAGRAPHS[1]]: expect.objectContaining({ type: 'pending-move' }),
			[PARAGRAPHS[2]]: expect.objectContaining({
				type: 'pending-remove',
			}),
		});
	const markers = await suggestionMarkers(editor);

	return {
		move: markers[PARAGRAPHS[1]].commentId,
		remove: markers[PARAGRAPHS[2]].commentId,
	};
}

/**
 * Structural suggestion markers, by the text of the block holding them.
 *
 * @param {Object} editor Editor fixture.
 * @return {Promise<Object>} Markers keyed by block text.
 */
async function suggestionMarkers(editor) {
	const markers = {};
	for (const block of await editor.getBlocks()) {
		if (block.attributes.metadata?.suggestion) {
			markers[String(block.attributes.content)] =
				block.attributes.metadata.suggestion;
		}
	}
	return markers;
}

/**
 * Wait for saved suggestion statuses.
 *
 * @param {Object}   requestUtils Request utils fixture.
 * @param {number[]} ids          Note IDs.
 * @param {string[]} statuses     Expected statuses, in the same order.
 */
async function expectStatuses(requestUtils, ids, statuses) {
	await expect
		.poll(() =>
			Promise.all(
				ids.map((id) =>
					getSuggestionMeta(requestUtils, id).then(
						(meta) => meta.status
					)
				)
			)
		)
		.toEqual(statuses);
}

test.describe('Structural suggestions', () => {
	test.afterEach(drainCron);

	test('accept the move and reject the removal', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const fixtures = { page, editor, admin, requestUtils, aiFeedback };
		const ids = await reviewPost(fixtures);

		await decideSuggestion(page, 'Accept', MOVE);
		await decideSuggestion(page, 'Reject', REMOVE);

		// The block stays where it was moved, and the other is kept.
		await expect
			.poll(() => blockTexts(editor))
			.toEqual([
				HEADING,
				PARAGRAPHS[1],
				PARAGRAPHS[0],
				PARAGRAPHS[2],
				PARAGRAPHS[3],
			]);
		expect(await suggestionMarkers(editor)).toEqual({});

		await editor.saveDraft();
		await expectStatuses(
			requestUtils,
			[ids.move, ids.remove],
			['applied', 'rejected']
		);
	});

	test('reject the move and accept the removal', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const fixtures = { page, editor, admin, requestUtils, aiFeedback };
		const ids = await reviewPost(fixtures);

		await decideSuggestion(page, 'Reject', MOVE);
		await decideSuggestion(page, 'Accept', REMOVE);

		// The block goes back, and the repeated one is gone.
		await expect
			.poll(() => blockTexts(editor))
			.toEqual([HEADING, PARAGRAPHS[0], PARAGRAPHS[1], PARAGRAPHS[3]]);
		expect(await suggestionMarkers(editor)).toEqual({});

		await editor.saveDraft();
		await expectStatuses(
			requestUtils,
			[ids.move, ids.remove],
			['rejected', 'applied']
		);
	});
});
