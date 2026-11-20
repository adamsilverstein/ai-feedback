/**
 * E2E tests for reader personas: "Read it as..." in Review Settings.
 *
 * Reviews and replies use the mock AI from tests/mu-plugins/ai-feedback-e2e.php.
 */
const { test, expect } = require('../fixtures');

/**
 * Reply to a note the way the core Notes UI does.
 *
 * @param {import('@playwright/test').Page} page    Page.
 * @param {number}                          parent  Note to reply to.
 * @param {string}                          content Reply text.
 */
async function replyToNote(page, parent, content) {
	await page.evaluate(
		async ({ parentId, text }) => {
			const { select, dispatch } = window.wp.data;
			await dispatch('core').saveEntityRecord(
				'root',
				'comment',
				{
					post: select('core/editor').getCurrentPostId(),
					content: text,
					status: 'hold',
					type: 'note',
					parent: parentId,
				},
				{ throwOnError: true }
			);
		},
		{ parentId: parent, text: content }
	);
}

test.describe('Reader personas', () => {
	test.afterEach(async ({ requestUtils, page, baseURL }) => {
		// Preferences are per user, so put the defaults back for other tests.
		await requestUtils.rest({
			path: '/ai-feedback/v1/settings',
			method: 'POST',
			data: { default_persona: 'copy_editor', review_audience: '' },
		});
		// Drain any reply a failed test left queued.
		await page.request
			.get(new URL('/wp-cron.php', baseURL).toString())
			.catch(() => {});
	});

	test('reviews as the chosen persona and keeps the choice', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const post = await requestUtils.createPost({
			title: 'Read it as',
			content:
				'<!-- wp:paragraph --><p>Our plugin is 40% faster.</p><!-- /wp:paragraph -->',
			status: 'draft',
		});
		await admin.editPost(post.id);
		await expect
			.poll(() => editor.getBlocks().then((blocks) => blocks.length))
			.toBe(1);
		await aiFeedback.openSidebar();
		await aiFeedback.expandReviewSettings();

		const personas = page.getByRole('radiogroup', { name: 'Read it as' });
		await expect(
			personas.getByRole('radio', { name: /Copy editor/ })
		).toBeChecked();

		await aiFeedback.saveSetting(() =>
			personas.getByRole('radio', { name: /Skeptical expert/ }).check()
		);
		const audience = page.getByLabel('Who is this for? (optional)');
		await audience.fill('plugin developers');
		await aiFeedback.saveSetting(() => audience.blur());

		// The choice persists for this user.
		await page.reload();
		await aiFeedback.openSidebar();
		await aiFeedback.expandReviewSettings();
		await expect(
			page
				.getByRole('radiogroup', { name: 'Read it as' })
				.getByRole('radio', { name: /Skeptical expert/ })
		).toBeChecked();
		await expect(
			page.getByLabel('Who is this for? (optional)')
		).toHaveValue('plugin developers');

		const requestPromise = page.waitForRequest((request) => {
			const url = new URL(request.url());
			const route =
				url.searchParams.get('rest_route') ??
				url.pathname.replace(/^.*\/wp-json/, '');
			return (
				request.method() === 'POST' &&
				route === '/ai-feedback/v1/review'
			);
		});
		const response = await aiFeedback.runReview();
		const sent = (await requestPromise).postDataJSON();
		expect(sent.persona).toBe('skeptical_expert');
		expect(sent.audience).toBe('plugin developers');

		const review = await response.json();
		expect(review.persona_label).toBe('Skeptical expert');

		// The summary leads with the persona's takeaway.
		await expect(page.locator('.ai-feedback-persona-takeaway')).toHaveText(
			'Skeptical expert: Mock takeaway from the Skeptical expert.'
		);

		// Each note says which persona wrote it, in the sidebar and in the note.
		await expect(
			page
				.locator('.ai-feedback-panel .ai-feedback-persona-badge')
				.first()
		).toHaveText('Skeptical expert');
		const note = await requestUtils.rest({
			path: `/wp/v2/comments/${review.note_ids[0]}`,
			params: { context: 'edit' },
		});
		expect(note.content.rendered).toContain(
			'<span class="ai-feedback-badge ai-feedback-persona">Skeptical expert</span>'
		);

		// A reply continues in the persona's voice.
		await replyToNote(
			page,
			review.note_ids[0],
			'Faster than the last release.'
		);
		const thread = page.getByRole('list', { name: 'Conversation replies' });
		await expect(thread.locator('.ai-feedback-reply-ai')).toContainText(
			'Still speaking as the Skeptical expert: link the benchmark.',
			{ timeout: 30000 }
		);
	});

	test('a default review has no persona', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const post = await requestUtils.createPost({
			title: 'Copy editor',
			content:
				'<!-- wp:paragraph --><p>Our plugin is 40% faster.</p><!-- /wp:paragraph -->',
			status: 'draft',
		});
		await admin.editPost(post.id);
		await expect
			.poll(() => editor.getBlocks().then((blocks) => blocks.length))
			.toBe(1);
		await aiFeedback.openSidebar();

		const response = await aiFeedback.runReview();
		const review = await response.json();

		expect(review).not.toHaveProperty('persona');
		await expect(page.locator('.ai-feedback-review-summary')).toBeVisible();
		await expect(page.locator('.ai-feedback-persona-takeaway')).toHaveCount(
			0
		);
		await expect(page.locator('.ai-feedback-persona-badge')).toHaveCount(0);
	});
});
