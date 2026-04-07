/**
 * E2E tests for Skip Links accessibility feature.
 */
const { test, expect } = require('../fixtures');

test.describe('Skip Links', () => {
	test.beforeEach(async ({ admin, page, editor, aiFeedback }) => {
		await admin.createNewPost();

		// Add content and save
		await editor.canvas
			.getByRole('textbox', { name: 'Add title' })
			.fill('Test Post');
		await editor.canvas
			.getByRole('textbox', { name: 'Type / to choose a block' })
			.fill('This is test content for the post.');

		await page.getByRole('button', { name: 'Save draft' }).click();
		await page.waitForSelector('button:has-text("Saved")', {
			timeout: 10000,
		});

		await aiFeedback.openSidebar();
	});

	test('skip links are hidden by default', async ({ page }) => {
		// Skip links should not be visible when not focused
		const skipLinks = page.locator('.ai-feedback-skip-links .skip-link');
		const count = await skipLinks.count();
		expect(count).toBeGreaterThan(0);

		// None of them should be visible (they are visually hidden via CSS clip)
		for (let i = 0; i < count; i++) {
			await expect(skipLinks.nth(i)).not.toBeVisible();
		}
	});

	test('skip link appears when focused via Tab key', async ({ page }) => {
		// Focus the sidebar region first
		const sidebar = page.getByRole('region', { name: 'AI Feedback' });
		await sidebar.focus();

		// Tab to the first focusable element (skip link)
		await page.keyboard.press('Tab');

		// The skip link should now be visible
		const focusedElement = page.locator('.skip-link:focus');
		await expect(focusedElement).toBeVisible();
	});

	test('skip to review button link is always present', async ({ page }) => {
		const reviewButtonLink = page.locator(
			'a.skip-link[href="#ai-feedback-review-button"]'
		);
		await expect(reviewButtonLink).toBeAttached();
		await expect(reviewButtonLink).toHaveText('Skip to review button');
	});

	test('skip to review results link appears when results exist', async ({
		page,
		aiFeedback,
	}) => {
		// Mock and run a review so results exist
		await aiFeedback.mockReviewAPI({
			review_id: 1,
			post_id: 1,
			model: 'test-model',
			note_count: 2,
			notes: [],
			summary_text: 'Test review summary',
			block_mapping: {},
		});
		await aiFeedback.startReviewAndWait();

		const resultsLink = page.locator(
			'a.skip-link[href="#ai-feedback-results"]'
		);
		await expect(resultsLink).toBeAttached();
		await expect(resultsLink).toHaveText('Skip to review results');
	});

	test('clicking skip link moves focus to target element', async ({
		page,
	}) => {
		// Focus and activate the skip link to review button
		const skipLink = page.locator(
			'a.skip-link[href="#ai-feedback-review-button"]'
		);
		await skipLink.focus();
		await skipLink.click();

		// The target element should now be focused
		const target = page.locator('#ai-feedback-review-button');
		await expect(target).toBeFocused();
	});

	test('skip link target receives visible focus indicator', async ({
		page,
		aiFeedback,
	}) => {
		// Mock and run a review so results target exists
		await aiFeedback.mockReviewAPI({
			review_id: 1,
			post_id: 1,
			model: 'test-model',
			note_count: 2,
			notes: [],
			summary_text: 'Test review summary',
			block_mapping: {},
		});
		await aiFeedback.startReviewAndWait();

		// Focus and activate the skip link to review results
		const skipLink = page.locator(
			'a.skip-link[href="#ai-feedback-results"]'
		);
		await skipLink.focus();
		await skipLink.click();

		// The target element should have focus styles applied
		const target = page.locator('#ai-feedback-results');
		await expect(target).toBeFocused();

		// Check that the element has an outline (focus indicator)
		const outlineStyle = await target.evaluate((el) =>
			window.getComputedStyle(el).getPropertyValue('outline-style')
		);
		expect(outlineStyle).not.toBe('none');
	});

	test('keyboard Enter key activates skip link', async ({ page }) => {
		// Focus the sidebar region first
		const sidebar = page.getByRole('region', { name: 'AI Feedback' });
		await sidebar.focus();

		// Tab to the first skip link
		await page.keyboard.press('Tab');

		// Verify we're on a skip link
		const focusedElement = page.locator(':focus');
		await expect(focusedElement).toHaveClass(/skip-link/);

		// Press Enter to activate
		await page.keyboard.press('Enter');

		// Focus should have moved to the target
		const reviewButton = page.locator('#ai-feedback-review-button');
		await expect(reviewButton).toBeFocused();
	});

	test('skip links have proper accessibility attributes', async ({
		page,
	}) => {
		// Check that the nav has proper aria-label
		const nav = page.locator('.ai-feedback-skip-links');
		await expect(nav).toHaveAttribute('aria-label', 'Skip links');

		// Check that links have href attributes pointing to valid targets
		const links = page.locator('.ai-feedback-skip-links .skip-link');
		const count = await links.count();

		for (let i = 0; i < count; i++) {
			const href = await links.nth(i).getAttribute('href');
			expect(href).toMatch(/^#ai-feedback-/);

			// Verify the target exists
			const targetId = href.slice(1);
			const target = page.locator(`#${targetId}`);
			await expect(target).toBeAttached();
		}
	});
});
