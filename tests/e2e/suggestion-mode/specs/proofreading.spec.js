/**
 * Suggestion Mode: proofreading fixes become suggestions, and Accept all
 * accepts the pending ones together with the rest; each can also be accepted
 * or rejected on its own from Gutenberg's Notes sidebar. Pending suggestions
 * whose markers are lost go back to being plain notes.
 *
 * Uses the mock AI from tests/mu-plugins/ai-feedback-e2e.php, which finds
 * seven typos in this post. A block takes one suggestion at a time, so the
 * first fix in each of the four paragraphs becomes a suggestion and the
 * other three stay plain fixes.
 */
const { test, expect } = require('../../fixtures');
const {
	blockTexts,
	decideSuggestion,
	drainCron,
	getSuggestionMeta,
	markerIds,
	openDraft,
} = require('../utils');

const CONTENT = [
	'<!-- wp:paragraph --><p>Teh <a href="https://example.com">quick brwon</a> fox jumpd over.</p><!-- /wp:paragraph -->',
	'<!-- wp:paragraph --><p>You <strong>recieve</strong> it alot.</p><!-- /wp:paragraph -->',
	'<!-- wp:paragraph --><p>A wierd day.</p><!-- /wp:paragraph -->',
	'<!-- wp:paragraph --><p>Thier plan works.</p><!-- /wp:paragraph -->',
].join('');

/**
 * Open the fixture post, review it and wait for the four suggestions.
 *
 * @param {Object} fixtures Playwright fixtures.
 * @return {Promise<{post: Object, suggested: number[]}>} The post and the IDs of the notes made suggestions.
 */
async function reviewPost(fixtures) {
	const { editor, aiFeedback } = fixtures;
	const post = await openDraft(
		fixtures,
		'Proofreading suggestions',
		CONTENT,
		4
	);
	await aiFeedback.openSidebar();
	const response = await aiFeedback.runReview();
	expect((await response.json()).note_ids).toHaveLength(7);

	// Each suggestion waits on its own note write, one after another.
	await expect
		.poll(
			() =>
				blockTexts(editor).then((texts) => texts.map(markerIds).flat()),
			{ timeout: 20000 }
		)
		.toHaveLength(4);
	const suggested = (await blockTexts(editor)).map(markerIds).flat();
	await expect(
		fixtures.page.getByTestId('snackbar').filter({
			hasText: '4 AI edits were added as suggestions.',
		})
	).toBeVisible();
	return { post, suggested };
}

test.describe('Proofreading suggestions', () => {
	test.afterEach(drainCron);

	test('Accept all accepts pending suggestions with the plain fixes', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const fixtures = { page, editor, admin, requestUtils, aiFeedback };
		const { suggested } = await reviewPost(fixtures);
		for (const id of suggested) {
			expect(await getSuggestionMeta(requestUtils, id)).toMatchObject({
				status: 'pending',
			});
		}

		const group = page.locator('.ai-feedback-proofreading');
		await group.getByRole('button', { name: 'Accept all' }).click();
		await expect(
			page.getByTestId('snackbar').filter({ hasText: '7 fixes applied.' })
		).toBeVisible();
		await expect
			.poll(() => blockTexts(editor))
			.toEqual([
				'The <a href="https://example.com">quick brown</a> fox jumped over.',
				'You <strong>receive</strong> it a lot.',
				'A weird day.',
				'Their plan works.',
			]);

		await editor.saveDraft();
		await expect
			.poll(() =>
				Promise.all(
					suggested.map((id) =>
						getSuggestionMeta(requestUtils, id).then(
							(meta) => meta.status
						)
					)
				)
			)
			.toEqual(suggested.map(() => 'applied'));
	});

	test('Accept and Reject one suggestion each from the Notes sidebar', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const fixtures = { page, editor, admin, requestUtils, aiFeedback };
		await reviewPost(fixtures);
		const [, , weird, their] = (await blockTexts(editor)).map(
			(text) => markerIds(text)[0]
		);

		await decideSuggestion(page, 'Accept', 'Thier');
		await decideSuggestion(page, 'Reject', 'wierd');

		await expect
			.poll(() => blockTexts(editor).then((texts) => texts.slice(2)))
			.toEqual(['A wierd day.', 'Their plan works.']);

		await editor.saveDraft();
		await expect
			.poll(() =>
				Promise.all(
					[their, weird].map((id) =>
						getSuggestionMeta(requestUtils, id).then(
							(meta) => meta.status
						)
					)
				)
			)
			.toEqual(['applied', 'rejected']);
	});

	test('pending suggestions whose markers are lost become plain notes', async ({
		page,
		context,
		baseURL,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const fixtures = { page, editor, admin, requestUtils, aiFeedback };
		const { post, suggested } = await reviewPost(fixtures);

		// The editor closes without saving, so the markers are lost.
		await requestUtils.rest({
			method: 'POST',
			path: `/wp/v2/posts/${post.id}`,
			data: { content: CONTENT },
		});
		await context.addCookies([
			{ name: 'ai_feedback_e2e_cleanup_delay', value: '0', url: baseURL },
		]);
		page.on('dialog', (dialog) => dialog.accept());
		await admin.editPost(post.id);
		// The cleanup runs when the Fresh Eyes sidebar loads the last review.
		await aiFeedback.openSidebar();

		await expect
			.poll(() =>
				Promise.all(
					suggested.map((id) => getSuggestionMeta(requestUtils, id))
				)
			)
			.toEqual(suggested.map(() => ({ payload: '', status: '' })));
	});
});
