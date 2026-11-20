/**
 * Suggestion Mode in Suggest intent: Gutenberg records any move as the
 * current user's suggestion, so a review's move must not also become one on
 * the AI note. The move ends up suggested once, by Gutenberg.
 *
 * Uses the same mock review as structural.spec.js: it moves "The real
 * opening" above "Background", removes "This repeats section two" and adds
 * a heading above "Next steps".
 */
const { test, expect } = require('../../fixtures');
const { blockTexts, drainCron, openDraft } = require('../utils');

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
 * Switch the editor to Suggest intent from the Options menu.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function switchToSuggesting(page) {
	await page
		.getByRole('region', { name: 'Editor top bar' })
		.getByRole('button', { name: 'Options' })
		.click();
	const suggesting = page.getByRole('menuitemradio', {
		name: /^Suggesting/,
	});
	await suggesting.click();
	await expect(suggesting).toHaveAttribute('aria-checked', 'true');
	await page.keyboard.press('Escape');
}

/**
 * The post's notes, with their suggestion meta.
 *
 * @param {Object} requestUtils Request utils fixture.
 * @param {number} postId       Post ID.
 * @return {Promise<Object[]>} Notes.
 */
function getNotes(requestUtils, postId) {
	return requestUtils.rest({
		path: '/wp/v2/comments',
		params: {
			post: postId,
			type: 'note',
			status: 'all',
			context: 'edit',
			per_page: 100,
		},
	});
}

/**
 * Operation types of every pending suggestion on the post, sorted.
 *
 * @param {Object[]} notes Notes.
 * @return {string[]} One entry per pending suggestion operation.
 */
function pendingOperations(notes) {
	return notes
		.filter(
			(note) =>
				note.meta?._wp_suggestion &&
				(note.meta._wp_suggestion_status || 'pending') === 'pending'
		)
		.flatMap((note) =>
			JSON.parse(note.meta._wp_suggestion).operations.map(
				(operation) => operation.type
			)
		)
		.sort();
}

test.describe('Structural suggestions in Suggest intent', () => {
	test.afterEach(drainCron);

	test('a review’s move is suggested once, by Gutenberg', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const post = await openDraft(
			{ admin, editor, requestUtils },
			'Structural suggestions in Suggest intent',
			[
				`<!-- wp:heading --><h2 class="wp-block-heading">${HEADING}</h2><!-- /wp:heading -->`,
				...PARAGRAPHS.map(
					(text) =>
						`<!-- wp:paragraph --><p>${text}</p><!-- /wp:paragraph -->`
				),
			].join(''),
			5
		);
		await switchToSuggesting(page);
		await aiFeedback.openSidebar();
		const response = await aiFeedback.runReview();
		expect((await response.json()).note_ids).toHaveLength(3);

		await expect
			.poll(() => blockTexts(editor))
			.toEqual([
				HEADING,
				PARAGRAPHS[1],
				PARAGRAPHS[0],
				PARAGRAPHS[2],
				PARAGRAPHS[3],
			]);

		// Gutenberg's auto-save posts its own note for the move; the AI note
		// keeps only the removal.
		await expect
			.poll(async () =>
				pendingOperations(await getNotes(requestUtils, post.id))
			)
			.toEqual(['block-move', 'block-remove']);

		const notes = await getNotes(requestUtils, post.id);
		const byText = (text) =>
			notes.find((note) => note.content.raw.includes(text));
		expect(byText(MOVE).meta._wp_suggestion || '').toBe('');
		expect(byText(REMOVE).meta._wp_suggestion_status).toBe('pending');

		// Only Gutenberg's marker holds the move: none carries the AI note.
		const markers = (await editor.getBlocks())
			.map((block) => block.attributes.metadata?.suggestion)
			.filter(Boolean);
		expect(markers).toEqual(
			expect.arrayContaining([
				expect.objectContaining({
					type: 'pending-remove',
					commentId: byText(REMOVE).id,
				}),
				expect.objectContaining({ type: 'pending-move' }),
			])
		);
		expect(
			markers.filter((marker) => marker.type === 'pending-move')
		).toEqual([
			expect.not.objectContaining({ commentId: byText(MOVE).id }),
		]);

		// Both cards read as suggested: the removal on the AI note, the move
		// held by Gutenberg. Neither says the move was already applied.
		await expect(
			page.getByText(
				'Suggested in the post. Accept or reject it from the Notes sidebar.'
			)
		).toHaveCount(2);
		await expect(
			page.getByText('The post already has this change.')
		).toHaveCount(0);
	});
});
