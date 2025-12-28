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

		// Change model
		await aiFeedback.selectModel('gpt-4o');

		// Wait for save (debounced)
		await aiFeedback.waitForSettingsSave();

		// Navigate away and back
		await admin.createNewPost();
		await aiFeedback.openSidebar();

		// Verify persistence
		const modelSelect = page.getByLabel('AI Model');
		await expect(modelSelect).toHaveValue('gpt-4o');
	});

	test('persists focus area selections', async ({
		admin,
		page,
		aiFeedback,
	}) => {
		await aiFeedback.openSidebar();
		await aiFeedback.expandReviewSettings();

		// Toggle focus areas
		await aiFeedback.toggleFocusArea('Content Quality', false);
		await aiFeedback.toggleFocusArea('Design & Formatting', true);

		// Wait for save
		await aiFeedback.waitForSettingsSave();

		// Navigate away and back
		await admin.createNewPost();
		await aiFeedback.openSidebar();
		await aiFeedback.expandReviewSettings();

		// Verify persistence
		await expect(
			page.getByLabel('Content Quality', { exact: true })
		).not.toBeChecked();
		await expect(
			page.getByLabel('Design & Formatting', { exact: true })
		).toBeChecked();
	});

	test('persists target tone selection', async ({
		admin,
		page,
		aiFeedback,
	}) => {
		await aiFeedback.openSidebar();
		await aiFeedback.expandReviewSettings();

		// Change tone
		await aiFeedback.selectTone('academic');

		// Wait and verify
		await aiFeedback.waitForSettingsSave();
		await admin.createNewPost();
		await aiFeedback.openSidebar();
		await aiFeedback.expandReviewSettings();

		await expect(page.getByLabel('Target Tone')).toHaveValue('academic');
	});

	test('handles concurrent settings updates', async ({
		page,
		aiFeedback,
		admin,
	}) => {
		await aiFeedback.openSidebar();
		await aiFeedback.expandReviewSettings();

		// Get initial state of focus areas
		const contentQuality = page.getByLabel('Content Quality', {
			exact: true,
		});
		const toneVoice = page.getByLabel('Tone & Voice', { exact: true });
		const flowStructure = page.getByLabel('Flow & Structure', {
			exact: true,
		});

		const initialContentState = await contentQuality.isChecked();
		const initialToneState = await toneVoice.isChecked();
		const initialFlowState = await flowStructure.isChecked();

		// Rapid changes (should debounce)
		await contentQuality.click();
		await toneVoice.click();
		await flowStructure.click();

		// Wait for debounced save
		await aiFeedback.waitForSettingsSave();

		// All changes should be saved
		await admin.createNewPost();
		await aiFeedback.openSidebar();
		await aiFeedback.expandReviewSettings();

		// Verify all toggles persisted correctly (opposite of initial state)
		if (initialContentState) {
			await expect(
				page.getByLabel('Content Quality', { exact: true })
			).not.toBeChecked();
		} else {
			await expect(
				page.getByLabel('Content Quality', { exact: true })
			).toBeChecked();
		}

		if (initialToneState) {
			await expect(
				page.getByLabel('Tone & Voice', { exact: true })
			).not.toBeChecked();
		} else {
			await expect(
				page.getByLabel('Tone & Voice', { exact: true })
			).toBeChecked();
		}

		if (initialFlowState) {
			await expect(
				page.getByLabel('Flow & Structure', { exact: true })
			).not.toBeChecked();
		} else {
			await expect(
				page.getByLabel('Flow & Structure', { exact: true })
			).toBeChecked();
		}
	});

	test('settings load on sidebar open', async ({ page, aiFeedback }) => {
		await aiFeedback.openSidebar();

		// Verify settings UI is present (settings should be loaded)
		await expect(page.getByLabel('AI Model')).toBeVisible();

		// Expand settings panel
		await aiFeedback.expandReviewSettings();

		// Verify settings content is present
		await expect(
			page.getByLabel('Content Quality', { exact: true })
		).toBeVisible();
		await expect(page.getByLabel('Target Tone')).toBeVisible();
	});

	test('preserves multiple settings changes in single session', async ({
		admin,
		page,
		aiFeedback,
	}) => {
		await aiFeedback.openSidebar();
		await aiFeedback.expandReviewSettings();

		// Make multiple changes
		await aiFeedback.selectModel('claude-opus-4');
		await aiFeedback.toggleFocusArea('Content Quality', false);
		await aiFeedback.selectTone('friendly');

		// Wait for all changes to save
		await aiFeedback.waitForSettingsSave();

		// Navigate to new post
		await admin.createNewPost();
		await aiFeedback.openSidebar();
		await aiFeedback.expandReviewSettings();

		// Verify all settings persisted
		await expect(page.getByLabel('AI Model')).toHaveValue('claude-opus-4');
		await expect(
			page.getByLabel('Content Quality', { exact: true })
		).not.toBeChecked();
		await expect(page.getByLabel('Target Tone')).toHaveValue('friendly');
	});
});
