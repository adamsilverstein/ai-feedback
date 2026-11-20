/**
 * E2E tests for what's working and progress through the feedback.
 *
 * Runs on the server with the mock AI provider from
 * tests/mu-plugins/ai-feedback-e2e.php, which leaves a note on each of the
 * first three blocks and, for a post opening with "Strengths fixture", says
 * what works in the first two.
 */
const { test, expect } = require('../fixtures');

const CONTENT = [
	'<!-- wp:paragraph --><p>Strengths fixture: the opening example makes the problem concrete.</p><!-- /wp:paragraph -->',
	'<!-- wp:paragraph --><p>Getting started takes three clear steps.</p><!-- /wp:paragraph -->',
	'<!-- wp:paragraph --><p>A closing paragraph that wraps things up.</p><!-- /wp:paragraph -->',
].join('');

/**
 * Open a new post holding the fixture text, with the sidebar open.
 *
 * @param {Object} fixtures              Test fixtures.
 * @param {Object} fixtures.admin        Admin fixture.
 * @param {Object} fixtures.editor       Editor fixture.
 * @param {Object} fixtures.requestUtils Request utilities.
 * @param {Object} fixtures.aiFeedback   Plugin test utilities.
 * @param {string} fixtures.title        Post title.
 * @return {Promise<Object>} Created post.
 */
async function openFixturePost({
	admin,
	editor,
	requestUtils,
	aiFeedback,
	title,
}) {
	const post = await requestUtils.createPost({
		title,
		content: CONTENT,
		status: 'draft',
	});
	await admin.editPost(post.id);
	await expect
		.poll(() => editor.getBlocks().then((blocks) => blocks.length))
		.toBe(3);
	await aiFeedback.openSidebar();
	return post;
}

/**
 * Dismiss or restore the feedback item at an index of the sidebar list.
 *
 * @param {import('@playwright/test').Page} page   Page.
 * @param {string}                          action `Dismiss` or `Restore`.
 * @param {number}                          index  Item index.
 */
async function toggleItem(page, action, index = 0) {
	await page
		.locator('.ai-feedback-list')
		.getByRole('button', { name: new RegExp(`^${action} feedback`) })
		.nth(index)
		.click();
}

test.describe('Strengths and progress', () => {
	test('shows what is working, counts addressed notes and finishes with a done state', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const post = await openFixturePost({
			admin,
			editor,
			requestUtils,
			aiFeedback,
			title: 'Strengths and progress',
		});

		const response = await aiFeedback.runReview();
		const review = await response.json();

		// Strengths come back beside the feedback, never as notes.
		expect(review.strengths).toHaveLength(2);
		expect(review.note_ids).toHaveLength(3);
		expect(review.note_count).toBe(3);
		expect(review.progress).toBeNull();
		const notes = await requestUtils.rest({
			path: '/wp/v2/comments',
			params: { post: post.id, type: 'note', status: 'all' },
		});
		expect(notes).toHaveLength(3);
		expect(JSON.stringify(notes)).not.toContain(
			'makes the problem concrete'
		);

		const strengths = page.locator('.ai-feedback-strengths');
		await expect(
			strengths.getByRole('heading', { name: 'What’s working' })
		).toBeVisible();
		// Markup in a strength is shown as plain text.
		await expect(strengths).toContainText(
			'Clear steps in "Getting started".'
		);
		await expect(strengths.locator('b')).toHaveCount(0);

		// Each strength links to its block.
		await strengths
			.getByRole('button', {
				name: 'The opening example makes the problem concrete.',
			})
			.click();
		await expect
			.poll(() =>
				page.evaluate(() =>
					String(
						window.wp.data
							.select('core/block-editor')
							.getSelectedBlock()?.attributes.content ?? ''
					)
				)
			)
			.toContain('Strengths fixture');

		const progress = page.locator('.ai-feedback-progress-text');
		await expect(progress).toHaveText('0 of 3 notes addressed');
		await expect(page.locator('.summary-headline')).toHaveText(
			'3 feedback items'
		);

		// Dismissing and restoring update the row and are announced.
		await toggleItem(page, 'Dismiss');
		await expect(progress).toHaveText('1 of 3 notes addressed');
		await expect(page.locator('#a11y-speak-polite')).toHaveText(
			'1 of 3 notes addressed'
		);
		await toggleItem(page, 'Restore');
		await expect(progress).toHaveText('0 of 3 notes addressed');
		await toggleItem(page, 'Dismiss');
		await expect(progress).toHaveText('1 of 3 notes addressed');

		// The count and the strengths survive a reload.
		await page.reload();
		await aiFeedback.openSidebar();
		await expect(progress).toHaveText('1 of 3 notes addressed');
		await expect(
			page.locator('.ai-feedback-strengths').getByRole('button', {
				name: 'The opening example makes the problem concrete.',
			})
		).toBeVisible();

		// Addressing the rest shows the done state.
		await toggleItem(page, 'Dismiss');
		await expect(progress).toHaveText('2 of 3 notes addressed');

		// The last one from the keyboard: focus moves to the done state
		// rather than dropping to the page with the removed button.
		await page
			.locator('.ai-feedback-list')
			.getByRole('button', { name: /^Dismiss feedback/ })
			.focus();
		await page.keyboard.press('Enter');
		const done = page.locator('.ai-feedback-done');
		await expect(done).toContainText(
			'All 3 notes from this review are addressed.'
		);
		await expect(page.locator('.ai-feedback-list')).toHaveCount(0);
		await expect(
			done.getByRole('button', { name: 'Review again' })
		).toBeFocused();

		// The list can come back, to restore a note, and takes focus.
		await page.keyboard.press('Tab');
		await expect(
			done.getByRole('button', { name: 'Show feedback' })
		).toBeFocused();
		await page.keyboard.press('Enter');
		await expect(page.locator('.ai-feedback-list')).toBeVisible();
		await expect(page.locator('.feedback-details')).toBeFocused();
		await toggleItem(page, 'Restore');
		await expect(progress).toHaveText('2 of 3 notes addressed');
		await expect(done).toHaveCount(0);
		await toggleItem(page, 'Dismiss');
		await expect(done).toBeVisible();

		// Review again reports what changed since this review.
		const second = page.waitForResponse(
			(res) =>
				res.request().method() === 'POST' &&
				/ai-feedback(\/|%2F)v1(\/|%2F)review/.test(res.url())
		);
		await done.getByRole('button', { name: 'Review again' }).click();
		const secondReview = await (await second).json();
		expect(secondReview.progress).toEqual({ addressed: 3, still_open: 0 });
		await expect(page.locator('.ai-feedback-comparison')).toHaveText(
			'Since the last review: 3 addressed, 3 new'
		);
		await expect(progress).toHaveText('0 of 3 notes addressed');

		// The history keeps the comparison too.
		await page.getByRole('button', { name: 'Review History' }).click();
		await expect(
			page.locator('.ai-feedback-history-progress').first()
		).toHaveText('Since the last review: 3 addressed, 3 new');
	});

	test('a follow-up review counts notes still open and keeps its notes after a reload', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		await openFixturePost({
			admin,
			editor,
			requestUtils,
			aiFeedback,
			title: 'Follow-up progress',
		});

		await aiFeedback.runReview();
		await toggleItem(page, 'Dismiss');
		const progress = page.locator('.ai-feedback-progress-text');
		await expect(progress).toHaveText('1 of 3 notes addressed');

		// Open notes go to the AI as earlier feedback, so the follow-up's
		// notes join their threads as replies.
		const response = await aiFeedback.runReview();
		const review = await response.json();
		expect(review.progress).toEqual({ addressed: 1, still_open: 2 });
		await expect(page.locator('.ai-feedback-comparison')).toHaveText(
			`Since the last review: 1 addressed, ${review.note_count} new, 2 still open`
		);
		await expect(progress).toHaveText(
			`0 of ${review.note_count} notes addressed`
		);

		// A reload lists the same review, replies included.
		await page.reload();
		await aiFeedback.openSidebar();
		await expect(progress).toHaveText(
			`0 of ${review.note_count} notes addressed`
		);
		await expect(page.locator('.ai-feedback-comparison')).toHaveText(
			`Since the last review: 1 addressed, ${review.note_count} new, 2 still open`
		);
	});

	test('a note trashed in the Notes panel drops out of the counts', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		await openFixturePost({
			admin,
			editor,
			requestUtils,
			aiFeedback,
			title: 'Trashed note progress',
		});

		const first = await (await aiFeedback.runReview()).json();
		expect(first.note_ids).toHaveLength(3);

		// The Notes panel deletes a note by moving it to the trash.
		const trashed = await requestUtils.rest({
			method: 'DELETE',
			path: `/wp/v2/comments/${first.note_ids[0]}`,
		});
		expect(trashed.status).toBe('trash');

		const progress = page.locator('.ai-feedback-progress-text');
		await page.reload();
		await aiFeedback.openSidebar();
		await expect(progress).toHaveText('0 of 2 notes addressed');

		const second = await (await aiFeedback.runReview()).json();
		expect(second.progress).toEqual({ addressed: 0, still_open: 2 });
		await expect(page.locator('.ai-feedback-comparison')).toHaveText(
			`Since the last review: 0 addressed, ${second.note_count} new, 2 still open`
		);
	});

	test('the progress bar does not animate with reduced motion', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		await openFixturePost({
			admin,
			editor,
			requestUtils,
			aiFeedback,
			title: 'Reduced motion progress',
		});
		await aiFeedback.runReview();

		const indicator = page
			.locator('.ai-feedback-progress-bar > div')
			.first();
		const transition = () =>
			indicator.evaluate(
				(element) =>
					element.ownerDocument.defaultView.getComputedStyle(element)
						.transitionDuration
			);

		await page.emulateMedia({ reducedMotion: 'no-preference' });
		expect(await transition()).not.toBe('0s');

		await page.emulateMedia({ reducedMotion: 'reduce' });
		expect(await transition()).toBe('0s');
	});
});
