/**
 * E2E tests for the hidden Fresh Eyes user the Notes `@` completer offers.
 *
 * The plugin creates the user on activation, or on the first admin page
 * load after an upgrade. It stays out of user lists everywhere except the
 * Notes completer's own search for its name, `GET /wp/v2/users?context=view
 * &search=<typed text>`. Answers come from the mock AI provider in
 * tests/mu-plugins/ai-feedback-e2e.php, which names the selected text when
 * asked to simplify.
 */
const path = require('path');
const { RequestUtils } = require('@wordpress/e2e-test-utils-playwright');
const { test, expect } = require('../fixtures');

const CONTENT = 'This is a very long and winding sentence.';
const SELECTED = 'very long and winding';
const EDITOR = {
	username: 'fresh-eyes-e2e-editor',
	email: 'fresh-eyes-e2e-editor@example.com',
	password: 'fresh-eyes-e2e-password',
	roles: ['editor'],
};

/**
 * Open a new post with one paragraph and read the Fresh Eyes user ID the
 * editor was given.
 *
 * @param {Object} fixtures              Playwright fixtures.
 * @param {Object} fixtures.page         Page.
 * @param {Object} fixtures.editor       Editor fixture.
 * @param {Object} fixtures.admin        Admin fixture.
 * @param {Object} fixtures.requestUtils Request utils fixture.
 * @return {Promise<number>} Fresh Eyes user ID.
 */
async function openPost({ page, editor, admin, requestUtils }) {
	// Any admin page creates the user after an upgrade.
	await admin.visitAdminPage('index.php');
	const post = await requestUtils.createPost({
		title: 'Completer test',
		content: `<!-- wp:paragraph --><p>${CONTENT}</p><!-- /wp:paragraph -->`,
		status: 'draft',
	});
	await admin.editPost(post.id);
	await expect
		.poll(() => editor.getBlocks().then((blocks) => blocks.length))
		.toBe(1);
	// wp_localize_script() sends numbers as strings.
	const userId = Number(
		await page.evaluate(() => window.aiFeedbackData?.mentionUserId)
	);
	expect(userId).toBeGreaterThan(0);
	return userId;
}

/**
 * Get user IDs from a `/wp/v2/users` request.
 *
 * @param {Object} requestUtils Request utils.
 * @param {Object} params       Query parameters.
 * @return {Promise<number[]>} User IDs.
 */
async function listUsers(requestUtils, params) {
	const users = await requestUtils.rest({ path: '/wp/v2/users', params });
	return users.map((user) => user.id);
}

/**
 * Wait for the completer's users search for some typed text.
 *
 * @param {import('@playwright/test').Page} page   Page.
 * @param {string}                          search Typed text.
 * @return {Promise<import('@playwright/test').Response>} Response.
 */
function waitForUserSearch(page, search) {
	return page.waitForResponse((response) => {
		const url = new URL(response.url());
		const route = url.searchParams.get('rest_route') ?? url.pathname;
		return (
			route.endsWith('/wp/v2/users') &&
			url.searchParams.get('search') === search
		);
	});
}

test.describe('Fresh Eyes user', () => {
	test.afterEach(async ({ page, baseURL }) => {
		await page.request
			.get(new URL('/wp-cron.php', baseURL).toString())
			.catch(() => {});
	});

	test('typing @fr in a note offers Fresh Eyes and picking it gets an answer', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const userId = await openPost({ page, editor, admin, requestUtils });

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
		}, SELECTED);
		await editor.clickBlockOptionsMenuItem('Add note');
		const field = page.getByRole('textbox', { name: 'Note' }).last();
		await field.click();

		const searched = waitForUserSearch(page, 'fr');
		await page.keyboard.type('@fr');
		const found = await (await searched).json();
		expect(found.map((user) => user.id)).toContain(userId);

		const option = page.getByRole('option', { name: /Fresh Eyes/ });
		await expect(option).toBeVisible();
		await option.click();
		await page.keyboard.type(' simplify this');

		await page
			.locator('.editor-collab-sidebar-panel__note-form')
			.getByRole('button', { name: /Add note|Submit|Save/ })
			.last()
			.click();
		await expect(page.getByText('Note added.').first()).toBeVisible();

		await aiFeedback.openSidebar();
		const answer = page
			.locator('.ai-feedback-mention-thread')
			.locator('.ai-feedback-reply-ai');
		await expect(answer).toContainText(`Simplified "${SELECTED}".`, {
			timeout: 30000,
		});

		// The note holds the completer's chip for the user. Read through
		// the page, so the request carries the mock AI cookie.
		const notes = await page.evaluate(() =>
			window.wp.apiFetch({
				path: '/wp/v2/comments?type=note&status=all&context=edit',
			})
		);
		const note = notes.find((item) =>
			String(item.content?.raw).includes('simplify this')
		);
		expect(note.content.raw).toContain(
			`class="wp-note-mention user-${userId}"`
		);
	});

	test('the block editor @ completer does not offer Fresh Eyes', async ({
		page,
		editor,
		admin,
		requestUtils,
	}) => {
		await openPost({ page, editor, admin, requestUtils });

		await editor.canvas.locator('p').first().click();
		await page.keyboard.press('End');
		const searched = waitForUserSearch(page, 'fr');
		await page.keyboard.type(' @fr');
		await searched;

		await expect(
			page.getByRole('option', { name: /Fresh Eyes/ })
		).toHaveCount(0);
	});

	test('user lists leave Fresh Eyes out unless the completer searches its name', async ({
		page,
		editor,
		admin,
		requestUtils,
		baseURL,
	}) => {
		const userId = await openPost({ page, editor, admin, requestUtils });

		// Only the completer's search for its name lists it.
		const view = { context: 'view', _locale: 'user' };
		for (const search of ['fr', 'fresh', 'fresh-e', 'Fresh Ey']) {
			expect(
				await listUsers(requestUtils, { ...view, search })
			).toContain(userId);
		}
		for (const params of [
			{},
			{ ...view, search: 'f' },
			{ ...view, search: 'eyes' },
			{ ...view, search: 'fresh', per_page: 100 },
			{ ...view, search: 'fresh', who: 'authors' },
			{ ...view, search: 'fresh', search_columns: 'name' },
			{ context: 'edit', search: 'fresh' },
			{ include: userId },
			{ slug: 'fresh-eyes' },
		]) {
			expect(
				await listUsers(requestUtils, params),
				JSON.stringify(params)
			).not.toContain(userId);
		}

		// The single user and its application passwords are not found.
		for (const route of [
			`/wp/v2/users/${userId}`,
			`/wp/v2/users/${userId}/application-passwords`,
		]) {
			await expect(
				requestUtils.rest({ path: route })
			).rejects.toMatchObject({
				code: 'rest_user_invalid_id',
				data: { status: 404 },
			});
		}

		// The Users screen and its counts.
		await admin.visitAdminPage('users.php');
		await expect(page.locator('#the-list')).not.toContainText('Fresh Eyes');
		await expect(page.locator('.subsubsub')).not.toContainText('No role');
		await expect(page.locator(`#user-${userId}`)).toHaveCount(0);

		// Editing it is not allowed.
		await page.goto(
			new URL(
				`/wp-admin/user-edit.php?user_id=${userId}`,
				baseURL
			).toString()
		);
		await expect(page.locator('body')).toContainText(
			'not allowed to edit this user'
		);

		// Its author archive, reached by ID, is not found.
		const archive = await page.request.get(
			new URL(`/?author=${userId}`, baseURL).toString(),
			{ maxRedirects: 0 }
		);
		expect(archive.status()).toBe(404);
	});

	test('an editor without list_users gets the user from the completer search only', async ({
		page,
		editor,
		admin,
		requestUtils,
		baseURL,
	}) => {
		const userId = await openPost({ page, editor, admin, requestUtils });
		const created = await requestUtils.createUser({ ...EDITOR });

		const editorUtils = await RequestUtils.setup({
			user: EDITOR,
			baseURL,
			storageStatePath: path.join(
				process.env.WP_ARTIFACTS_PATH,
				'storage-states/fresh-eyes-editor.json'
			),
		});

		const view = { context: 'view', _locale: 'user' };
		expect(
			await listUsers(editorUtils, { ...view, search: 'fr' })
		).toContain(userId);
		expect(await listUsers(editorUtils, {})).not.toContain(userId);
		expect(
			await listUsers(editorUtils, { ...view, search: 'eyes' })
		).not.toContain(userId);

		await requestUtils.rest({
			method: 'DELETE',
			path: `/wp/v2/users/${created.id}`,
			params: { force: true, reassign: 1 },
		});
	});
});
