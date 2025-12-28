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

		// Verify sidebar is visible
		const sidebar = page.getByRole('region', { name: 'AI Feedback' });
		await expect(sidebar).toBeVisible();
	});

	test('displays model selector in sidebar', async ({ page, aiFeedback }) => {
		await aiFeedback.openSidebar();

		const modelSelect = page.getByLabel('AI Model');
		await expect(modelSelect).toBeVisible();
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

		const reviewDocumentButton = page.getByRole('button', {
			name: 'Review Document',
		});
		await expect(reviewDocumentButton).toBeVisible();
	});

	test('sidebar can be closed and reopened', async ({ page, aiFeedback }) => {
		await aiFeedback.openSidebar();

		// Close sidebar by clicking close button
		const closeButton = page.getByRole('button', { name: 'Close plugin' });
		await closeButton.click();

		// Verify sidebar is hidden
		const sidebar = page.getByRole('region', { name: 'AI Feedback' });
		await expect(sidebar).not.toBeVisible();

		// Reopen
		await aiFeedback.openSidebar();
		await expect(sidebar).toBeVisible();
	});

	test('displays focus area checkboxes in settings', async ({
		page,
		aiFeedback,
	}) => {
		await aiFeedback.openSidebar();
		await aiFeedback.expandReviewSettings();

		// Verify focus area checkboxes are present
		await expect(
			page.getByLabel('Content Quality', { exact: true })
		).toBeVisible();
		await expect(
			page.getByLabel('Tone & Voice', { exact: true })
		).toBeVisible();
		await expect(
			page.getByLabel('Flow & Structure', { exact: true })
		).toBeVisible();
	});
});
