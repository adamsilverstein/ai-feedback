/**
 * E2E tests for progressive tips.
 */
const { test, expect } = require('../fixtures');

const MOCK_REVIEW = {
	review_id: 'tips-review-id',
	post_id: 1,
	model: 'gpt-4o',
	notes: [],
	note_ids: [],
	block_mapping: {},
	summary: { by_severity: {}, by_category: {} },
	summary_text: 'Looks good.',
	note_count: 0,
	timestamp: new Date().toISOString(),
};

/**
 * Reset tip preferences for the current user.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 */
async function resetTips(page) {
	await page.evaluate(() => {
		const { set } = window.wp.data.dispatch('core/preferences');
		set('ai-feedback', 'reviewCount', 0);
		set('ai-feedback', 'dismissedTips', []);
	});
}

test.describe('Progressive tips', () => {
	test('shows a tip after the first review and remembers dismissal', async ({
		page,
		editor,
		aiFeedback,
	}) => {
		await aiFeedback.createAndSavePost(
			{ title: 'Tips Post', content: 'Some content to review.' },
			{ editor }
		);
		await aiFeedback.openSidebar();
		await resetTips(page);

		const tip = page.locator('.ai-feedback-panel .ai-feedback-tip');
		await expect(tip).toHaveCount(0);

		await page.route(/\/ai-feedback\/v1\/review/, async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(MOCK_REVIEW),
			});
		});
		await aiFeedback.startReviewAndWait();

		await expect(tip).toBeVisible();
		await expect(tip).toContainText(/saved as a Note on its block/i);

		await tip.getByRole('button', { name: /close/i }).click();
		await expect(tip).toHaveCount(0);

		const dismissed = await page.evaluate(() =>
			window.wp.data
				.select('core/preferences')
				.get('ai-feedback', 'dismissedTips')
		);
		expect(dismissed).toContain('notes-on-blocks');
	});
});
