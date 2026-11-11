/**
 * E2E tests for the review workflow.
 *
 * Reviews run against the real REST endpoint with the plugin's mock AI
 * response (see tests/mu-plugins/ai-feedback-e2e.php), which returns one
 * feedback item for each of the first three blocks.
 */
const { test, expect } = require('../fixtures');

test.describe('Review Workflow', () => {
	test('shows the busy state, then the summary for a full review', async ({
		page,
		editor,
		aiFeedback,
	}) => {
		await aiFeedback.createAndSavePost(
			{ title: 'Review Cycle', content: 'Content for the AI review.' },
			{ editor }
		);
		await aiFeedback.openSidebar();

		// Hold the request so the busy state can be checked.
		let releaseReview;
		const reviewHeld = new Promise((resolve) => {
			releaseReview = resolve;
		});
		await aiFeedback.routeREST('/review', async (route) => {
			await reviewHeld;
			await route.continue();
		});

		await aiFeedback.getReviewButton().click();

		const panel = page.locator('.ai-feedback-panel');
		const busyButton = panel.getByRole('button', { name: 'Reviewing…' });
		await expect(busyButton).toBeDisabled();
		await expect(busyButton).toHaveClass(/is-busy/);
		await expect(
			panel.getByRole('progressbar', { name: 'Review progress' })
		).toBeVisible();
		await expect(
			panel.getByText('AI is analyzing your content…')
		).toBeVisible();

		releaseReview();

		const summary = panel.locator('.ai-feedback-review-summary');
		await expect(summary).toBeVisible();
		await expect(summary).toContainText(
			'This review generated 1 notes of constructive feedback.'
		);
		await expect(summary.getByRole('status')).toHaveText('1 feedback item');
		await expect(summary).toContainText('Created notes for 1 block');
		await expect(summary).toContainText('Reviewed with: claude-sonnet-5-5');
		await expect(aiFeedback.getReviewButton()).toBeEnabled();
		await expect(aiFeedback.getReviewButton()).toHaveText(
			'Review Document'
		);
		await expect(page.getByText('No reviews yet')).toBeHidden();
	});

	test('creates a note for each feedback item and links it to its block', async ({
		page,
		editor,
		aiFeedback,
		requestUtils,
	}) => {
		await aiFeedback.createAndSavePost(
			{ title: 'Notes Created', content: 'A paragraph to review.' },
			{ editor }
		);
		await aiFeedback.openSidebar();

		const response = await aiFeedback.runReview();
		const review = await response.json();
		expect(review.note_count).toBe(1);

		const postId = await getPostId(page);
		const notes = await requestUtils.rest({
			path: '/wp/v2/comments',
			params: { post: postId, type: 'note', status: 'all' },
		});
		expect(notes.map((note) => note.id)).toEqual(review.note_ids);

		// The note ID is stored in the reviewed block's metadata.
		const [block] = await editor.getBlocks({ full: true });
		expect(review.block_mapping).toEqual({
			[block.clientId]: review.note_ids[0],
		});
		expect(block.attributes.metadata.noteId).toBe(review.note_ids[0]);
	});

	test('reviews documents with several blocks', async ({
		page,
		editor,
		admin,
		aiFeedback,
	}) => {
		await admin.createNewPost({ title: 'Multiple Blocks' });
		await editor.insertBlock({
			name: 'core/heading',
			attributes: { content: 'A heading', level: 2 },
		});
		await editor.insertBlock({
			name: 'core/paragraph',
			attributes: { content: 'The first paragraph.' },
		});
		await editor.insertBlock({
			name: 'core/paragraph',
			attributes: { content: 'The second paragraph.' },
		});
		await editor.saveDraft();
		await aiFeedback.openSidebar();

		const response = await aiFeedback.runReview();
		const requestBlocks = response.request().postDataJSON().blocks;
		expect(requestBlocks.map((block) => block.name)).toEqual([
			'core/heading',
			'core/paragraph',
			'core/paragraph',
		]);

		const summary = page.locator('.ai-feedback-review-summary');
		await expect(summary.getByRole('status')).toHaveText(
			'3 feedback items'
		);
		await expect(summary).toContainText('Created notes for 3 blocks');
		await expect(
			summary.getByRole('list', { name: 'Feedback items' })
		).toBeVisible();

		const blocks = await editor.getBlocks({ full: true });
		for (const block of blocks) {
			expect(block.attributes.metadata?.noteId).toBeGreaterThan(0);
		}
	});

	test('selects the block when using "Go to block"', async ({
		page,
		editor,
		admin,
		aiFeedback,
	}) => {
		await admin.createNewPost({ title: 'Block Navigation' });
		await editor.insertBlock({
			name: 'core/paragraph',
			attributes: { content: 'First block.' },
		});
		await editor.insertBlock({
			name: 'core/paragraph',
			attributes: { content: 'Second block.' },
		});
		await editor.saveDraft();
		await aiFeedback.openSidebar();
		await aiFeedback.runReview();

		const blocks = await editor.getBlocks({ full: true });
		const panel = page.locator('.ai-feedback-panel');
		// Feedback is sorted by priority by default.
		await panel.getByLabel('Sort by').selectOption('Document order');
		await panel.getByRole('button', { name: 'Go to block' }).nth(1).click();

		await expect
			.poll(() =>
				page.evaluate(() =>
					window.wp.data
						.select('core/block-editor')
						.getSelectedBlockClientId()
				)
			)
			.toBe(blocks[1].clientId);
	});

	test('sends the selected model and language with the review', async ({
		page,
		editor,
		aiFeedback,
		requestUtils,
	}) => {
		await aiFeedback.createAndSavePost(
			{ title: 'Review Settings', content: 'Content to review.' },
			{ editor }
		);
		await aiFeedback.openSidebar();
		await aiFeedback.runReview();

		try {
			await aiFeedback.saveSetting(() =>
				page.getByLabel('AI Model').selectOption('gpt-4o')
			);
			await aiFeedback.saveSetting(() =>
				page.getByLabel('Feedback Language').selectOption('fr_FR')
			);

			const response = await aiFeedback.runReview();
			const request = response.request().postDataJSON();
			expect(request.model).toBe('gpt-4o');
			expect(request.locale).toBe('fr_FR');

			await expect(
				page.locator('.ai-feedback-review-summary')
			).toContainText('Reviewed with: gpt-4o');
		} finally {
			await requestUtils.rest({
				path: '/ai-feedback/v1/settings',
				method: 'POST',
				data: {
					default_model: 'claude-sonnet-5-5',
					feedback_locale: 'auto',
				},
			});
		}
	});

	test('sends earlier feedback when reviewing again', async ({
		editor,
		aiFeedback,
	}) => {
		await aiFeedback.createAndSavePost(
			{ title: 'Continuation', content: 'Content to review twice.' },
			{ editor }
		);
		await aiFeedback.openSidebar();

		const first = await aiFeedback.runReview();
		expect(first.request().postDataJSON().existing_feedback).toEqual([]);
		const firstReview = await first.json();

		const second = await aiFeedback.runReview();
		const existing = second.request().postDataJSON().existing_feedback;
		expect(existing.map((note) => note.id)).toContain(
			firstReview.note_ids[0]
		);
		expect(second.ok()).toBe(true);
	});

	test('warns instead of reviewing when the content is removed', async ({
		page,
		editor,
		aiFeedback,
	}) => {
		await aiFeedback.createAndSavePost(
			{ title: 'Emptied Post', content: 'Soon to be removed.' },
			{ editor }
		);
		await aiFeedback.openSidebar();
		await aiFeedback.runReview();

		await page.evaluate(() => {
			const blockEditor = window.wp.data.dispatch('core/block-editor');
			blockEditor.resetBlocks([]);
		});

		let reviewRequests = 0;
		page.on('request', (request) => {
			if (
				request.method() === 'POST' &&
				decodeURIComponent(request.url()).includes(
					'/ai-feedback/v1/review'
				)
			) {
				reviewRequests++;
			}
		});

		await aiFeedback.getReviewButton().click();

		await expect(
			page
				.locator('.components-snackbar')
				.filter({ hasText: 'No content blocks found to review.' })
		).toBeVisible();
		expect(reviewRequests).toBe(0);
	});

	test('does not offer a review for a post without content', async ({
		admin,
		page,
		aiFeedback,
	}) => {
		await admin.createNewPost({ title: 'Title Only' });
		await aiFeedback.openSidebar();

		const panel = page.locator('.ai-feedback-panel');
		await expect(
			panel.getByText('Add some content to your post')
		).toBeVisible();
		await expect(
			panel.getByRole('button', { name: 'Review Document' })
		).toHaveCount(0);
	});
});

/**
 * Get the ID of the post open in the editor.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {Promise<number>} Post ID.
 */
function getPostId(page) {
	return page.evaluate(() =>
		window.wp.data.select('core/editor').getCurrentPostId()
	);
}
