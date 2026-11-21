/**
 * Suggestion Mode in Suggest intent: taking back a review's pending move.
 * Gutenberg records any move it sees in Suggest intent as the current
 * user's suggestion, unless the move lands in the same store update that
 * clears the block's pending-move marker. So the move back must not become
 * a new suggestion, whether the author rejects the move or a newer edit in
 * the thread retires it.
 *
 * Uses the mock AI from tests/mu-plugins/ai-feedback-e2e.php: the review
 * moves "The real opening" above "Background", removes "This repeats section
 * two" and adds a heading above "Next steps". On the move's note, a reply
 * asking to "reword it" proposes a text edit on that block, and one asking
 * to "move it" moves the block above the heading.
 */
const { test, expect } = require('../../fixtures');
const {
	blockTexts,
	decideSuggestion,
	drainCron,
	getNotes,
	openDraft,
	pendingOperations,
	switchToSuggesting,
} = require('../utils');

const HEADING = 'Why this matters';
const PARAGRAPHS = [
	'Background on the project.',
	'The real opening of the piece.',
	'This repeats section two.',
	'Next steps follow.',
];
const MOVE = 'Lead with the real opening';

/**
 * How long to give Gutenberg's auto-save to post a captured move as a note.
 */
const CAPTURE_WAIT = 3000;

/**
 * Review the fixture post in Edit intent, so the move is the AI note's own
 * suggestion, then switch to Suggest intent.
 *
 * @param {Object} fixtures Playwright fixtures.
 * @return {Promise<{post: Object, moveNote: Object}>} The post and the move's note.
 */
async function reviewThenSuggest(fixtures) {
	const { page, editor, requestUtils, aiFeedback } = fixtures;
	const post = await openDraft(
		fixtures,
		'Retiring a move in Suggest intent',
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
	await expect
		.poll(
			async () =>
				pendingOperations(await getNotes(requestUtils, post.id)),
			{ timeout: 30000 }
		)
		.toEqual(['block-move', 'block-remove']);
	const moveNote = (await getNotes(requestUtils, post.id)).find((note) =>
		note.content.raw.includes(MOVE)
	);
	// A reply reads the block from the saved post.
	await editor.saveDraft();

	await switchToSuggesting(page);
	return { post, moveNote };
}

/**
 * Reply to a note as the author.
 *
 * @param {import('@playwright/test').Page} page    Page.
 * @param {number}                          parent  Note to reply to.
 * @param {string}                          content Reply text.
 */
async function replyToNote(page, parent, content) {
	await page.evaluate(
		async (reply) => {
			const { select, dispatch } = window.wp.data;
			await dispatch('core').saveEntityRecord(
				'root',
				'comment',
				{
					post: select('core/editor').getCurrentPostId(),
					content: reply.content,
					status: 'hold',
					type: 'note',
					parent: reply.parent,
				},
				{ throwOnError: true }
			);
		},
		{ parent, content }
	);
}

/**
 * The `pending-move` markers on the post's top-level blocks.
 *
 * @param {Object} editor Editor fixture.
 * @return {Promise<Object[]>} Markers.
 */
async function pendingMoveMarkers(editor) {
	return (await editor.getBlocks())
		.map((block) => block.attributes.metadata?.suggestion)
		.filter((marker) => marker?.type === 'pending-move');
}

/**
 * The pending move operations saved on the post's notes.
 *
 * @param {Object} requestUtils Request utils fixture.
 * @param {number} postId       Post ID.
 * @return {Promise<string[]>} One entry per pending move.
 */
async function pendingMoves(requestUtils, postId) {
	return pendingOperations(await getNotes(requestUtils, postId)).filter(
		(type) => type === 'block-move'
	);
}

/**
 * Wait for the move's note to be retired by a newer edit.
 *
 * @param {Object} requestUtils Request utils fixture.
 * @param {number} postId       Post ID.
 * @param {number} noteId       The move's note.
 */
async function expectRetired(requestUtils, postId, noteId) {
	await expect
		.poll(
			async () =>
				(await getNotes(requestUtils, postId)).find(
					(note) => note.id === noteId
				).meta._wp_suggestion_status,
			{ timeout: 30000 }
		)
		.toBe('rejected-unsaved');
}

test.describe('Retiring a move in Suggest intent', () => {
	test.afterEach(drainCron);

	test('rejecting the move leaves no pending move', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const { post } = await reviewThenSuggest({
			page,
			editor,
			admin,
			requestUtils,
			aiFeedback,
		});

		await decideSuggestion(page, 'Reject', MOVE);

		await expect
			.poll(() => blockTexts(editor))
			.toEqual([HEADING, ...PARAGRAPHS]);
		await page.waitForTimeout(CAPTURE_WAIT);
		expect(await pendingMoveMarkers(editor)).toEqual([]);
		// Only the review's removal is still pending.
		expect(
			pendingOperations(await getNotes(requestUtils, post.id))
		).toEqual(['block-remove']);
	});

	test('a newer edit puts the block back without suggesting the move back', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const { post, moveNote } = await reviewThenSuggest({
			page,
			editor,
			admin,
			requestUtils,
			aiFeedback,
		});

		await replyToNote(page, moveNote.id, 'Please reword it.');

		// The reply's text edit retires the move: the block goes back.
		await expect
			.poll(() => blockTexts(editor), { timeout: 30000 })
			.toEqual([HEADING, ...PARAGRAPHS]);
		await expectRetired(requestUtils, post.id, moveNote.id);

		await page.waitForTimeout(CAPTURE_WAIT);
		expect(await pendingMoveMarkers(editor)).toEqual([]);
		expect(await pendingMoves(requestUtils, post.id)).toEqual([]);
	});

	test('a revised move is the only pending move', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const { post, moveNote } = await reviewThenSuggest({
			page,
			editor,
			admin,
			requestUtils,
			aiFeedback,
		});

		await replyToNote(page, moveNote.id, 'Please move it to the top.');

		// The reply's move retires the review's: the block goes back first.
		await expect
			.poll(() => blockTexts(editor), { timeout: 30000 })
			.toEqual([HEADING, ...PARAGRAPHS]);
		await expectRetired(requestUtils, post.id, moveNote.id);
		expect(await pendingMoveMarkers(editor)).toEqual([]);

		// A reply's move stays with Preview and Apply. Applied in Suggest
		// intent, Gutenberg records it as the current user's suggestion.
		await page
			.locator('.ai-feedback-item', { hasText: MOVE })
			.locator('.ai-feedback-reply-ai')
			.getByRole('button', { name: 'Apply change' })
			.click();
		await expect
			.poll(() => blockTexts(editor))
			.toEqual([
				PARAGRAPHS[1],
				HEADING,
				PARAGRAPHS[0],
				PARAGRAPHS[2],
				PARAGRAPHS[3],
			]);

		await expect
			.poll(() => pendingMoves(requestUtils, post.id))
			.toEqual(['block-move']);
		await page.waitForTimeout(CAPTURE_WAIT);
		expect(await pendingMoves(requestUtils, post.id)).toEqual([
			'block-move',
		]);
		expect(await pendingMoveMarkers(editor)).toEqual([
			expect.not.objectContaining({ commentId: moveNote.id }),
		]);
	});
});
