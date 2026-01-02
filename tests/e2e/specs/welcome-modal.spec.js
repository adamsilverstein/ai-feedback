/**
 * E2E tests for Welcome Modal component.
 */
const { test, expect } = require('../fixtures');

test.describe('Welcome Modal', () => {
	// Clear localStorage before each test to ensure fresh state
	test.beforeEach(async ({ page }) => {
		await page.addInitScript(() => {
			window.localStorage.removeItem('ai-feedback-welcomed');
		});
	});

	test('shows welcome modal on first sidebar open', async ({
		admin,
		page,
		aiFeedback,
	}) => {
		await admin.createNewPost();
		await aiFeedback.openSidebar();

		// Verify welcome modal is visible
		await expect(
			page.getByRole('dialog', { name: 'Welcome to AI Feedback' })
		).toBeVisible();
		await expect(
			page.getByText(
				'Get AI-powered editorial feedback on your content right in the editor.'
			)
		).toBeVisible();
	});

	test('displays 3-step guide with icons', async ({
		admin,
		page,
		aiFeedback,
	}) => {
		await admin.createNewPost();
		await aiFeedback.openSidebar();

		// Verify steps are visible
		await expect(page.getByText('Get started in 3 steps:')).toBeVisible();
		await expect(
			page.getByText('Write your content in the editor')
		).toBeVisible();
		await expect(page.getByText('Save your post as a draft')).toBeVisible();
		await expect(
			page.getByText('Click "Review Document" to get AI feedback')
		).toBeVisible();
	});

	test('shows Get Started and Learn More buttons', async ({
		admin,
		page,
		aiFeedback,
	}) => {
		await admin.createNewPost();
		await aiFeedback.openSidebar();

		// Verify buttons are present
		await expect(
			page.getByRole('button', { name: 'Get Started' })
		).toBeVisible();
		await expect(
			page.getByRole('link', { name: 'Learn More' })
		).toBeVisible();
	});

	test('dismisses modal when Get Started button is clicked', async ({
		admin,
		page,
		aiFeedback,
	}) => {
		await admin.createNewPost();
		await aiFeedback.openSidebar();

		// Click Get Started button
		await page.getByRole('button', { name: 'Get Started' }).click();

		// Modal should be hidden
		await expect(
			page.getByRole('dialog', { name: 'Welcome to AI Feedback' })
		).not.toBeVisible();
	});

	test('dismisses modal when close button is clicked', async ({
		admin,
		page,
		aiFeedback,
	}) => {
		await admin.createNewPost();
		await aiFeedback.openSidebar();

		// Click the close button (X)
		await page.getByRole('button', { name: 'Close' }).click();

		// Modal should be hidden
		await expect(
			page.getByRole('dialog', { name: 'Welcome to AI Feedback' })
		).not.toBeVisible();
	});

	test('does not show welcome modal after dismissal', async ({
		admin,
		page,
		aiFeedback,
	}) => {
		await admin.createNewPost();
		await aiFeedback.openSidebar();

		// Dismiss modal
		await page.getByRole('button', { name: 'Get Started' }).click();

		// Modal should be hidden
		await expect(
			page.getByRole('dialog', { name: 'Welcome to AI Feedback' })
		).not.toBeVisible();

		// Create a new post
		await admin.createNewPost();
		await aiFeedback.openSidebar();

		// Welcome modal should not appear again
		await expect(
			page.getByRole('dialog', { name: 'Welcome to AI Feedback' })
		).not.toBeVisible();
	});

	test('stores welcomed state in localStorage', async ({
		admin,
		page,
		aiFeedback,
	}) => {
		await admin.createNewPost();
		await aiFeedback.openSidebar();

		// Check localStorage before dismissing
		const beforeDismiss = await page.evaluate(() =>
			window.localStorage.getItem('ai-feedback-welcomed')
		);
		expect(beforeDismiss).toBeNull();

		// Dismiss modal
		await page.getByRole('button', { name: 'Get Started' }).click();

		// Check localStorage after dismissing
		const afterDismiss = await page.evaluate(() =>
			window.localStorage.getItem('ai-feedback-welcomed')
		);
		expect(afterDismiss).toBe('true');
	});

	test('Learn More link opens in new tab', async ({
		admin,
		page,
		aiFeedback,
	}) => {
		await admin.createNewPost();
		await aiFeedback.openSidebar();

		// Get the Learn More link
		const learnMoreLink = page.getByRole('link', { name: 'Learn More' });
		await expect(learnMoreLink).toBeVisible();

		// Verify it has target="_blank"
		await expect(learnMoreLink).toHaveAttribute('target', '_blank');
	});

	test('does not show welcome modal when localStorage flag is set', async ({
		admin,
		page,
		aiFeedback,
	}) => {
		// Set localStorage flag before navigation
		await page.addInitScript(() => {
			window.localStorage.setItem('ai-feedback-welcomed', 'true');
		});

		await admin.createNewPost();
		await aiFeedback.openSidebar();

		// Modal should not be visible
		await expect(
			page.getByRole('dialog', { name: 'Welcome to AI Feedback' })
		).not.toBeVisible();
	});
});
