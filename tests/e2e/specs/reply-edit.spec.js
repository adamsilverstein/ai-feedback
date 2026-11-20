/**
 * E2E tests for AI replies that make the change they discuss.
 *
 * Runs on the server with the mock AI provider from
 * tests/mu-plugins/ai-feedback-e2e.php: a mock reply that asks to "make this
 * change" proposes replacing "last year" with "in 2025", and any other reply
 * gets the prose mock reply. Suggestion Mode is off in wp-env, so the edit
 * shows as Preview and Apply in the sidebar.
 */
const { test, expect } = require('../fixtures');

const CONTENT = 'Revenue grew 40% last year.';
const EDITED = 'Revenue grew 40% in 2025.';

/**
 * Open a post with one paragraph, review it and save the note links.
 *
 * @param {Object} fixtures              Playwright fixtures.
 * @param {Object} fixtures.editor       Editor fixture.
 * @param {Object} fixtures.admin        Admin fixture.
 * @param {Object} fixtures.requestUtils Request utils fixture.
 * @param {Object} fixtures.aiFeedback   AI Feedback fixture.
 * @param {string} [content]             Paragraph HTML.
 * @return {Promise<number>} ID of the review's note on the paragraph.
 */
async function reviewPost(
	{ editor, admin, requestUtils, aiFeedback },
	content = CONTENT
) {
	// Create the post over REST: content added in a freshly loaded editor is
	// sometimes lost before the save, which leaves nothing to review.
	const post = await requestUtils.createPost({
		title: 'Reply edit',
		content: `<!-- wp:paragraph --><p>${content}</p><!-- /wp:paragraph -->`,
		status: 'draft',
	});
	await admin.editPost(post.id);
	await expect
		.poll(() => editor.getBlocks().then((blocks) => blocks.length))
		.toBe(1);
	await aiFeedback.openSidebar();

	const response = await aiFeedback.runReview();
	const review = await response.json();
	const noteId = review.note_ids[0];
	expect(noteId).toBeGreaterThan(0);

	// The reply reads the block from the saved post, found by its note link.
	await editor.saveDraft();

	return noteId;
}

/**
 * Read the paragraph's content.
 *
 * @param {Object} editor Editor fixture.
 * @return {Promise<string>} Paragraph HTML.
 */
async function paragraphContent(editor) {
	const [block] = await editor.getBlocks();
	return block.attributes.content;
}

/**
 * Set the paragraph's content as if the author typed it.
 *
 * @param {import('@playwright/test').Page} page    Page.
 * @param {string}                          content Paragraph HTML.
 */
async function setParagraphContent(page, content) {
	await page.evaluate((html) => {
		const { select, dispatch } = window.wp.data;
		const [block] = select('core/block-editor').getBlocks();
		dispatch('core/block-editor').updateBlockAttributes(block.clientId, {
			content: html,
		});
	}, content);
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

test.describe('Reply edits', () => {
	// Mock mode follows the e2e cookie, not the queued event. Drain any reply a
	// failed test left scheduled, so a later cookie-less cron run does not
	// send it to a real provider.
	test.afterEach(async ({ page, baseURL }) => {
		await page.request
			.get(new URL('/wp-cron.php', baseURL).toString())
			.catch(() => {});
	});

	test('"Make this change" turns the reply into an edit to preview and apply', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		await reviewPost({ editor, admin, requestUtils, aiFeedback });

		// The mock note has a suggestion but no quick fix.
		await page.getByRole('button', { name: 'Make this change' }).click();

		const thread = page.getByRole('list', { name: 'Conversation replies' });
		const aiReply = thread.locator('.ai-feedback-reply-ai');
		await expect(aiReply).toContainText('now reads "in 2025"', {
			timeout: 30000,
		});
		await expect(aiReply).toContainText(
			'Replace “last year” with “in 2025”'
		);
		// The thread already has its edit, so the button goes away.
		await expect(
			page.getByRole('button', { name: 'Make this change' })
		).toHaveCount(0);

		// Nothing is applied until the author says so.
		expect(await paragraphContent(editor)).toBe(CONTENT);

		// Once the text it targets is gone, the edit is outdated.
		await setParagraphContent(page, 'Revenue grew 40% this year.');
		await expect(aiReply).toContainText('This change is outdated');
		await expect(
			aiReply.getByRole('button', { name: 'Apply change' })
		).toHaveAttribute('aria-disabled', 'true');

		await setParagraphContent(page, CONTENT);
		await aiReply.getByRole('button', { name: 'Preview' }).click();

		const dialog = page.getByRole('dialog', { name: 'Preview change' });
		await expect(dialog.locator('del')).toHaveText('last year');
		await expect(dialog.locator('ins')).toHaveText('in 2025');
		await dialog.getByRole('button', { name: 'Apply change' }).click();

		await expect.poll(() => paragraphContent(editor)).toBe(EDITED);
		await expect(aiReply).toContainText('You applied this change.');

		// One undo step reverts the edit.
		await page.evaluate(() =>
			window.wp.data.dispatch('core/editor').undo()
		);
		await expect.poll(() => paragraphContent(editor)).toBe(CONTENT);
	});

	test('a reply that only asks a question gets no edit', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const noteId = await reviewPost({
			editor,
			admin,
			requestUtils,
			aiFeedback,
		});

		await replyToNote(page, noteId, 'Which figure do you mean?');

		const thread = page.getByRole('list', { name: 'Conversation replies' });
		await expect(thread.locator('.ai-feedback-reply-ai')).toContainText(
			'This is a mock AI reply',
			{ timeout: 30000 }
		);
		await expect(
			thread.getByRole('button', { name: 'Apply change' })
		).toHaveCount(0);
		expect(await paragraphContent(editor)).toBe(CONTENT);
	});

	test('an edit across a line break applies and keeps the break', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const content = 'Revenue grew 40%<br>last year.';
		const noteId = await reviewPost(
			{ editor, admin, requestUtils, aiFeedback },
			content
		);

		await replyToNote(page, noteId, 'Make this change on both lines.');

		const thread = page.getByRole('list', { name: 'Conversation replies' });
		const aiReply = thread.locator('.ai-feedback-reply-ai');
		await expect(aiReply).toContainText('both lines now read', {
			timeout: 30000,
		});
		await expect(aiReply).not.toContainText('This change is outdated');

		await aiReply.getByRole('button', { name: 'Apply change' }).click();

		await expect
			.poll(() => paragraphContent(editor))
			.toBe('Revenue grew 40%<br>in 2025.');
	});
});
