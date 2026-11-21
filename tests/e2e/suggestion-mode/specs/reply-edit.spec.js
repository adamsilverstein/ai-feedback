/**
 * Suggestion Mode: an AI reply's edit becomes a Gutenberg suggestion on its
 * thread's root note, the only note Gutenberg links to the block, so the
 * author can accept or reject it from the Notes sidebar. A revised edit in
 * the same thread replaces it.
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
 * @return {Promise<number>} ID of the review's note, the thread's root.
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
	const [rootId] = (await response.json()).note_ids;
	expect(rootId).toBeGreaterThan(0);
	// The reply reads the block from the saved post, found by its note link.
	await editor.saveDraft();
	return rootId;
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
 * The AI reply whose edit a root note's suggestion is.
 *
 * @param {Object} requestUtils Request utils fixture.
 * @param {number} rootId       Root note.
 * @return {Promise<number|null>} Reply ID named in the payload, or null.
 */
async function getEditOwner(requestUtils, rootId) {
	const { payload } = await getSuggestionMeta(requestUtils, rootId);
	return payload ? (JSON.parse(payload).aiFeedbackEditOwner ?? null) : null;
}

/**
 * Wait for an AI reply's edit to be the root note's pending suggestion.
 *
 * @param {Object} fixtures              Playwright fixtures.
 * @param {Object} fixtures.page         Page.
 * @param {Object} fixtures.editor       Editor fixture.
 * @param {Object} fixtures.requestUtils Request utils fixture.
 * @param {number} rootId                Root note.
 * @param {string} replacement           Text the edit puts in.
 * @return {Promise<number>} ID of the AI reply whose edit it is.
 */
async function expectSuggested(
	{ page, editor, requestUtils },
	rootId,
	replacement
) {
	const add = replacement.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	await expect
		.poll(() => paragraph(editor))
		.toMatch(
			new RegExp(
				`^Revenue grew 40% <mark [^>]*wp-suggestion-add[^>]*>${add}</mark><mark [^>]*wp-suggestion-del[^>]*>last year</mark>\\.$`
			)
		);
	// The markers carry the root note's id, not the reply's.
	expect(markerIds(await paragraph(editor))).toEqual([rootId]);

	await expect
		.poll(() => getSuggestionMeta(requestUtils, rootId))
		.toMatchObject({ status: 'pending' });
	const ownerId = await getEditOwner(requestUtils, rootId);
	const owner = await requestUtils.rest({
		path: `/wp/v2/comments/${ownerId}`,
		params: { context: 'edit' },
	});
	expect(owner.parent).toBe(rootId);
	// Nothing is stored on the reply itself.
	expect(await getSuggestionMeta(requestUtils, ownerId)).toMatchObject({
		payload: '',
	});

	await expect(
		page
			.getByTestId('snackbar')
			.filter({
				hasText: 'The AI reply’s edit was added as a suggestion.',
			})
			.last()
	).toBeVisible();
	return ownerId;
}

/**
 * Ask for the change and wait for it to become the root note's suggestion.
 *
 * @param {Object} fixtures Playwright fixtures.
 * @param {number} rootId   Root note.
 * @return {Promise<number>} ID of the AI reply whose edit it is.
 */
async function suggestChange(fixtures, rootId) {
	const { page } = fixtures;
	await page.getByRole('button', { name: 'Make this change' }).click();
	await expect(
		page
			.getByRole('list', { name: 'Conversation replies' })
			.locator('.ai-feedback-reply-ai')
	).toContainText('now reads "in 2025"', { timeout: 30000 });
	return expectSuggested(fixtures, rootId, 'in 2025');
}

/**
 * Wait for a root note's saved suggestion status.
 *
 * @param {Object} requestUtils Request utils fixture.
 * @param {number} rootId       Root note.
 * @param {string} status       Expected status.
 */
async function expectStatus(requestUtils, rootId, status) {
	await expect
		.poll(() =>
			getSuggestionMeta(requestUtils, rootId).then((meta) => meta.status)
		)
		.toBe(status);
}

test.describe('Reply edits as suggestions', () => {
	test.afterEach(drainCron);

	test('a reply edit becomes a pending suggestion on the thread’s note', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const fixtures = { page, editor, admin, requestUtils, aiFeedback };
		const rootId = await reviewPost(fixtures);
		await suggestChange(fixtures, rootId);

		// The Fresh Eyes sidebar shows it on the reply that proposed it.
		await expect(
			page
				.getByRole('list', { name: 'Conversation replies' })
				.locator('.ai-feedback-reply-ai')
		).toContainText(
			'Suggested in the text. Accept or reject it from the Notes sidebar.'
		);

		await editor.saveDraft();
		await expectStatus(requestUtils, rootId, 'pending');
	});

	test('Accept applies a reply edit suggestion', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const fixtures = { page, editor, admin, requestUtils, aiFeedback };
		const rootId = await reviewPost(fixtures);
		const ownerId = await suggestChange(fixtures, rootId);

		await decideSuggestion(page, 'Accept');
		await expect
			.poll(() => paragraph(editor))
			.toBe('Revenue grew 40% in 2025.');
		// Accept opened Gutenberg's Notes sidebar in its place.
		await aiFeedback.openSidebar();
		await expect(
			page
				.getByRole('list', { name: 'Conversation replies' })
				.locator('.ai-feedback-reply-ai')
		).toContainText('You accepted this suggested edit.');

		await editor.saveDraft();
		await expectStatus(requestUtils, rootId, 'applied');
		expect(await getEditOwner(requestUtils, rootId)).toBe(ownerId);
	});

	test('Reject leaves the text as it was', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const fixtures = { page, editor, admin, requestUtils, aiFeedback };
		const rootId = await reviewPost(fixtures);
		await suggestChange(fixtures, rootId);

		await decideSuggestion(page, 'Reject');
		await expect.poll(() => paragraph(editor)).toBe(CONTENT);

		await editor.saveDraft();
		await expectStatus(requestUtils, rootId, 'rejected');
	});

	/**
	 * Ask for the change, then a shorter version, and wait for the revision
	 * to replace the first suggestion.
	 *
	 * @param {Object} fixtures Playwright fixtures.
	 * @return {Promise<{rootId: number, first: number, second: number}>} The root note and the two replies.
	 */
	async function reviseChange(fixtures) {
		const { page } = fixtures;
		const rootId = await reviewPost(fixtures);
		const first = await suggestChange(fixtures, rootId);

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

		await expect
			.poll(() => getEditOwner(fixtures.requestUtils, rootId))
			.not.toBe(first);
		const second = await expectSuggested(fixtures, rootId, "in '25");
		expect(second).not.toBe(first);
		return { rootId, first, second };
	}

	test('a revised edit replaces the earlier suggestion', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		await reviseChange({ page, editor, admin, requestUtils, aiFeedback });

		const replies = page
			.getByRole('list', { name: 'Conversation replies' })
			.locator('.ai-feedback-reply-ai');
		await expect(replies.nth(0)).toContainText(
			'Replaced by a revised suggestion.'
		);
	});

	test('saving keeps only the revision, which Accept then applies', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const { rootId, second } = await reviseChange({
			page,
			editor,
			admin,
			requestUtils,
			aiFeedback,
		});

		await editor.saveDraft();
		await expectStatus(requestUtils, rootId, 'pending');
		expect(await getEditOwner(requestUtils, rootId)).toBe(second);

		await decideSuggestion(page, 'Accept');
		await expect
			.poll(() => paragraph(editor))
			.toBe("Revenue grew 40% in '25.");
		await editor.saveDraft();
		await expectStatus(requestUtils, rootId, 'applied');
	});
});
