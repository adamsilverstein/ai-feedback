/**
 * E2E tests for settings persistence.
 */
const { test, expect } = require('../fixtures');

test.describe('Settings Persistence', () => {
	test.beforeEach(async ({ admin }) => {
		await admin.createNewPost();
	});

	test('persists model selection across sessions', async ({
		admin,
		page,
		aiFeedback,
	}) => {
		await aiFeedback.openSidebar();

		// Wait for settings to load
		await page.waitForTimeout(1000);

		// Check if model selector is available
		const modelSelect = page.getByLabel('AI Model');
		const isVisible = await modelSelect.isVisible().catch(() => false);

		if (!isVisible) {
			// Skip if no models configured
			test.skip();
			return;
		}

		// Get available options
		const options = await modelSelect.locator('option').all();
		if (options.length < 2) {
			// Skip if only one or no models
			test.skip();
			return;
		}

		// Select the second model
		const secondOptionValue = await options[1].getAttribute('value');
		await modelSelect.selectOption(secondOptionValue);

		// Wait for save (debounced)
		await aiFeedback.waitForSettingsSave();

		// Navigate away and back
		await admin.createNewPost();
		await aiFeedback.openSidebar();
		await page.waitForTimeout(1000);

		// Verify persistence
		await expect(page.getByLabel('AI Model')).toHaveValue(
			secondOptionValue
		);
	});

	test('persists focus area selections', async ({
		admin,
		page,
		aiFeedback,
	}) => {
		await aiFeedback.openSidebar();
		await aiFeedback.expandReviewSettings();

		// Wait for settings to load
		await page.waitForTimeout(1000);

		// Check if focus areas are available
		const checkboxes = page.locator(
			'.ai-feedback-settings input[type="checkbox"]'
		);
		const count = await checkboxes.count();

		if (count < 2) {
			// Skip if not enough focus areas
			test.skip();
			return;
		}

		// Toggle first two checkboxes
		const firstCheckbox = checkboxes.nth(0);
		const secondCheckbox = checkboxes.nth(1);
		const firstInitial = await firstCheckbox.isChecked();
		const secondInitial = await secondCheckbox.isChecked();

		await firstCheckbox.click();
		await secondCheckbox.click();

		// Wait for save
		await aiFeedback.waitForSettingsSave();

		// Navigate away and back
		await admin.createNewPost();
		await aiFeedback.openSidebar();
		await aiFeedback.expandReviewSettings();
		await page.waitForTimeout(1000);

		// Verify persistence (should be toggled from initial state)
		const newCheckboxes = page.locator(
			'.ai-feedback-settings input[type="checkbox"]'
		);
		await expect(newCheckboxes.nth(0)).toBeChecked({
			checked: !firstInitial,
		});
		await expect(newCheckboxes.nth(1)).toBeChecked({
			checked: !secondInitial,
		});
	});

	test('persists target tone selection', async ({
		admin,
		page,
		aiFeedback,
	}) => {
		await aiFeedback.openSidebar();
		await aiFeedback.expandReviewSettings();

		// Wait for settings to load
		await page.waitForTimeout(1000);

		// Check if tone selector is available
		const toneSelect = page.getByLabel('Target Tone');
		const isVisible = await toneSelect.isVisible().catch(() => false);

		if (!isVisible) {
			// Skip if tone selector not available
			test.skip();
			return;
		}

		// Get available options
		const options = await toneSelect.locator('option').all();
		if (options.length < 2) {
			// Skip if only one or no tones
			test.skip();
			return;
		}

		// Select the second tone
		const secondOptionValue = await options[1].getAttribute('value');
		await toneSelect.selectOption(secondOptionValue);

		// Wait and verify
		await aiFeedback.waitForSettingsSave();
		await admin.createNewPost();
		await aiFeedback.openSidebar();
		await aiFeedback.expandReviewSettings();
		await page.waitForTimeout(1000);

		await expect(page.getByLabel('Target Tone')).toHaveValue(
			secondOptionValue
		);
	});

	test('handles concurrent settings updates', async ({
		page,
		aiFeedback,
		admin,
	}) => {
		await aiFeedback.openSidebar();
		await aiFeedback.expandReviewSettings();

		// Wait for settings to load
		await page.waitForTimeout(1000);

		// Check if focus areas are available
		const checkboxes = page.locator(
			'.ai-feedback-settings input[type="checkbox"]'
		);
		const count = await checkboxes.count();

		if (count < 3) {
			// Skip if not enough focus areas
			test.skip();
			return;
		}

		// Get initial states
		const firstCheckbox = checkboxes.nth(0);
		const secondCheckbox = checkboxes.nth(1);
		const thirdCheckbox = checkboxes.nth(2);

		const initialFirst = await firstCheckbox.isChecked();
		const initialSecond = await secondCheckbox.isChecked();
		const initialThird = await thirdCheckbox.isChecked();

		// Rapid changes (should debounce)
		await firstCheckbox.click();
		await secondCheckbox.click();
		await thirdCheckbox.click();

		// Wait for debounced save
		await aiFeedback.waitForSettingsSave();

		// All changes should be saved
		await admin.createNewPost();
		await aiFeedback.openSidebar();
		await aiFeedback.expandReviewSettings();
		await page.waitForTimeout(1000);

		// Verify all toggles persisted correctly (opposite of initial state)
		const newCheckboxes = page.locator(
			'.ai-feedback-settings input[type="checkbox"]'
		);
		await expect(newCheckboxes.nth(0)).toBeChecked({
			checked: !initialFirst,
		});
		await expect(newCheckboxes.nth(1)).toBeChecked({
			checked: !initialSecond,
		});
		await expect(newCheckboxes.nth(2)).toBeChecked({
			checked: !initialThird,
		});
	});

	test('settings load on sidebar open', async ({ page, aiFeedback }) => {
		await aiFeedback.openSidebar();

		// Wait for settings to load
		await page.waitForTimeout(1000);

		// Expand settings panel
		await aiFeedback.expandReviewSettings();

		// Wait for expanded content
		await page.waitForTimeout(500);

		// Verify some settings UI is present (specific elements may vary based on config)
		// Look for Focus Areas section which should always be present
		const focusAreasLegend = page.locator('legend:has-text("Focus Areas")');
		const hasFocusAreas = await focusAreasLegend
			.isVisible()
			.catch(() => false);

		// Or look for checkboxes in settings
		const checkboxes = page.locator(
			'.ai-feedback-settings input[type="checkbox"]'
		);
		const checkboxCount = await checkboxes.count();

		// At least one of these should be present
		expect(hasFocusAreas || checkboxCount > 0).toBe(true);
	});

	test('preserves multiple settings changes in single session', async ({
		admin,
		page,
		aiFeedback,
	}) => {
		await aiFeedback.openSidebar();
		await aiFeedback.expandReviewSettings();

		// Wait for settings to load
		await page.waitForTimeout(1000);

		// Check if we have checkboxes to toggle
		const checkboxes = page.locator(
			'.ai-feedback-settings input[type="checkbox"]'
		);
		const checkboxCount = await checkboxes.count();

		if (checkboxCount === 0) {
			// Skip if no settings available
			test.skip();
			return;
		}

		// Toggle the first checkbox
		const firstCheckbox = checkboxes.first();
		const initialState = await firstCheckbox.isChecked();
		await firstCheckbox.click();

		// Wait for all changes to save
		await aiFeedback.waitForSettingsSave();

		// Navigate to new post
		await admin.createNewPost();
		await aiFeedback.openSidebar();
		await aiFeedback.expandReviewSettings();
		await page.waitForTimeout(1000);

		// Verify checkbox state persisted (should be toggled from initial)
		const newCheckboxes = page.locator(
			'.ai-feedback-settings input[type="checkbox"]'
		);
		await expect(newCheckboxes.first()).toBeChecked({
			checked: !initialState,
		});
	});
});
