/**
 * E2E tests for AI notes written as the Fresh Eyes user.
 *
 * Reviews run on the server with the mock AI provider from
 * tests/mu-plugins/ai-feedback-e2e.php. Any admin page creates the hidden
 * Fresh Eyes user, and new AI notes are written as it, with its name and
 * blinking eyes avatar.
 */
const { test, expect } = require('../fixtures');

/**
 * Get the post ID from the editor.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {Promise<number>} Post ID.
 */
function getPostId(page) {
	return page.evaluate(() =>
		window.wp.data.select('core/editor').getCurrentPostId()
	);
}

test.describe('AI notes author', () => {
	test('a new AI note shows Fresh Eyes as its author, with its avatar', async ({
		page,
		admin,
		editor,
		aiFeedback,
		requestUtils,
	}) => {
		// Any admin page creates the user after an update.
		await admin.visitAdminPage('index.php');
		await aiFeedback.createAndSavePost(
			{ title: 'Author test', content: 'A paragraph to review.' },
			{ editor }
		);
		const userId = Number(
			await page.evaluate(() => window.aiFeedbackData?.mentionUserId)
		);
		expect(userId).toBeGreaterThan(0);

		await aiFeedback.openSidebar();
		const review = await (await aiFeedback.runReview()).json();
		expect(review.note_count).toBe(1);

		const [note] = await requestUtils.rest({
			path: '/wp/v2/comments',
			params: {
				post: await getPostId(page),
				type: 'note',
				status: 'all',
				context: 'edit',
			},
		});
		expect(note.author).toBe(userId);
		expect(note.author_name).toBe('Fresh Eyes');
		expect(note.author_avatar_urls['48']).toMatch(
			/\/assets\/fresh-eyes-avatar\.svg$/
		);
		// The hidden user is not found at /wp/v2/users/<id>, so no link to it.
		expect(note._links.author).toBeUndefined();

		// The Notes UI shows the name and avatar, not a missing author.
		const thread = page
			.locator('.editor-collab-sidebar-panel__thread')
			.first();
		await thread.click();
		await expect(
			thread.getByText('Fresh Eyes', { exact: true })
		).toBeVisible();
		await expect(
			thread.locator('img[src*="fresh-eyes-avatar.svg"]').first()
		).toBeVisible();
	});
});
