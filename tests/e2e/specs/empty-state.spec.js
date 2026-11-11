/**
 * E2E tests for Empty State component.
 */
const { test, expect } = require('../fixtures');

test.describe('Empty State', () => {
	test('shows empty state when no reviews exist', async ({
		admin,
		page,
		aiFeedback,
	}) => {
		await admin.createNewPost();
		await aiFeedback.openSidebar();

		// Verify empty state is visible
		await expect(page.getByText('No reviews yet')).toBeVisible();
		await expect(
			page.getByText(
				'Get AI-powered feedback on your writing to improve clarity, tone, and structure.'
			)
		).toBeVisible();
	});

	test.describe('prerequisites', () => {
		test.beforeEach(async ({ page }) => {
			// The welcome modal opens after an async status check and can
			// cover the sidebar, so mark it as seen.
			await page.addInitScript(() => {
				try {
					window.localStorage.setItem('ai-feedback-welcomed', 'true');
				} catch {
					// Storage unavailable; the modal may still open.
				}
			});
		});

		test('asks for content and a save on a new post', async ({
			admin,
			page,
		}) => {
			await admin.createNewPost();
			const panel = await openPanel(page);

			await expect(panel.getByText('Before you start:')).toBeVisible();
			await expect(
				panel.getByText('Add some content to your post')
			).toBeVisible();
			await expect(
				panel.getByText('Save your post as a draft')
			).toBeVisible();
			await expect(
				panel.getByRole('button', { name: 'Review Document' })
			).toHaveCount(0);
		});

		test('asks for a save when an unsaved post has content', async ({
			admin,
			editor,
			page,
		}) => {
			await admin.createNewPost({ title: 'Unsaved Post' });
			await editor.insertBlock({
				name: 'core/paragraph',
				attributes: { content: 'Content that has not been saved.' },
			});
			const panel = await openPanel(page);

			await expect(
				panel.getByText('Add some content to your post')
			).toBeHidden();
			await expect(
				panel.getByText('Save your post as a draft')
			).toBeVisible();
			await expect(
				panel.getByRole('button', { name: 'Review Document' })
			).toHaveCount(0);

			await editor.saveDraft();

			await expect(panel.getByText('Before you start:')).toBeHidden();
			await expect(
				panel.getByRole('button', { name: 'Review Document' })
			).toBeEnabled();
		});
	});

	test.skip('shows Review Document button when post is saved with content', async ({
		admin,
		page,
		editor,
		aiFeedback,
	}) => {
		await admin.createNewPost();

		// Add content
		await editor.canvas
			.getByRole('textbox', { name: 'Add title' })
			.fill('Test Post');
		await editor.canvas
			.getByRole('textbox', { name: 'Type / to choose a block' })
			.fill('This is test content for the post.');

		// Save the post
		await page.getByRole('button', { name: 'Save draft' }).click();

		// Wait for save to complete
		await page.waitForSelector('button:has-text("Saved")', {
			timeout: 10000,
		});

		await aiFeedback.openSidebar();

		// Prerequisites should not be shown
		await expect(page.getByText('Before you start:')).not.toBeVisible();

		// Review button should be visible in empty state
		const reviewButtons = page.getByRole('button', {
			name: 'Review Document',
		});
		await expect(reviewButtons.first()).toBeVisible();
	});

	test.skip('hides empty state after a review is completed', async ({
		admin,
		page,
		editor,
		aiFeedback,
	}) => {
		// Mock the review endpoint to return a successful response
		await page.route('**/wp-json/ai-feedback/v1/review', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					review_id: 1,
					post_id: 1,
					model: 'test-model',
					note_count: 1,
					notes: [],
					summary_text: 'Test review summary',
					block_mapping: {},
				}),
			});
		});

		await admin.createNewPost();

		// Add content and save
		await editor.canvas
			.getByRole('textbox', { name: 'Add title' })
			.fill('Test Post');
		await editor.canvas
			.getByRole('textbox', { name: 'Type / to choose a block' })
			.fill('This is test content for the post.');

		await page.getByRole('button', { name: 'Save draft' }).click();
		await page.waitForSelector('button:has-text("Saved")', {
			timeout: 10000,
		});

		await aiFeedback.openSidebar();

		// Click review button from empty state
		await page
			.getByRole('button', { name: 'Review Document' })
			.first()
			.click();

		// Wait for the review to complete by watching for the Last Review panel
		await expect(
			page.getByRole('button', { name: 'Last Review' })
		).toBeVisible({ timeout: 5000 });

		// Empty state should no longer be visible
		await expect(page.getByText('No reviews yet')).not.toBeVisible();

		// Review settings panel should now be visible
		await expect(
			page.getByRole('button', { name: 'Review Settings' })
		).toBeVisible();
	});

	test('empty state icon is visible', async ({ admin, page, aiFeedback }) => {
		await admin.createNewPost();
		await aiFeedback.openSidebar();

		// Check for the icon container
		const iconContainer = page.locator('.ai-feedback-empty-icon');
		await expect(iconContainer).toBeVisible();
	});
});

/**
 * Open the AI Feedback sidebar from its pinned top bar button.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {Promise<import('@playwright/test').Locator>} The panel.
 */
async function openPanel(page) {
	const toggle = page
		.getByRole('region', { name: 'Editor top bar' })
		.getByRole('button', { name: 'AI Feedback' });
	await toggle.waitFor();
	if ((await toggle.getAttribute('aria-pressed')) !== 'true') {
		await toggle.click();
	}
	const panel = page.locator('.ai-feedback-panel');
	await expect(panel.getByText('No reviews yet')).toBeVisible();
	return panel;
}
