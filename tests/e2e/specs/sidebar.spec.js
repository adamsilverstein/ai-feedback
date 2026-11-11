/**
 * E2E tests for the AI Feedback sidebar.
 */
const { test, expect } = require('../fixtures');

const DEFAULT_SETTINGS = {
	default_model: 'claude-sonnet-5-5',
	feedback_locale: 'auto',
};

test.describe('AI Feedback Sidebar', () => {
	test.afterEach(async ({ requestUtils }) => {
		// Settings are stored as options, so reset them between tests.
		await requestUtils.rest({
			path: '/ai-feedback/v1/settings',
			method: 'POST',
			data: DEFAULT_SETTINGS,
		});
	});

	test('opens, toggles, and closes from the editor UI', async ({
		admin,
		page,
		aiFeedback,
	}) => {
		await admin.createNewPost();
		await aiFeedback.openSidebar();

		const toolbarButton = page
			.getByRole('region', { name: 'Editor top bar' })
			.getByRole('button', { name: 'AI Feedback' });
		await expect(toolbarButton).toHaveAttribute('aria-pressed', 'true');

		await toolbarButton.click();
		await expect(page.locator('.ai-feedback-panel')).toBeHidden();
		await expect(toolbarButton).toHaveAttribute('aria-pressed', 'false');

		await toolbarButton.click();
		await expect(page.locator('.ai-feedback-panel')).toBeVisible();
		await expect(toolbarButton).toHaveAttribute('aria-pressed', 'true');

		await page
			.getByRole('region', { name: 'Editor settings' })
			.getByRole('button', { name: 'Close plugin' })
			.click();
		await expect(page.locator('.ai-feedback-panel')).toBeHidden();
	});

	test('shows the empty state with a content prerequisite for a new post', async ({
		admin,
		page,
		aiFeedback,
	}) => {
		await admin.createNewPost();
		await aiFeedback.openSidebar();

		const panel = page.locator('.ai-feedback-panel');
		await expect(panel.getByText('No reviews yet')).toBeVisible();
		await expect(panel.getByText('Before you start:')).toBeVisible();
		await expect(
			panel.getByText('Add some content to your post')
		).toBeVisible();
		await expect(
			panel.getByRole('button', { name: 'Review Document' })
		).toHaveCount(0);
	});

	test('enables the review button once the post has saved content', async ({
		page,
		editor,
		aiFeedback,
	}) => {
		await aiFeedback.createAndSavePost(
			{ title: 'Sidebar Test', content: 'Some content to review.' },
			{ editor }
		);
		await aiFeedback.openSidebar();

		const panel = page.locator('.ai-feedback-panel');
		await expect(panel.getByText('Before you start:')).toBeHidden();
		await expect(aiFeedback.getReviewButton()).toBeEnabled();
	});

	test.describe('after a review', () => {
		test.beforeEach(async ({ editor, aiFeedback }) => {
			await aiFeedback.createAndSavePost(
				{ title: 'Sidebar Panels', content: 'Content to review.' },
				{ editor }
			);
			await aiFeedback.openSidebar();
			await aiFeedback.runReview();
		});

		test('shows the settings, review, and last review panels', async ({
			page,
		}) => {
			const panel = page.locator('.ai-feedback-panel');

			for (const title of [
				'Review Settings',
				'Review Document',
				'Last Review',
			]) {
				await expect(
					panel
						.locator('.components-panel__body-toggle')
						.filter({ hasText: title })
				).toHaveAttribute('aria-expanded', 'true');
			}

			await expect(panel.getByLabel('AI Model')).toBeVisible();
			await expect(panel.getByLabel('Feedback Language')).toBeVisible();
			await expect(page.getByText('No reviews yet')).toBeHidden();
		});

		test('lists every available model grouped by provider', async ({
			page,
		}) => {
			const modelSelect = page.getByLabel('AI Model');
			const optionLabels = await modelSelect
				.locator('option')
				.allTextContents();

			expect(optionLabels).toEqual([
				'Anthropic - Claude Sonnet 5.5',
				'Anthropic - Claude Opus 4',
				'Openai - GPT-4o',
				'Google - Gemini 2.0 Flash',
			]);
			await expect(modelSelect).toHaveValue('claude-sonnet-5-5');
		});

		test('saves the selected model and language', async ({
			page,
			aiFeedback,
		}) => {
			await aiFeedback.saveSetting(() =>
				page.getByLabel('AI Model').selectOption('gpt-4o')
			);
			await aiFeedback.saveSetting(() =>
				page.getByLabel('Feedback Language').selectOption({ index: 1 })
			);
			const locale = await page
				.getByLabel('Feedback Language')
				.inputValue();

			await page.reload();
			await aiFeedback.openSidebar();

			await expect(page.getByLabel('AI Model')).toHaveValue('gpt-4o');
			await expect(page.getByLabel('Feedback Language')).toHaveValue(
				locale
			);
		});

		test('collapses and expands the Review Settings panel', async ({
			page,
		}) => {
			const toggle = page
				.locator('.ai-feedback-panel')
				.getByRole('button', { name: 'Review Settings' });

			await toggle.click();
			await expect(toggle).toHaveAttribute('aria-expanded', 'false');
			await expect(page.getByLabel('AI Model')).toBeHidden();

			await toggle.click();
			await expect(toggle).toHaveAttribute('aria-expanded', 'true');
			await expect(page.getByLabel('AI Model')).toBeVisible();
		});

		test('restores the last review after a reload', async ({
			page,
			aiFeedback,
		}) => {
			await page.reload();
			await aiFeedback.openSidebar();

			await expect(
				page
					.locator('.ai-feedback-panel')
					.getByRole('button', { name: 'Last Review' })
			).toBeVisible();
			await expect(
				page.locator('.ai-feedback-review-summary')
			).toBeVisible();
		});
	});
});
