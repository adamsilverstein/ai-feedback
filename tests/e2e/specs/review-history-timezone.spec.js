/**
 * E2E tests for Review History dates when the browser and site timezones differ.
 */
const { test, expect } = require('../fixtures');

/**
 * Update site settings from the editor page, which carries the e2e cookie.
 *
 * @param {import('@playwright/test').Page} page     Editor page.
 * @param {Object}                          settings Settings to save.
 */
async function updateSettings(page, settings) {
	await page.evaluate(
		(data) =>
			window.wp.apiFetch({
				path: '/wp/v2/settings',
				method: 'POST',
				data,
			}),
		settings
	);
}

/**
 * Format a moment in the site timezone with the site datetime format.
 *
 * @param {import('@playwright/test').Page} page Editor page.
 * @param {number}                          time Milliseconds since the epoch.
 * @return {Promise<string>} Formatted date.
 */
function formatSiteTime(page, time) {
	return page.evaluate((ms) => {
		const { dateI18n, getSettings } = window.wp.date;
		return dateI18n(getSettings().formats.datetime, ms);
	}, time);
}

test.describe('Review History timezone', () => {
	// The browser runs in US Pacific time, the site at UTC+2.
	test.use({ timezoneId: 'America/Los_Angeles' });

	test.afterEach(async ({ page }) => {
		await updateSettings(page, { timezone: '', gmt_offset: 0 });
	});

	test('shows the time the review ran in the site timezone', async ({
		page,
		admin,
		editor,
		aiFeedback,
	}) => {
		await admin.createNewPost();
		await updateSettings(page, { timezone: '', gmt_offset: 2 });

		await aiFeedback.createAndSavePost(
			{ title: 'Timezone', content: 'Content for the AI review.' },
			{ editor }
		);
		const timezone = await page.evaluate(
			() => window.wp.date.getSettings().timezone
		);
		expect(timezone.string).toBe('');
		expect(Number(timezone.offset)).toBe(2);

		await aiFeedback.openSidebar();
		const before = Date.now();
		const response = await aiFeedback.runReview();
		expect(response?.ok()).toBe(true);
		const after = Date.now();

		const expected = [
			await formatSiteTime(page, before),
			await formatSiteTime(page, after),
		];

		const checkHistoryDate = async () => {
			const panel = page.locator('.ai-feedback-panel');
			await panel.getByRole('button', { name: 'Review History' }).click();
			const date = panel.locator('.ai-feedback-history-date').first();
			await expect(date).toBeVisible();
			expect(expected).toContain(await date.textContent());
		};

		await checkHistoryDate();

		// History loaded from the server after a reload shows the same time.
		await page.reload();
		await aiFeedback.openSidebar();
		await checkHistoryDate();
	});
});
