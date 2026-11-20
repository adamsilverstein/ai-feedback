/**
 * Suggestion Mode: an AI reply's edit becomes a Gutenberg suggestion, which
 * the author accepts or rejects from the Notes sidebar. A revised edit in the
 * same thread retires the earlier suggestion.
 *
 * Uses the mock AI from tests/mu-plugins/ai-feedback-e2e.php: "make this
 * change" proposes replacing "last year" with "in 2025", and the Shorter
 * quick reply revises that pending edit to "in '25".
 */
const { test, expect } = require('../../fixtures');
const {
	decideSuggestion,
	drainCron,
	getSuggestionMeta,
	markerIds,
	openDraft,
} = require('../utils');

const CONTENT = 'Revenue grew 40% last year.';

/**
 * Open a one-paragraph draft, review it and save the note link.
 *
 * @param {Object} fixtures Playwright fixtures.
 */
async function reviewPost(fixtures) {
	const { editor, aiFeedback } = fixtures;
	await openDraft(
		fixtures,
		'Reply edit suggestion',
		`<!-- wp:paragraph --><p>${CONTENT}</p><!-- /wp:paragraph -->`,
		1
	);
	await aiFeedback.openSidebar();
	const response = await aiFeedback.runReview();
	expect((await response.json()).note_ids[0]).toBeGreaterThan(0);
	// The reply reads the block from the saved post, found by its note link.
	await editor.saveDraft();
}

/**
 * The paragraph's content.
 *
 * @param {Object} editor Editor fixture.
 * @return {Promise<string>} Paragraph HTML.
 */
async function paragraph(editor) {
	const [block] = await editor.getBlocks();
	return String(block.attributes.content);
}

/**
 * Ask for the change and wait for the reply's edit to become a suggestion.
 *
 * @param {Object} fixtures        Playwright fixtures.
 * @param {Object} fixtures.page   Page.
 * @param {Object} fixtures.editor Editor fixture.
 * @return {Promise<number>} ID of the AI reply holding the suggestion.
 */
async function suggestChange({ page, editor }) {
	await page.getByRole('button', { name: 'Make this change' }).click();
	await expect(
		page
			.getByRole('list', { name: 'Conversation replies' })
			.locator('.ai-feedback-reply-ai')
	).toContainText('now reads "in 2025"', { timeout: 30000 });

	await expect.poll(() => paragraph(editor).then(markerIds)).toHaveLength(1);
	const html = await paragraph(editor);
	const [id] = markerIds(html);
	// An add run with the new text, then a del run over the old.
	expect(html).toMatch(
		/^Revenue grew 40% <mark [^>]*wp-suggestion-add[^>]*>in 2025<\/mark><mark [^>]*wp-suggestion-del[^>]*>last year<\/mark>\.$/
	);
	await expect(
		page.getByTestId('snackbar').filter({
			hasText: 'The AI reply’s edit was added as a suggestion.',
		})
	).toBeVisible();
	return id;
}

test.describe('Reply edits as suggestions', () => {
	test.afterEach(drainCron);

	test('a reply edit becomes a pending suggestion', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const fixtures = { page, editor, admin, requestUtils, aiFeedback };
		await reviewPost(fixtures);
		const id = await suggestChange(fixtures);
		expect(await getSuggestionMeta(requestUtils, id)).toMatchObject({
			status: 'pending',
		});

		await editor.saveDraft();
		expect(await getSuggestionMeta(requestUtils, id)).toMatchObject({
			status: 'pending',
		});
	});

	// FIXME: the suggestion sits on the AI reply, and Gutenberg's Notes
	// sidebar only links a thread's root note to its block. The reply's
	// Accept button is disabled ("Target block has been deleted.").
	test.fixme('Accept applies a reply edit suggestion', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const fixtures = { page, editor, admin, requestUtils, aiFeedback };
		await reviewPost(fixtures);
		const id = await suggestChange(fixtures);

		await decideSuggestion(page, 'Accept');
		await expect
			.poll(() => paragraph(editor))
			.toBe('Revenue grew 40% in 2025.');

		await editor.saveDraft();
		await expect
			.poll(() =>
				getSuggestionMeta(requestUtils, id).then((meta) => meta.status)
			)
			.toBe('applied');
	});

	// FIXME: same cause as Accept above. Reject saves `rejected-unsaved` on
	// the reply but has no block to take the markers out of, so the
	// suggested text stays in the post.
	test.fixme('Reject leaves the text as it was', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const fixtures = { page, editor, admin, requestUtils, aiFeedback };
		await reviewPost(fixtures);
		const id = await suggestChange(fixtures);

		await decideSuggestion(page, 'Reject');
		await expect.poll(() => paragraph(editor)).toBe(CONTENT);

		await editor.saveDraft();
		await expect
			.poll(() =>
				getSuggestionMeta(requestUtils, id).then((meta) => meta.status)
			)
			.toBe('rejected');
	});

	/**
	 * Ask for the change, then a shorter version, and wait for the revision
	 * to replace the first suggestion in the text.
	 *
	 * @param {Object} fixtures Playwright fixtures.
	 * @return {Promise<{first: number, second: number}>} IDs of the retired and the new suggestion.
	 */
	async function reviseChange(fixtures) {
		const { page, editor, requestUtils } = fixtures;
		await reviewPost(fixtures);
		const first = await suggestChange(fixtures);

		await page
			.getByRole('group', { name: 'Quick replies' })
			.getByRole('button', { name: 'Shorter' })
			.click();
		await expect(
			page
				.getByRole('list', { name: 'Conversation replies' })
				.locator('.ai-feedback-reply-ai')
				.nth(1)
		).toContainText('Revised suggestion (2)', { timeout: 30000 });

		// Only the revision is in the text; the first suggestion is retired.
		await expect
			.poll(async () => {
				const ids = markerIds(await paragraph(editor));
				return ids.length === 1 && ids[0] !== first;
			})
			.toBe(true);
		const [second] = markerIds(await paragraph(editor));
		expect(await paragraph(editor)).toMatch(
			/^Revenue grew 40% <mark [^>]*wp-suggestion-add[^>]*>in '25<\/mark><mark [^>]*wp-suggestion-del[^>]*>last year<\/mark>\.$/
		);
		expect(await getSuggestionMeta(requestUtils, first)).toMatchObject({
			status: 'rejected-unsaved',
		});
		expect(await getSuggestionMeta(requestUtils, second)).toMatchObject({
			status: 'pending',
		});
		return { first, second };
	}

	test('a revised edit retires the earlier suggestion', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		await reviseChange({ page, editor, admin, requestUtils, aiFeedback });
	});

	// FIXME: Gutenberg finalizes `rejected-unsaved` on save only for root
	// notes (its reconciler queries `parent => 0`), and the retired
	// suggestion is on a reply, so it stays `rejected-unsaved` for good.
	test.fixme('saving makes the retirement final', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const { first, second } = await reviseChange({
			page,
			editor,
			admin,
			requestUtils,
			aiFeedback,
		});

		await editor.saveDraft();
		await expect
			.poll(() =>
				Promise.all(
					[first, second].map((id) =>
						getSuggestionMeta(requestUtils, id).then(
							(meta) => meta.status
						)
					)
				)
			)
			.toEqual(['rejected', 'pending']);
	});
});
