/**
 * E2E tests for review workflow.
 */
const { test, expect } = require('../fixtures');

test.describe('Review Workflow', () => {
	test.beforeEach(async ({ admin }) => {
		await admin.createNewPost();
	});

	test('review button shows save message without post ID', async ({
		page,
		aiFeedback,
	}) => {
		await aiFeedback.openSidebar();

		// New unsaved post - button should show helper text
		await expect(
			page.getByText('Save your post first to enable review')
		).toBeVisible();
	});

	test('review button enables after saving post with content', async ({
		page,
		editor,
		aiFeedback,
	}) => {
		// Add title
		await page
			.getByRole('textbox', { name: 'Add title' })
			.fill('Test Post');

		// Add paragraph content
		await editor.insertBlock({ name: 'core/paragraph' });
		await page.keyboard.type('This is test content for the AI review.');

		// Save draft to get post ID
		await page.getByRole('button', { name: 'Save draft' }).click();
		await page.waitForSelector('.editor-post-saved-state.is-saved');

		await aiFeedback.openSidebar();

		// Helper text should be gone and button should be enabled
		await expect(
			page.getByText('Save your post first to enable review')
		).not.toBeVisible();

		const reviewButton = page.getByRole('button', {
			name: 'Review Document',
		});
		await expect(reviewButton).toBeEnabled();
	});

	test('shows reviewing state when review is initiated', async ({
		page,
		editor,
		aiFeedback,
	}) => {
		// Setup: Add content and save
		await page
			.getByRole('textbox', { name: 'Add title' })
			.fill('Test Post');
		await editor.insertBlock({ name: 'core/paragraph' });
		await page.keyboard.type('Test content for review.');
		await page.getByRole('button', { name: 'Save draft' }).click();
		await page.waitForSelector('.editor-post-saved-state.is-saved');

		await aiFeedback.openSidebar();

		// Mock the API to add a delay
		await page.route('**/wp-json/ai-feedback/v1/review', async (route) => {
			await new Promise((resolve) => setTimeout(resolve, 2000));
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					review_id: 'test-review-id',
					post_id: 1,
					model: 'gpt-4o',
					notes: [],
					note_ids: [],
					block_mapping: {},
					summary: { by_severity: {}, by_category: {} },
					summary_text: 'No issues found.',
					note_count: 0,
					timestamp: new Date().toISOString(),
				}),
			});
		});

		// Click review button
		await page.getByRole('button', { name: 'Review Document' }).click();

		// Verify reviewing state
		await expect(
			page.getByRole('button', { name: /Reviewing/i })
		).toBeVisible();
		await expect(
			page.getByText('AI is analyzing your content')
		).toBeVisible();
	});

	test('displays review summary after completion', async ({
		page,
		editor,
		aiFeedback,
	}) => {
		// Setup: Add content and save
		await page
			.getByRole('textbox', { name: 'Add title' })
			.fill('Test Post');
		await editor.insertBlock({ name: 'core/paragraph' });
		await page.keyboard.type('Test content for review.');
		await page.getByRole('button', { name: 'Save draft' }).click();
		await page.waitForSelector('.editor-post-saved-state.is-saved');

		await aiFeedback.openSidebar();

		// Mock the API with feedback
		await page.route('**/wp-json/ai-feedback/v1/review', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					review_id: 'test-review-id',
					post_id: 1,
					model: 'gpt-4o',
					notes: [
						{
							id: 1,
							content: 'Consider adding more detail.',
							block_id: 'block-1',
						},
					],
					note_ids: [1],
					block_mapping: {},
					summary: {
						by_severity: { suggestion: 1 },
						by_category: { content: 1 },
					},
					summary_text: 'Found 1 suggestion for improvement.',
					note_count: 1,
					timestamp: new Date().toISOString(),
				}),
			});
		});

		await page.getByRole('button', { name: 'Review Document' }).click();

		// Wait for and verify summary
		await expect(page.getByText('1 feedback item')).toBeVisible({
			timeout: 10000,
		});
		await expect(
			page.getByText('Found 1 suggestion for improvement.')
		).toBeVisible();
	});

	test('shows success message when no issues found', async ({
		page,
		editor,
		aiFeedback,
	}) => {
		// Setup
		await page
			.getByRole('textbox', { name: 'Add title' })
			.fill('Perfect Post');
		await editor.insertBlock({ name: 'core/paragraph' });
		await page.keyboard.type('Well-written content.');
		await page.getByRole('button', { name: 'Save draft' }).click();
		await page.waitForSelector('.editor-post-saved-state.is-saved');

		await aiFeedback.openSidebar();

		// Mock API with no issues
		await page.route('**/wp-json/ai-feedback/v1/review', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					review_id: 'test-review-id',
					post_id: 1,
					model: 'gpt-4o',
					notes: [],
					note_ids: [],
					block_mapping: {},
					summary: { by_severity: {}, by_category: {} },
					summary_text: '',
					note_count: 0,
					timestamp: new Date().toISOString(),
				}),
			});
		});

		await page.getByRole('button', { name: 'Review Document' }).click();

		await expect(
			page.getByText('Great job! The AI found no issues')
		).toBeVisible({ timeout: 10000 });
	});
});
