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

	test('shows prerequisites when post is not saved', async ({
		admin,
		page,
		aiFeedback,
	}) => {
		await admin.createNewPost();
		await aiFeedback.openSidebar();

		// Verify prerequisites are shown
		await expect(page.getByText('Before you start:')).toBeVisible();
		await expect(
			page.getByText('Add some content to your post')
		).toBeVisible();
		await expect(page.getByText('Save your post as a draft')).toBeVisible();

		// Review button should not be visible
		await expect(
			page.getByRole('button', { name: 'Review Document' })
		).not.toBeVisible();
	});

	test('shows only save prerequisite when post has content but is not saved', async ({
		admin,
		page,
		editor,
		aiFeedback,
	}) => {
		await admin.createNewPost();

		// Add some content
		await editor.canvas
			.getByRole('textbox', { name: 'Add title' })
			.fill('Test Post');
		await editor.canvas
			.getByRole('textbox', { name: 'Type / to choose a block' })
			.fill('This is test content for the post.');

		await aiFeedback.openSidebar();

		// Should show save prerequisite but not content prerequisite
		await expect(page.getByText('Before you start:')).toBeVisible();
		await expect(
			page.getByText('Add some content to your post')
		).not.toBeVisible();
		await expect(page.getByText('Save your post as a draft')).toBeVisible();
	});

	test('shows Review Document button when post is saved with content', async ({
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

	test('hides empty state after a review is completed', async ({
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

		// Wait for review to complete
		await page.waitForTimeout(1000);

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
