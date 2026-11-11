/**
 * E2E tests for keyboard shortcuts.
 */
const { test, expect } = require('../fixtures');

test.describe('Keyboard shortcuts', () => {
	test('toggles the AI Feedback sidebar', async ({
		admin,
		page,
		pageUtils,
		aiFeedback,
	}) => {
		await admin.createNewPost();

		// Opening once through the UI also dismisses the welcome modal.
		await aiFeedback.openSidebar();
		const panel = page.locator('.ai-feedback-panel');

		await pageUtils.pressKeys('access+f');
		await expect(panel).toBeHidden();

		await pageUtils.pressKeys('access+f');
		await expect(panel).toBeVisible();
	});

	test('lists the shortcuts in the keyboard shortcuts help', async ({
		admin,
		page,
		pageUtils,
	}) => {
		await admin.createNewPost();

		await pageUtils.pressKeys('access+h');

		const dialog = page.getByRole('dialog', {
			name: 'Keyboard shortcuts',
		});
		await expect(
			dialog.getByText('Show or hide the AI Feedback sidebar')
		).toBeVisible();
		await expect(
			dialog.getByText('Review the document with AI')
		).toBeVisible();
	});
});
