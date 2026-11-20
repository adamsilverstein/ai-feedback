/**
 * E2E tests for house style rules.
 *
 * Runs with the mock AI provider from tests/mu-plugins/ai-feedback-e2e.php:
 * a reply that mentions sentence case offers to remember a long rule that
 * starts "Headings use sentence case", and a review whose prompt carries
 * that rule adds a "Follow the house style" note.
 */
const { test, expect } = require('../fixtures');

// Long enough to wrap in the sidebar, so the test can check it all shows.
const RULE =
	'Headings use sentence case, except product names such as WordPress and Gutenberg, which keep their own capital letters';
const HOUSE_STYLE = '/ai-feedback/v1/house-style';

/**
 * Remove every house style rule the admin can see.
 *
 * @param {Object} requestUtils Request utils fixture.
 */
async function clearRules(requestUtils) {
	const rules = await requestUtils.rest({ path: HOUSE_STYLE });
	for (const scope of ['site', 'user']) {
		for (const rule of rules[scope]) {
			await requestUtils.rest({
				method: 'DELETE',
				path: `${HOUSE_STYLE}/${rule.id}`,
				params: { scope },
			});
		}
	}
}

/**
 * Open a post with one paragraph, review it and save the note links.
 *
 * @param {Object} fixtures              Playwright fixtures.
 * @param {Object} fixtures.editor       Editor fixture.
 * @param {Object} fixtures.admin        Admin fixture.
 * @param {Object} fixtures.requestUtils Request utils fixture.
 * @param {Object} fixtures.aiFeedback   AI Feedback fixture.
 * @return {Promise<number>} ID of the review's note on the paragraph.
 */
async function reviewPost({ editor, admin, requestUtils, aiFeedback }) {
	const post = await requestUtils.createPost({
		title: 'House style',
		content:
			'<!-- wp:paragraph --><p>How We Ship Releases Every Week.</p><!-- /wp:paragraph -->',
		status: 'draft',
	});
	await admin.editPost(post.id);
	await expect
		.poll(() => editor.getBlocks().then((blocks) => blocks.length))
		.toBe(1);
	await aiFeedback.openSidebar();

	const response = await aiFeedback.runReview();
	const review = await response.json();
	expect(review.note_ids[0]).toBeGreaterThan(0);
	await editor.saveDraft();

	return review.note_ids[0];
}

/**
 * Reply to a note as the author, through the editor's data store.
 *
 * @param {import('@playwright/test').Page} page    Page.
 * @param {number}                          parent  Note ID.
 * @param {string}                          content Reply text.
 */
async function reply(page, parent, content) {
	await page.evaluate(
		async (args) => {
			const { select, dispatch } = window.wp.data;
			await dispatch('core').saveEntityRecord(
				'root',
				'comment',
				{
					post: select('core/editor').getCurrentPostId(),
					content: args.content,
					status: 'hold',
					type: 'note',
					parent: args.parent,
				},
				{ throwOnError: true }
			);
		},
		{ parent, content }
	);
}

test.describe('House style', () => {
	test.beforeEach(async ({ requestUtils }) => {
		await clearRules(requestUtils);
	});

	test.afterEach(async ({ page, baseURL, requestUtils }) => {
		// Drain any reply a failed test left queued; see reply-edit.spec.js.
		await page.request
			.get(new URL('/wp-cron.php', baseURL).toString())
			.catch(() => {});
		await clearRules(requestUtils);
	});

	test('a stated preference is remembered on confirm and the next review follows it', async ({
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

		await reply(page, noteId, 'We always use sentence case here.');

		const thread = page.getByRole('list', { name: 'Conversation replies' });
		await expect(thread.locator('.ai-feedback-reply-ai')).toContainText(
			'sentence case it is',
			{ timeout: 30000 }
		);
		const field = thread.getByLabel('Remember this for future reviews');
		await expect(field).toHaveValue(RULE);
		// The whole rule shows, with nothing scrolled out of view.
		expect(
			await field.evaluate(
				(element) => element.scrollHeight <= element.clientHeight + 1
			)
		).toBe(true);
		expect(
			await field.evaluate((element) => element.scrollWidth)
		).toBeLessThanOrEqual(
			await field.evaluate((element) => element.clientWidth + 1)
		);

		// Nothing is saved until the author confirms.
		expect(
			(await requestUtils.rest({ path: HOUSE_STYLE })).user
		).toHaveLength(0);

		const saved = page.waitForResponse(
			(response) =>
				response.url().includes('house-style') &&
				response.request().method() === 'POST'
		);
		await thread.getByRole('button', { name: 'Remember' }).click();
		expect((await saved).status()).toBe(201);
		await expect(
			thread.getByText(`Saved to house style: “${RULE}”`)
		).toBeVisible();

		// The rule shows in Review Settings.
		await aiFeedback.expandReviewSettings();
		const settings = page.locator('.ai-feedback-house-style');
		await expect(settings.getByText(RULE)).toBeVisible();

		// The next review's prompt carries the rule, so the mock flags it.
		const response = await aiFeedback.runReview();
		expect(JSON.stringify(await response.json())).toContain(
			'Follow the house style'
		);

		// Reloading keeps the offer marked as saved.
		await page.reload();
		await aiFeedback.openSidebar();
		await expect(
			page
				.getByRole('list', { name: 'Conversation replies' })
				.first()
				.getByText('Saved to house style.')
		).toBeVisible();
	});

	test('Not now saves nothing and the offer stays dismissed', async ({
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

		await reply(page, noteId, 'We use sentence case for headings.');

		const thread = page.getByRole('list', { name: 'Conversation replies' });
		await expect(
			thread.getByLabel('Remember this for future reviews')
		).toBeVisible({ timeout: 30000 });

		const dismissed = page.waitForResponse((response) =>
			response.url().includes('/dismiss')
		);
		await thread.getByRole('button', { name: 'Not now' }).click();
		expect((await dismissed).ok()).toBe(true);
		await expect(
			thread.getByLabel('Remember this for future reviews')
		).toHaveCount(0);

		const rules = await requestUtils.rest({ path: HOUSE_STYLE });
		expect([...rules.site, ...rules.user]).toHaveLength(0);

		await page.reload();
		await aiFeedback.openSidebar();
		await expect(
			page
				.getByRole('list', { name: 'Conversation replies' })
				.first()
				.locator('.ai-feedback-reply-ai')
		).toBeVisible();
		await expect(
			page.getByLabel('Remember this for future reviews')
		).toHaveCount(0);
	});

	test('rules can be added, edited and removed in Review Settings', async ({
		page,
		admin,
		aiFeedback,
		requestUtils,
	}) => {
		await admin.createNewPost();
		await aiFeedback.openSidebar();
		await aiFeedback.expandReviewSettings();

		const settings = page.locator('.ai-feedback-house-style');
		await settings.getByLabel('New rule').fill('Use the Oxford comma');
		await settings
			.getByLabel('Applies to')
			.selectOption('Everyone on this site');
		await settings.getByRole('button', { name: 'Add rule' }).click();
		await expect(
			settings.getByRole('button', {
				name: 'Edit rule: Use the Oxford comma',
			})
		).toBeVisible();
		expect(
			(await requestUtils.rest({ path: HOUSE_STYLE })).site.map(
				(rule) => rule.text
			)
		).toEqual(['Use the Oxford comma']);

		await settings
			.getByRole('button', { name: 'Edit rule: Use the Oxford comma' })
			.click();
		await settings.getByLabel('Edit rule').fill('Skip the Oxford comma');
		await settings.getByRole('button', { name: 'Save' }).click();
		await expect(settings.getByText('Skip the Oxford comma')).toBeVisible();

		await settings
			.getByRole('button', { name: 'Remove rule: Skip the Oxford comma' })
			.click();
		await expect(settings.getByText('Skip the Oxford comma')).toHaveCount(
			0
		);
		expect(
			(await requestUtils.rest({ path: HOUSE_STYLE })).site
		).toHaveLength(0);
	});
});
