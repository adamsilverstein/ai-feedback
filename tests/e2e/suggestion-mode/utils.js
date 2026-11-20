/**
 * Helpers for the Suggestion Mode e2e suite.
 *
 * Accept and Reject are Gutenberg's own buttons in its Notes sidebar, so
 * these drive that sidebar rather than the Fresh Eyes one.
 */
const { expect } = require('../fixtures');

/**
 * Open Gutenberg's "All notes" sidebar if it is closed.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {Promise<import('@playwright/test').Locator>} The sidebar region.
 */
async function openNotesSidebar(page) {
	const toggle = page
		.getByRole('region', { name: 'Editor top bar' })
		.getByRole('button', { name: 'All notes', exact: true });
	if ((await toggle.getAttribute('aria-expanded')) !== 'true') {
		await toggle.click();
	}
	return page.getByRole('region', { name: 'Editor settings' });
}

/**
 * Accept or reject a suggestion from Gutenberg's Notes sidebar, and wait for
 * the snackbar Gutenberg shows once the note's status is saved.
 *
 * @param {import('@playwright/test').Page} page         Page.
 * @param {'Accept'|'Reject'}               action       Decision.
 * @param {string}                          [threadText] Text in the note's thread, when there is more than one suggestion.
 */
async function decideSuggestion(page, action, threadText) {
	const sidebar = await openNotesSidebar(page);
	const scope = threadText
		? sidebar
				.locator('.editor-collab-sidebar-panel__thread')
				.filter({ hasText: threadText })
		: sidebar;
	const button = scope.getByRole('button', { name: `${action} suggestion` });
	await expect(button).toBeEnabled();
	await button.click();
	await expect(
		page
			.locator('.components-snackbar-list')
			.getByText(
				action === 'Accept'
					? 'Suggestion applied.'
					: 'Suggestion rejected.'
			)
			.last()
	).toBeVisible();
}

/**
 * Read a note's suggestion meta over REST.
 *
 * @param {Object} requestUtils Request utils fixture.
 * @param {number} noteId       Note ID.
 * @return {Promise<{payload: string, status: string}>} `_wp_suggestion` and `_wp_suggestion_status`.
 */
async function getSuggestionMeta(requestUtils, noteId) {
	const note = await requestUtils.rest({
		path: `/wp/v2/comments/${noteId}`,
		params: { context: 'edit' },
	});
	return {
		payload: note.meta?._wp_suggestion ?? '',
		status: note.meta?._wp_suggestion_status ?? '',
	};
}

/**
 * The suggestion ids that markers in some HTML carry, in order.
 *
 * @param {string} html Block content.
 * @return {number[]} Unique ids.
 */
function markerIds(html) {
	return [
		...new Set(
			[...String(html).matchAll(/data-suggestion-id="(\d+)"/g)].map(
				(match) => Number(match[1])
			)
		),
	];
}

/**
 * Content of each top-level block, in order.
 *
 * @param {Object} editor Editor fixture.
 * @return {Promise<string[]>} One entry per block.
 */
async function blockTexts(editor) {
	const blocks = await editor.getBlocks();
	return blocks.map((block) => String(block.attributes.content ?? ''));
}

/**
 * Create a draft over REST and open it in the editor.
 *
 * @param {Object} fixtures              Playwright fixtures.
 * @param {Object} fixtures.admin        Admin fixture.
 * @param {Object} fixtures.editor       Editor fixture.
 * @param {Object} fixtures.requestUtils Request utils fixture.
 * @param {string} title                 Post title.
 * @param {string} content               Serialized blocks.
 * @param {number} blockCount            Top-level blocks to wait for.
 * @return {Promise<Object>} The created post.
 */
async function openDraft(
	{ admin, editor, requestUtils },
	title,
	content,
	blockCount
) {
	const post = await requestUtils.createPost({
		title,
		content,
		status: 'draft',
	});
	await admin.editPost(post.id);
	await expect
		.poll(() => editor.getBlocks().then((blocks) => blocks.length))
		.toBe(blockCount);
	return post;
}

/**
 * Drain replies a failed test left queued. Mock mode follows the e2e cookie,
 * not the queued event, so a later cookie-less cron run would send them to a
 * real provider.
 *
 * @param {Object} fixtures         Playwright fixtures.
 * @param {Object} fixtures.page    Page.
 * @param {string} fixtures.baseURL Base URL.
 */
async function drainCron({ page, baseURL }) {
	await page.request
		.get(new URL('/wp-cron.php', baseURL).toString())
		.catch(() => {});
}

module.exports = {
	blockTexts,
	decideSuggestion,
	drainCron,
	getSuggestionMeta,
	markerIds,
	openDraft,
	openNotesSidebar,
};
