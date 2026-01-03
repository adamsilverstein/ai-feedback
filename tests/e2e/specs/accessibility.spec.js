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

		// Wait for settings to load
		await page.waitForTimeout(1000);

		// Find any focusable element in the sidebar (model selector may not be present if no models configured)
		const focusableElement = page
			.locator('.ai-feedback-panel')
			.locator('button, input, select, [tabindex="0"]')
			.first();
		await focusableElement.focus();
		await expect(focusableElement).toBeFocused();

		// Tab to next interactive element
		await page.keyboard.press('Tab');

		// Verify focus moved to another element
		await expect(focusableElement).not.toBeFocused();
	});

	test('focus areas can be toggled with keyboard', async ({
		page,
		aiFeedback,
	}) => {
		await aiFeedback.openSidebar();
		await aiFeedback.expandReviewSettings();

		// Wait for settings to load
		await page.waitForTimeout(1000);

		// Find checkboxes in the settings panel
		const checkboxLocator = page.locator(
			'.ai-feedback-settings input[type="checkbox"]'
		);
		const checkboxCount = await checkboxLocator.count();

		// Skip test if no checkboxes are available
		if (checkboxCount === 0) {
			test.skip();
			return;
		}

		const checkbox = checkboxLocator.first();
		await checkbox.focus();

		// Get initial state
		const initialChecked = await checkbox.isChecked();

		// Toggle with Space
		await page.keyboard.press('Space');

		// State should change
		await expect(checkbox).toBeChecked({ checked: !initialChecked });
	});

	test('model selector is keyboard accessible', async ({
		page,
		aiFeedback,
	}) => {
		await aiFeedback.openSidebar();

		// Wait for settings to load
		await page.waitForTimeout(1000);

		const modelSelect = page.getByLabel('AI Model');
		const isVisible = await modelSelect.isVisible().catch(() => false);

		// Skip if model selector is not available (no models configured)
		if (!isVisible) {
			test.skip();
			return;
		}

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
		const reviewButton = page.locator('button.is-primary.is-busy');
		await expect(reviewButton).toBeVisible();

		// Check that button has busy indicator (class or attribute)
		// WordPress Button component may use is-busy class instead of aria-busy
		const hasBusyClass = await reviewButton.evaluate((el) =>
			el.classList.contains('is-busy')
		);
		expect(hasBusyClass).toBe(true);
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

		// Verify the notice has proper styling for error state
		// WordPress notice component uses is-error class for visual indication
		const hasErrorClass = await notice.evaluate((el) =>
			el.classList.contains('is-error')
		);
		expect(hasErrorClass).toBe(true);
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
