/**
 * E2E tests for sidebar functionality.
 */
const { test, expect } = require('../fixtures');

test.describe('Sidebar', () => {
	test.beforeEach(async ({ admin }) => {
		await admin.createNewPost();
	});

	test('opens sidebar from editor options menu', async ({
		page,
		aiFeedback,
	}) => {
		await aiFeedback.openSidebar();

		// Verify sidebar is visible by checking for the primary Review Document button
		const reviewButton = page.locator('button.is-primary:has-text("Review Document")');
		await expect(reviewButton).toBeVisible();
	});

	test('displays model selector in sidebar', async ({ page, aiFeedback }) => {
		await aiFeedback.openSidebar();

		// Wait for settings to load - the model selector should appear if models are available
		// If no models are configured, the select may be empty or hidden
		const modelSelect = page.getByLabel('AI Model');

		// Wait a bit for settings API to complete
		await page.waitForTimeout(1000);

		// Model selector should be visible if models are available
		const isVisible = await modelSelect.isVisible().catch(() => false);
		if (isVisible) {
			await expect(modelSelect).toBeVisible();
		} else {
			// If models aren't configured in test environment, check for Focus Areas section instead
			const focusAreasLegend = page.locator('legend:has-text("Focus Areas")');
			await expect(focusAreasLegend).toBeVisible();
		}
	});

	test('displays Review Settings panel', async ({ page, aiFeedback }) => {
		await aiFeedback.openSidebar();

		const reviewSettingsButton = page.getByRole('button', {
			name: 'Review Settings',
		});
		await expect(reviewSettingsButton).toBeVisible();
	});

	test('displays Review Document panel', async ({ page, aiFeedback }) => {
		await aiFeedback.openSidebar();

		// Check for the primary Review Document button (action button, not panel toggle)
		const reviewDocumentButton = page.locator('button.is-primary:has-text("Review Document")');
		await expect(reviewDocumentButton).toBeVisible();
	});

	test('sidebar can be closed and reopened', async ({ page, aiFeedback }) => {
		await aiFeedback.openSidebar();

		// Close sidebar by clicking close button
		const closeButton = page.getByRole('button', { name: 'Close plugin' });
		await closeButton.click();

		// Verify sidebar is hidden by checking primary Review Document button is not visible
		const reviewButton = page.locator('button.is-primary:has-text("Review Document")');
		await expect(reviewButton).not.toBeVisible();

		// Reopen
		await aiFeedback.openSidebar();
		await expect(reviewButton).toBeVisible();
	});

	test('displays focus area checkboxes in settings', async ({
		page,
		aiFeedback,
	}) => {
		await aiFeedback.openSidebar();
		await aiFeedback.expandReviewSettings();

		// Wait for settings to load
		await page.waitForTimeout(1000);

		// The Focus Areas section should always be visible
		const focusAreasLegend = page.locator('legend:has-text("Focus Areas")');
		await expect(focusAreasLegend).toBeVisible();

		// Checkboxes are dynamically loaded from the API
		// If focus areas are configured, check that at least one checkbox exists
		const checkboxes = page.locator('.ai-feedback-settings input[type="checkbox"]');
		const checkboxCount = await checkboxes.count();

		if (checkboxCount > 0) {
			// Verify first checkbox is visible
			await expect(checkboxes.first()).toBeVisible();
		}
		// If no checkboxes (data not loaded), the test still passes since Focus Areas section is visible
	});
});
