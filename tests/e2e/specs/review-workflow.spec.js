/**
 * E2E tests for review workflow.
 */
const { test, expect } = require('../fixtures');

test.describe('Review Workflow', () => {
	test('review button state before saving post', async ({
		admin,
		page,
		aiFeedback,
	}) => {
		await admin.createNewPost();
		await aiFeedback.openSidebar();

		// Wait for sidebar to fully load
		await page.locator('.ai-feedback-panel').waitFor({ state: 'visible' });

		const panel = page.locator('.ai-feedback-panel');
		const reviewButton = panel
			.locator('button.is-primary')
			.filter({ hasText: /Review( Document)?/i })
			.first();

		// For unsaved posts, the review functionality might:
		// 1. Show helper text about saving first
		// 2. Disable the button
		// 3. Or work anyway (clicking would trigger the review which may fail)
		const helperText = panel.getByText(/save/i);
		const hasHelperText = await helperText.isVisible().catch(() => false);
		const isDisabled = await reviewButton.isDisabled().catch(() => false);
		const buttonExists = await reviewButton.isVisible().catch(() => false);

		// Button should exist in the panel
		expect(buttonExists).toBe(true);

		// The test passes if either of these conditions are met for an unsaved post:
		// - Helper text is shown
		// - Button is disabled
		expect(hasHelperText || isDisabled).toBe(true);
	});

	test('review button enables after saving post with content', async ({
		admin,
		page,
		editor,
		aiFeedback,
	}) => {
		// Add title and paragraph content
		await admin.createNewPost({ title: 'Test Post' });
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

		const reviewButton = page
			.locator('button.is-primary')
			.filter({ hasText: /Review( Document)?/i })
			.first();
		await expect(reviewButton).toBeEnabled();
	});

	test('shows reviewing state when review is initiated', async ({
		admin,
		page,
		editor,
		aiFeedback,
	}) => {
		// Setup: Add content and save
		await admin.createNewPost({ title: 'Test Post' });
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

		const reviewButton = page
			.locator('.ai-feedback-panel button.is-primary')
			.first();
		await reviewButton.click();

		// Verify reviewing state
		await expect(
			page.getByRole('button', { name: /Reviewing/i })
		).toBeVisible();
		// The actual text is "AI is analyzing your content..."
		await expect(
			page.getByText(/AI is analyzing your content/i)
		).toBeVisible();
	});

	test('displays review summary after completion', async ({
		admin,
		page,
		editor,
		aiFeedback,
	}) => {
		// Setup: Add content and save
		await admin.createNewPost({ title: 'Test Post' });
		await editor.insertBlock({ name: 'core/paragraph' });
		await page.keyboard.type('Test content for review.');
		await page.getByRole('button', { name: 'Save draft' }).click();
		await page.waitForSelector('.editor-post-saved-state.is-saved');

		await aiFeedback.openSidebar();

		// Mock the API with feedback
		// Use regex to ensure we intercept it even with query params like _locale
		await page.route(/\/ai-feedback\/v1\/review/, async (route) => {
			await new Promise((resolve) => setTimeout(resolve, 500));
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

		// Start review and wait for completion
		await aiFeedback.startReviewAndWait();

		// Check for rate limit error from real server (if mock failed somehow or pre-flight)
		const errorNotice = page.locator('.ai-feedback-error-notice');
		if (
			(await errorNotice.isVisible()) &&
			(await errorNotice.innerText()).toLowerCase().includes('rate limit')
		) {
			test.skip(true, 'Skipping due to rate limit');
		}

		// Verify summary appears - look for the summary text from our mock
		const panel = page.locator('.ai-feedback-panel');
		await expect(panel).toContainText(/Found 1 suggestion for improvement/i, {
			timeout: 10000,
		});
		await expect(panel).toContainText(/1 feedback item/i);
		await expect(panel.locator('.ai-feedback-review-summary')).toBeVisible();
	});

	test('shows success message when no issues found', async ({
		admin,
		page,
		editor,
		aiFeedback,
	}) => {
		// Setup
		await admin.createNewPost({ title: 'Perfect Post' });
		await editor.insertBlock({ name: 'core/paragraph' });
		await page.keyboard.type('Well-written content.');
		await page.getByRole('button', { name: 'Save draft' }).click();
		await page.waitForSelector('.editor-post-saved-state.is-saved');

		await aiFeedback.openSidebar();

		// Mock API with no issues (using regex)
		await page.route(/\/ai-feedback\/v1\/review/, async (route) => {
			await new Promise((resolve) => setTimeout(resolve, 500));
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

		// Start review and wait for completion
		await aiFeedback.startReviewAndWait();

		// Check for rate limit
		const errorNotice = page.locator('.ai-feedback-error-notice');
		if (
			(await errorNotice.isVisible()) &&
			(await errorNotice.innerText()).toLowerCase().includes('rate limit')
		) {
			test.skip(true, 'Skipping due to rate limit');
		}

		// Ensure a success indicator is visible
		const panel = page.locator('.ai-feedback-panel');
		await expect(panel.getByText(/Great job/i)).toBeVisible({
			timeout: 10000,
		});
		await expect(panel.getByText(/no feedback items/i)).toBeVisible();
	});

	test('displays error message when API fails', async ({
		admin,
		page,
		editor,
		aiFeedback,
	}) => {
		// Setup
		await admin.createNewPost({ title: 'Error Post' });
		await editor.insertBlock({ name: 'core/paragraph' });
		await page.keyboard.type('Content to trigger error.');
		await page.getByRole('button', { name: 'Save draft' }).click();
		await page.waitForSelector('.editor-post-saved-state.is-saved');

		await aiFeedback.openSidebar();

		// Mock API failure (using regex)
		await page.route(/\/ai-feedback\/v1\/review/, async (route) => {
			await new Promise((resolve) => setTimeout(resolve, 500));
			await route.fulfill({
				status: 500,
				contentType: 'application/json',
				body: JSON.stringify({
					code: 'ai_request_failed',
					message: 'The AI provider is currently unavailable.',
				}),
			});
		});

		// Start review and wait for completion
		await aiFeedback.startReviewAndWait();

		// Verify error message is shown
		const errorNotice = page.locator('.ai-feedback-error-notice');
		await expect(errorNotice).toBeVisible();
		await expect(errorNotice).toContainText(
			/The AI provider is currently unavailable/i
		);
		await expect(errorNotice).toContainText(/ai_request_failed/i);
	});
});
