/**
 * E2E tests for asking Fresh Eyes about a selection with an `@fresh-eyes`
 * mention in a note.
 *
 * Runs on the server with the mock AI provider from
 * tests/mu-plugins/ai-feedback-e2e.php: a mention that asks to "simplify"
 * gets an edit replacing the note's text with "short", and one that asks
 * for sources gets an answer with a made-up link. Suggestion Mode is off in
 * wp-env, so the edit shows as Preview and Apply in the sidebar.
 *
 * Fresh Eyes is not a WordPress user, so the notes `@` completer does not
 * list it; the tests type the handle as text, as an author would.
 */
const { test, expect } = require('../fixtures');

const CONTENT = 'This is a very long and winding sentence.';
const SELECTED = 'very long and winding';
const EDITED = 'This is a short sentence.';

/**
 * Open a new post with one paragraph.
 *
 * @param {Object} fixtures              Playwright fixtures.
 * @param {Object} fixtures.editor       Editor fixture.
 * @param {Object} fixtures.admin        Admin fixture.
 * @param {Object} fixtures.requestUtils Request utils fixture.
 * @return {Promise<number>} Post ID.
 */
async function openPost({ editor, admin, requestUtils }) {
	const post = await requestUtils.createPost({
		title: 'Mention test',
		content: `<!-- wp:paragraph --><p>${CONTENT}</p><!-- /wp:paragraph -->`,
		status: 'draft',
	});
	await admin.editPost(post.id);
	await expect
		.poll(() => editor.getBlocks().then((blocks) => blocks.length))
		.toBe(1);
	return post.id;
}

/**
 * Read the paragraph's text.
 *
 * @param {Object} editor Editor fixture.
 * @return {Promise<string>} Paragraph text without markup.
 */
async function paragraphText(editor) {
	const [block] = await editor.getBlocks();
	return String(block.attributes.content).replace(/<[^>]*>/g, '');
}

/**
 * Select text in the paragraph, as dragging over it would.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string}                          text Text to select.
 */
async function selectText(page, text) {
	await page.evaluate((selected) => {
		const { select, dispatch } = window.wp.data;
		const [block] = select('core/block-editor').getBlocks();
		const start = String(block.attributes.content).indexOf(selected);
		dispatch('core/block-editor').selectionChange(
			block.clientId,
			'content',
			start,
			start + selected.length
		);
	}, text);
}

/**
 * Add a note to the selection through the Notes UI.
 *
 * @param {Object}                          fixtures        Playwright fixtures.
 * @param {import('@playwright/test').Page} fixtures.page   Page.
 * @param {Object}                          fixtures.editor Editor fixture.
 * @param {string}                          text            Note text.
 */
async function addNote({ page, editor }, text) {
	await editor.clickBlockOptionsMenuItem('Add note');
	const field = page.getByRole('textbox', { name: 'Note' }).last();
	await field.click();
	await page.keyboard.type(text);
	await page
		.locator('.editor-collab-sidebar-panel__note-form')
		.getByRole('button', { name: /Add note|Submit|Save/ })
		.last()
		.click();
	await expect(page.getByText('Note added.').first()).toBeVisible();
}

test.describe('Ask Fresh Eyes with a mention', () => {
	// Mock mode follows the e2e cookie, not the queued event. Drain any
	// answer a failed test left scheduled.
	test.afterEach(async ({ page, baseURL }) => {
		await page.request
			.get(new URL('/wp-cron.php', baseURL).toString())
			.catch(() => {});
	});

	test('a note that mentions @fresh-eyes gets an answer and a change to apply', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		await openPost({ editor, admin, requestUtils });

		await selectText(page, SELECTED);
		await addNote({ page, editor }, '@fresh-eyes simplify this');

		await aiFeedback.openSidebar();
		const panel = page.locator('.ai-feedback-mention-thread');
		await expect(panel).toContainText('@fresh-eyes simplify this');

		// The answer names the selected text, so the selection reached the
		// AI before the post was saved.
		const answer = panel.locator('.ai-feedback-reply-ai');
		await expect(answer).toContainText(`Simplified "${SELECTED}".`, {
			timeout: 30000,
		});
		await expect(answer).toContainText(
			`Replace “${SELECTED}” with “short”`
		);

		// Nothing changes until the author applies it.
		expect(await paragraphText(editor)).toBe(CONTENT);

		await answer.getByRole('button', { name: 'Preview' }).click();
		const dialog = page.getByRole('dialog', { name: 'Preview change' });
		await expect(dialog.locator('del')).toHaveText(SELECTED);
		await expect(dialog.locator('ins')).toHaveText('short');
		await dialog.getByRole('button', { name: 'Apply change' }).click();

		await expect.poll(() => paragraphText(editor)).toBe(EDITED);
	});

	test('a note without a mention in a person’s thread is not answered', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const postId = await openPost({ editor, admin, requestUtils });

		await editor.selectBlocks(
			editor.canvas.getByRole('document', { name: 'Block: Paragraph' })
		);
		await addNote({ page, editor }, 'Fresh eyes on this would help.');

		const threads = await requestUtils.rest({
			path: '/ai-feedback/v1/replies',
			params: { post: postId },
		});
		expect(threads.threads).toEqual({});

		await aiFeedback.openSidebar();
		await expect(page.locator('.ai-feedback-mention-thread')).toHaveCount(
			0
		);
	});

	test('an answer never carries a link Fresh Eyes made up', async ({
		page,
		editor,
		admin,
		requestUtils,
	}) => {
		const postId = await openPost({ editor, admin, requestUtils });

		// A note added outside the Notes UI, answered from the saved post.
		// Requests go through the editor page, which carries the mock cookie.
		const { noteId, status } = await page.evaluate(async (post) => {
			const note = await window.wp.apiFetch({
				method: 'POST',
				path: '/wp/v2/comments',
				data: {
					post,
					type: 'note',
					status: 'hold',
					content:
						'@fresh-eyes research this and link to the sources',
				},
			});
			const result = await window.wp.apiFetch({
				method: 'POST',
				path: `/ai-feedback/v1/replies/${note.id}/process`,
			});
			return { noteId: note.id, status: result.status };
		}, postId);
		expect(status).toBe('complete');

		const { threads } = await requestUtils.rest({
			path: '/ai-feedback/v1/replies',
			params: { post: postId },
		});
		const [answer] = threads[noteId].replies;
		expect(answer.is_ai).toBe(true);
		expect(answer.content).toBe('See [link removed] for the numbers.');
		expect(answer.edit).toBeNull();
	});
});
