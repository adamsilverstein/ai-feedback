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
	expect(html).toMatch(
		new RegExp(
			`<mark class="wp-suggestion-add" data-suggestion-id="${id}"[^>]*>in 2025</mark>` +
				`<mark class="wp-suggestion-del" data-suggestion-id="${id}"[^>]*>last year</mark>`
		)
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

	test('Accept applies a reply edit suggestion', async ({
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

	test('Reject leaves the text as it was', async ({
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

	test('a revised edit retires the earlier suggestion', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const fixtures = { page, editor, admin, requestUtils, aiFeedback };
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
			.poll(() => paragraph(editor).then(markerIds))
			.toEqual([expect.any(Number)]);
		const [second] = markerIds(await paragraph(editor));
		expect(second).not.toBe(first);
		expect(await paragraph(editor)).toContain(`>in '25</mark>`);
		expect(await getSuggestionMeta(requestUtils, first)).toMatchObject({
			status: 'rejected-unsaved',
		});

		await decideSuggestion(page, 'Accept');
		await expect
			.poll(() => paragraph(editor))
			.toBe("Revenue grew 40% in '25.");

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
			.toEqual(['rejected', 'applied']);
	});
});
