/**
 * E2E tests for refining a suggested edit by replying.
 *
 * Runs on the server with the mock AI provider from
 * tests/mu-plugins/ai-feedback-e2e.php: "make this change" proposes
 * replacing "last year" with "in 2025", and the Shorter and Try another
 * quick replies revise that pending edit, which the mock only does when the
 * reply prompt carries it. Suggestion Mode is off in wp-env, so each revision
 * shows as Preview and Apply in the sidebar.
 */
const { test, expect } = require('../fixtures');

const CONTENT = 'Revenue grew 40% last year.';

/**
 * Open a post with one paragraph, review it and save the note links.
 *
 * @param {Object} fixtures              Playwright fixtures.
 * @param {Object} fixtures.editor       Editor fixture.
 * @param {Object} fixtures.admin        Admin fixture.
 * @param {Object} fixtures.requestUtils Request utils fixture.
 * @param {Object} fixtures.aiFeedback   AI Feedback fixture.
 */
async function reviewPost({ editor, admin, requestUtils, aiFeedback }) {
	const post = await requestUtils.createPost({
		title: 'Refine edit',
		content: `<!-- wp:paragraph --><p>${CONTENT}</p><!-- /wp:paragraph -->`,
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

	// The reply reads the block from the saved post, found by its note link.
	await editor.saveDraft();
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

test.describe('Refining a suggested edit', () => {
	// Mock mode follows the e2e cookie, not the queued event. Drain any reply a
	// failed test left scheduled, so a later cookie-less cron run does not
	// send it to a real provider.
	test.afterEach(async ({ page, baseURL }) => {
		await page.request
			.get(new URL('/wp-cron.php', baseURL).toString())
			.catch(() => {});
	});

	test('two rounds of refinement, then the newest revision is applied', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		await reviewPost({ editor, admin, requestUtils, aiFeedback });

		await page.getByRole('button', { name: 'Make this change' }).click();

		const thread = page.getByRole('list', { name: 'Conversation replies' });
		const aiReplies = thread.locator('.ai-feedback-reply-ai');
		await expect(aiReplies.nth(0)).toContainText('Suggested edit', {
			timeout: 30000,
		});

		// Round one, from the keyboard.
		const quickReplies = page.getByRole('group', { name: 'Quick replies' });
		await expect(
			quickReplies.getByRole('button', { name: 'Make that change' })
		).toHaveCount(0);
		await quickReplies.getByRole('button', { name: 'Shorter' }).focus();
		await page.keyboard.press('Enter');

		// The quick reply posts like a typed one and waits on the AI.
		await expect(
			thread.locator('.ai-feedback-reply-user').last()
		).toContainText('Make it shorter.');
		await expect(aiReplies.nth(1)).toContainText('Revised suggestion (2)', {
			timeout: 30000,
		});
		await expect(aiReplies.nth(1)).toContainText(
			"Replace “last year” with “in '25”"
		);
		await expect(aiReplies.nth(0)).toContainText(
			'Replaced by a revised suggestion.'
		);

		// Round two.
		await quickReplies.getByRole('button', { name: 'Try another' }).click();
		await expect(aiReplies.nth(2)).toContainText('Revised suggestion (3)', {
			timeout: 30000,
		});
		await expect(aiReplies.nth(1)).toContainText(
			'Replaced by a revised suggestion.'
		);

		// Only the newest revision can be applied.
		const apply = thread.getByRole('button', { name: 'Apply change' });
		await expect(apply).toHaveCount(1);
		expect(await paragraphContent(editor)).toBe(CONTENT);

		await apply.click();
		await expect
			.poll(() => paragraphContent(editor))
			.toBe('Revenue grew 40% in fiscal 2025.');
		await expect(aiReplies.nth(2)).toContainText(
			'You applied this change.'
		);

		// With nothing pending, the quick replies offer a new change.
		await expect(
			quickReplies.getByRole('button', { name: 'Make that change' })
		).toBeVisible();
	});
});
