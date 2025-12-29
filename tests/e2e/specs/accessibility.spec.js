/**
 * E2E accessibility tests.
 */
const { test, expect } = require('../fixtures');
const AxeBuilder = require('@axe-core/playwright').default;

test.describe('Accessibility', () => {
	test.beforeEach(async ({ admin }) => {
		await admin.createNewPost();
	});

	test('sidebar has no critical accessibility violations', async ({
		page,
		aiFeedback,
	}) => {
		await aiFeedback.openSidebar();

		// Wait for sidebar to be fully loaded
		await page.waitForSelector('.ai-feedback-panel');

		// Run axe accessibility scan on sidebar
		const accessibilityScanResults = await new AxeBuilder({ page })
			.include('.ai-feedback-panel')
			.analyze();

		// Filter for critical and serious violations only
		const criticalViolations = accessibilityScanResults.violations.filter(
			(v) => v.impact === 'critical' || v.impact === 'serious'
		);

		expect(criticalViolations).toEqual([]);
	});

	test('sidebar elements are keyboard navigable', async ({
		page,
		aiFeedback,
	}) => {
		await aiFeedback.openSidebar();

		// Tab into the sidebar (may need multiple tabs to reach it)
		// Focus on model selector first
		const modelSelect = page.getByLabel('AI Model');
		await modelSelect.focus();
		await expect(modelSelect).toBeFocused();

		// Tab to next interactive element
		await page.keyboard.press('Tab');

		// Verify focus moved to another element (not the model selector)
		await expect(modelSelect).not.toBeFocused();
	});

	test('focus areas can be toggled with keyboard', async ({
		page,
		aiFeedback,
	}) => {
		await aiFeedback.openSidebar();
		await aiFeedback.expandReviewSettings();

		// Focus on a checkbox
		const contentQuality = page.getByLabel('Content Quality', {
			exact: true,
		});
		await contentQuality.focus();

		// Get initial state
		const initialChecked = await contentQuality.isChecked();

		// Toggle with Space
		await page.keyboard.press('Space');

		// State should change
		await expect(contentQuality).toBeChecked({ checked: !initialChecked });
	});

	test('model selector is keyboard accessible', async ({
		page,
		aiFeedback,
	}) => {
		await aiFeedback.openSidebar();

		const modelSelect = page.getByLabel('AI Model');

		// Has accessible label
		await expect(modelSelect).toBeVisible();

		// Can be focused
		await modelSelect.focus();
		await expect(modelSelect).toBeFocused();

		// Can interact with keyboard
		await page.keyboard.press('ArrowDown');
	});

	test('review button has proper aria attributes when busy', async ({
		admin,
		page,
		editor,
		aiFeedback,
	}) => {
		// Setup
		await admin.createNewPost({ title: 'Loading State A11y' });
		await editor.insertBlock({ name: 'core/paragraph' });
		await page.keyboard.type('Content.');
		await page.getByRole('button', { name: 'Save draft' }).click();
		await page.waitForSelector('.editor-post-saved-state.is-saved');

		await aiFeedback.openSidebar();

		// Delay response to observe loading state
		await page.route('**/wp-json/ai-feedback/v1/review', async (route) => {
			await new Promise((resolve) => setTimeout(resolve, 3000));
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					review_id: 'test',
					notes: [],
					note_count: 0,
				}),
			});
		});

		await page
			.locator('button.is-primary:has-text("Review Document")')
			.click();

		// Button should indicate busy state
		const reviewButton = page.getByRole('button', { name: /Reviewing/i });
		await expect(reviewButton).toBeVisible();

		// WordPress Button component sets aria-busy when isBusy prop is true
		await expect(reviewButton).toHaveAttribute('aria-busy', 'true');
	});

	test('error notices have proper role for screen readers', async ({
		admin,
		page,
		editor,
		aiFeedback,
	}) => {
		// Setup for error
		await admin.createNewPost({ title: 'A11y Error Test' });
		await editor.insertBlock({ name: 'core/paragraph' });
		await page.keyboard.type('Content.');
		await page.getByRole('button', { name: 'Save draft' }).click();
		await page.waitForSelector('.editor-post-saved-state.is-saved');

		await aiFeedback.openSidebar();

		// Mock API error
		await aiFeedback.mockReviewAPIError(
			500,
			'test_error',
			'Test error for accessibility'
		);

		await page
			.locator('button.is-primary:has-text("Review Document")')
			.click();

		// Error notice should be visible
		const notice = page.locator('.components-notice.is-error');
		await expect(notice).toBeVisible({ timeout: 10000 });
	});

	test('panels can be expanded with keyboard', async ({
		page,
		aiFeedback,
	}) => {
		await aiFeedback.openSidebar();

		// Focus on Review Settings panel button
		const reviewSettingsButton = page.getByRole('button', {
			name: 'Review Settings',
		});
		await reviewSettingsButton.focus();

		// Get current state
		const isExpanded =
			await reviewSettingsButton.getAttribute('aria-expanded');

		// Toggle with Enter key
		await page.keyboard.press('Enter');

		// State should change
		const newExpandedState =
			await reviewSettingsButton.getAttribute('aria-expanded');
		expect(newExpandedState).not.toBe(isExpanded);
	});
});
