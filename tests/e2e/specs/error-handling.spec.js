/**
 * E2E tests for error handling.
 */
const { test, expect } = require('../fixtures');

test.describe('Error Handling', () => {
	test('displays error notice when API request fails', async ({
		admin,
		page,
		editor,
		aiFeedback,
	}) => {
		// Setup - create post with title and content
		await admin.createNewPost({ title: 'Error Test Post' });
		await editor.insertBlock({ name: 'core/paragraph' });
		await page.keyboard.type('Content for error test.');
		await page.getByRole('button', { name: 'Save draft' }).click();
		await page.waitForSelector('.editor-post-saved-state.is-saved');

		await aiFeedback.openSidebar();

		// Mock API failure
		await aiFeedback.mockReviewAPIError(
			500,
			'ai_request_failed',
			'AI request failed: Internal server error'
		);

		await page
			.locator('button.is-primary:has-text("Review Document")')
			.click();

		// Verify error notice appears
		await expect(page.getByText('AI request failed')).toBeVisible({
			timeout: 10000,
		});
		await expect(page.getByText('Error code:')).toBeVisible();
	});

	test('error notice can be dismissed', async ({
		admin,
		page,
		editor,
		aiFeedback,
	}) => {
		// Setup with error
		await admin.createNewPost({ title: 'Dismissable Error' });
		await editor.insertBlock({ name: 'core/paragraph' });
		await page.keyboard.type('Content.');
		await page.getByRole('button', { name: 'Save draft' }).click();
		await page.waitForSelector('.editor-post-saved-state.is-saved');

		await aiFeedback.openSidebar();

		await aiFeedback.mockReviewAPIError(
			500,
			'unknown_error',
			'Something went wrong'
		);

		await page
			.locator('button.is-primary:has-text("Review Document")')
			.click();

		// Wait for error
		await expect(page.getByText('Something went wrong')).toBeVisible({
			timeout: 10000,
		});

		// Dismiss the notice
		const dismissButton = page.getByRole('button', { name: /Dismiss/i });
		await dismissButton.click();

		// Error should be hidden
		await expect(page.getByText('Something went wrong')).not.toBeVisible();
	});

	test('displays rate limit error with appropriate message', async ({
		admin,
		page,
		editor,
		aiFeedback,
	}) => {
		// Setup
		await admin.createNewPost({ title: 'Rate Limit Test' });
		await editor.insertBlock({ name: 'core/paragraph' });
		await page.keyboard.type('Content.');
		await page.getByRole('button', { name: 'Save draft' }).click();
		await page.waitForSelector('.editor-post-saved-state.is-saved');

		await aiFeedback.openSidebar();

		await aiFeedback.mockReviewAPIError(
			429,
			'rate_limit_exceeded',
			'You have reached the maximum number of reviews per hour.'
		);

		await page
			.locator('button.is-primary:has-text("Review Document")')
			.click();

		await expect(page.getByText('rate_limit_exceeded')).toBeVisible({
			timeout: 10000,
		});
		await expect(
			page.getByText('Please wait before making another request')
		).toBeVisible();
	});

	test('displays billing/credit error with settings link', async ({
		admin,
		page,
		editor,
		aiFeedback,
	}) => {
		// Setup
		await admin.createNewPost({ title: 'Billing Error Test' });
		await editor.insertBlock({ name: 'core/paragraph' });
		await page.keyboard.type('Content.');
		await page.getByRole('button', { name: 'Save draft' }).click();
		await page.waitForSelector('.editor-post-saved-state.is-saved');

		await aiFeedback.openSidebar();

		await aiFeedback.mockReviewAPIError(
			402,
			'ai_request_failed',
			'AI request failed: Insufficient credit balance'
		);

		await page
			.locator('button.is-primary:has-text("Review Document")')
			.click();

		await expect(page.getByText('Insufficient credit')).toBeVisible({
			timeout: 10000,
		});
		await expect(
			page.getByRole('link', { name: 'Go to Settings' })
		).toBeVisible();
	});

	test('handles network timeout gracefully', async ({
		admin,
		page,
		editor,
		aiFeedback,
	}) => {
		// Setup
		await admin.createNewPost({ title: 'Timeout Test' });
		await editor.insertBlock({ name: 'core/paragraph' });
		await page.keyboard.type('Content.');
		await page.getByRole('button', { name: 'Save draft' }).click();
		await page.waitForSelector('.editor-post-saved-state.is-saved');

		await aiFeedback.openSidebar();

		// Abort the request to simulate network failure
		await page.route('**/wp-json/ai-feedback/v1/review', async (route) => {
			await route.abort('timedout');
		});

		await page
			.locator('button.is-primary:has-text("Review Document")')
			.click();

		// Should show some error state - button should return to normal
		await expect(
			page.locator('button.is-primary:has-text("Review Document")')
		).toBeVisible({ timeout: 10000 });
	});

	test('shows warning when reviewing empty content', async ({
		admin,
		page,
		aiFeedback,
	}) => {
		// Don't add any content, just try to review
		// Save as draft to get post ID
		await admin.createNewPost({ title: 'Empty Post' });
		await page.getByRole('button', { name: 'Save draft' }).click();
		await page.waitForSelector('.editor-post-saved-state.is-saved');

		await aiFeedback.openSidebar();

		await page
			.locator('button.is-primary:has-text("Review Document")')
			.click();

		// Should show warning notice about no content (snackbar)
		await expect(
			page.getByText('No content blocks found to review')
		).toBeVisible({ timeout: 5000 });
	});
});
