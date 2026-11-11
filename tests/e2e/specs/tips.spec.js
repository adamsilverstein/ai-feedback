/**
 * E2E tests for progressive tips.
 */
const { test, expect } = require('../fixtures');

/**
 * Set the plugin's tip preferences for the current user.
 *
 * @param {import('@playwright/test').Page} page   Playwright page.
 * @param {Object}                          values Preference values by key.
 */
async function setTipPreferences(page, values) {
	await page.evaluate((prefs) => {
		const { set } = window.wp.data.dispatch('core/preferences');
		Object.entries(prefs).forEach(([key, value]) =>
			set('ai-feedback', key, value)
		);
	}, values);
}

/**
 * Read one of the plugin's tip preferences.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @param {string}                          key  Preference key.
 * @return {Promise<unknown>} Preference value.
 */
async function getTipPreference(page, key) {
	return page.evaluate(
		(name) =>
			window.wp.data.select('core/preferences').get('ai-feedback', name),
		key
	);
}

test.describe('Progressive tips', () => {
	test.beforeEach(async ({ page, editor, aiFeedback }) => {
		await aiFeedback.createAndSavePost(
			{ title: 'Tips Post', content: 'Some content to review.' },
			{ editor }
		);
		await aiFeedback.openSidebar();
		await setTipPreferences(page, { reviewCount: 0, dismissedTips: [] });
		await aiFeedback.mockReviewAPI({ summary_text: 'Looks good.' });
	});

	test('shows a tip after the first review and remembers dismissal', async ({
		page,
		aiFeedback,
	}) => {
		const tip = page.locator('.ai-feedback-panel .ai-feedback-tip');
		await expect(tip).toHaveCount(0);

		await aiFeedback.startReviewAndWait();

		await expect(tip).toBeVisible();
		await expect(tip).toContainText(/saved as a Note on its block/i);
		expect(await getTipPreference(page, 'reviewCount')).toBe(1);

		await tip.getByRole('button', { name: /close/i }).click();
		await expect(tip).toHaveCount(0);
		expect(await getTipPreference(page, 'dismissedTips')).toEqual([
			'notes-on-blocks',
		]);

		// The next review only unlocks the next tip, not the dismissed one.
		await aiFeedback.startReviewAndWait();
		await expect(tip).toContainText(/reply to them or resolve them/i);
	});

	test('resets tips from the command palette', async ({
		page,
		pageUtils,
	}) => {
		await setTipPreferences(page, {
			reviewCount: 4,
			dismissedTips: ['notes-on-blocks'],
			welcomeDismissed: true,
		});

		await pageUtils.pressKeys('primary+k');
		const palette = page.getByRole('dialog', { name: 'Command palette' });
		await palette.getByRole('combobox').fill('Reset AI Feedback tips');
		await palette
			.getByRole('option', { name: 'Reset AI Feedback tips' })
			.click();

		await expect(
			page.getByTestId('snackbar').filter({
				hasText: 'AI Feedback tips and welcome screen reset.',
			})
		).toBeVisible();
		expect(await getTipPreference(page, 'reviewCount')).toBe(0);
		expect(await getTipPreference(page, 'dismissedTips')).toEqual([]);
		expect(await getTipPreference(page, 'welcomeDismissed')).toBe(false);
	});
});
