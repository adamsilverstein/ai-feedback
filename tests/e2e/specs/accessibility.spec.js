/**
 * E2E accessibility tests.
 */
const { test, expect } = require('../fixtures');
const AxeBuilder = require('@axe-core/playwright').default;

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

/**
 * Run axe against part of the page, limited to WCAG A and AA rules.
 *
 * @param {import('@playwright/test').Page} page     Page.
 * @param {string}                          selector Region to scan.
 * @return {Promise<Array>} Violations, reduced to rule ID and offending targets.
 */
async function scan(page, selector) {
	const results = await new AxeBuilder({ page })
		.include(selector)
		.withTags(WCAG_TAGS)
		.analyze();
	return results.violations.map((violation) => ({
		id: violation.id,
		targets: violation.nodes.map((node) => node.target.join(' ')),
	}));
}

test.describe('Accessibility', () => {
	test.describe('axe scans', () => {
		test('empty state has no violations', async ({
			admin,
			page,
			aiFeedback,
		}) => {
			await admin.createNewPost();
			await aiFeedback.openSidebar();
			await expect(page.getByText('No reviews yet')).toBeVisible();

			expect(await scan(page, '.ai-feedback-panel')).toEqual([]);
		});

		test('settings and review results have no violations', async ({
			page,
			editor,
			admin,
			aiFeedback,
		}) => {
			await admin.createNewPost({ title: 'Axe Results' });
			await editor.insertBlock({
				name: 'core/paragraph',
				attributes: { content: 'First paragraph.' },
			});
			await editor.insertBlock({
				name: 'core/paragraph',
				attributes: { content: 'Second paragraph.' },
			});
			await editor.saveDraft();
			await aiFeedback.openSidebar();
			await aiFeedback.runReview();
			await expect(
				page.locator('.ai-feedback-review-summary')
			).toBeVisible();

			expect(await scan(page, '.ai-feedback-panel')).toEqual([]);
		});

		test('error notice has no violations', async ({
			page,
			editor,
			aiFeedback,
		}) => {
			await aiFeedback.createAndSavePost(
				{ title: 'Axe Error', content: 'Content.' },
				{ editor }
			);
			await aiFeedback.openSidebar();
			await aiFeedback.mockReviewAPIError(
				402,
				'prompt_client_error',
				'Insufficient credit balance'
			);
			await aiFeedback.runReview();
			await expect(
				page.locator('.ai-feedback-error-notice')
			).toBeVisible();

			expect(await scan(page, '.ai-feedback-panel')).toEqual([]);
		});

		test('setup modal has no violations', async ({
			admin,
			page,
			aiFeedback,
		}) => {
			// Undo the fixture's "welcomed" flag so the modal opens.
			await page.addInitScript(() => {
				try {
					window.localStorage.removeItem('ai-feedback-welcomed');
				} catch {}
			});
			await admin.createNewPost();
			await aiFeedback.openSidebar({ dismissWelcomeModal: false });

			const modal = page.getByRole('dialog', {
				name: /Welcome to AI Feedback|AI Feedback Setup Required/,
			});
			await expect(modal).toBeVisible();

			expect(await scan(page, '.ai-feedback-welcome-modal')).toEqual([]);

			await page.keyboard.press('Escape');
			await expect(modal).toBeHidden();
		});
	});

	test.describe('keyboard', () => {
		test.beforeEach(async ({ editor, aiFeedback }) => {
			await aiFeedback.createAndSavePost(
				{ title: 'Keyboard', content: 'Content to review.' },
				{ editor }
			);
			await aiFeedback.openSidebar();
			await aiFeedback.runReview();
		});

		test('moves through the panel in a logical tab order', async ({
			page,
		}) => {
			const panel = page.locator('.ai-feedback-panel');
			const settingsToggle = panel
				.locator('.components-panel__body-toggle')
				.filter({ hasText: 'Review Settings' });
			const reviewToggle = panel
				.locator('.components-panel__body-toggle')
				.filter({ hasText: 'Review Document' });

			// Tone, focus area and help controls sit between these, so tab
			// forward until each target is reached, in order.
			await settingsToggle.focus();
			for (const target of [
				panel.getByLabel('AI Model'),
				panel.getByLabel('Feedback Language'),
				reviewToggle,
				panel.locator('#ai-feedback-review-button button'),
			]) {
				let presses = 0;
				do {
					await page.keyboard.press('Tab');
					presses++;
				} while (
					!(await target.evaluate(
						(element) =>
							element === element.ownerDocument.activeElement
					)) &&
					presses < 20
				);
				await expect(target).toBeFocused();
			}
		});

		test('toggles panels with Enter and Space', async ({ page }) => {
			const toggle = page
				.locator('.ai-feedback-panel .components-panel__body-toggle')
				.filter({ hasText: 'Review Settings' });
			await toggle.focus();

			await page.keyboard.press('Enter');
			await expect(toggle).toHaveAttribute('aria-expanded', 'false');
			await expect(page.getByLabel('AI Model')).toBeHidden();

			await page.keyboard.press('Space');
			await expect(toggle).toHaveAttribute('aria-expanded', 'true');
			await expect(page.getByLabel('AI Model')).toBeVisible();
		});

		test('starts a review with the keyboard', async ({
			page,
			aiFeedback,
		}) => {
			const reviewRequest = page.waitForRequest(
				(request) =>
					request.method() === 'POST' &&
					decodeURIComponent(request.url()).includes(
						'/ai-feedback/v1/review'
					)
			);
			await aiFeedback.getReviewButton().focus();
			await page.keyboard.press('Enter');
			await reviewRequest;
			await expect(aiFeedback.getReviewButton()).not.toHaveClass(
				/is-busy/
			);
		});

		test('skip links move focus to their targets', async ({ page }) => {
			const panel = page.locator('.ai-feedback-panel');
			const skipLinks = panel.getByRole('navigation', {
				name: 'Skip links',
			});

			const results = skipLinks.getByRole('link', {
				name: 'Skip to review results',
			});
			await results.focus();
			await expect(results).toBeFocused();
			await page.keyboard.press('Enter');
			await expect(panel.locator('#ai-feedback-results')).toBeFocused();

			const items = skipLinks.getByRole('link', {
				name: 'Skip to feedback items',
			});
			await items.focus();
			await page.keyboard.press('Enter');
			await expect(panel.locator('#ai-feedback-items')).toBeFocused();
		});

		test('form controls show a visible focus indicator', async ({
			page,
		}) => {
			const panel = page.locator('.ai-feedback-panel');
			await panel
				.locator('.components-panel__body-toggle')
				.filter({ hasText: 'Review Settings' })
				.focus();
			// Reach the select with Tab so :focus-visible applies.
			await page.keyboard.press('Tab');

			const modelSelect = panel.getByLabel('AI Model');
			await expect(modelSelect).toBeFocused();

			const hasIndicator = await modelSelect.evaluate((element) => {
				// SelectControl draws its focus ring on a sibling backdrop.
				const candidates = [
					element,
					...element.parentElement.querySelectorAll('*'),
				];
				return candidates.some((candidate) => {
					const style = window.getComputedStyle(candidate);
					const outline =
						style.outlineStyle !== 'none' &&
						parseFloat(style.outlineWidth) > 0;
					return outline || style.boxShadow !== 'none';
				});
			});
			expect(hasIndicator).toBe(true);
		});
	});

	test.describe('screen reader feedback', () => {
		test.beforeEach(async ({ editor, aiFeedback }) => {
			await aiFeedback.createAndSavePost(
				{ title: 'Announcements', content: 'Content to review.' },
				{ editor }
			);
			await aiFeedback.openSidebar();
		});

		test('announces review progress and completion', async ({
			page,
			aiFeedback,
		}) => {
			let releaseReview;
			const reviewHeld = new Promise((resolve) => {
				releaseReview = resolve;
			});
			await aiFeedback.routeREST('/review', async (route) => {
				await reviewHeld;
				await route.continue();
			});

			const liveRegion = page.locator('#a11y-speak-polite');
			await aiFeedback.getReviewButton().click();
			await expect(liveRegion).toContainText(
				'Review in progress. Please wait.'
			);

			const progress = page.getByRole('progressbar', {
				name: 'Review progress',
			});
			await expect(progress).toHaveAttribute(
				'aria-valuetext',
				'Step 2 of 3: AI is analyzing your content…'
			);

			releaseReview();
			await expect(liveRegion).toContainText(
				'Review complete. 1 notes created.'
			);
			await expect(
				page.locator('.ai-feedback-review-summary').getByRole('status')
			).toHaveText('1 feedback item');
		});

		test('announces errors', async ({ page, aiFeedback }) => {
			// The panel and the Notice both speak; whichever runs last wins
			// the live region, and both include the error message.
			await aiFeedback.mockReviewAPIError(
				500,
				'ai_request_failed',
				'Provider unavailable'
			);
			await aiFeedback.runReview();

			await expect(
				page.locator('.ai-feedback-error-notice')
			).toBeVisible();
			await expect
				.poll(async () =>
					(
						await page
							.locator(
								'#a11y-speak-polite, #a11y-speak-assertive'
							)
							.allTextContents()
					).join(' ')
				)
				.toContain('Provider unavailable');
		});
	});
});
