/**
 * E2E tests that a review runs on the selected provider and model, or fails,
 * and never moves to another one.
 *
 * Reviews go through the real core AI client to two fake providers from
 * tests/mu-plugins/ai-feedback-e2e-providers.php: "anthropic", which the
 * default model belongs to, and "e2eother", which is registered first and
 * also lists the default model.
 */
const { test, expect } = require('../fixtures');

const DEFAULT_MODEL = 'claude-sonnet-5-5';

/**
 * Send reviews to the fake providers instead of the mock response.
 *
 * @param {import('@playwright/test').BrowserContext} context Browser context.
 * @param {string}                                    baseURL Site URL.
 * @param {'missing'|'listed'}                        listing What "anthropic" lists.
 */
async function useFakeProviders(context, baseURL, listing) {
	await context.addCookies([
		{ name: 'ai_feedback_e2e_providers', value: listing, url: baseURL },
	]);
}

test.describe('Model pinning', () => {
	test.beforeEach(async ({ requestUtils }) => {
		// Settings persist across specs, so start from the default model.
		await requestUtils.rest({
			path: '/ai-feedback/v1/settings',
			method: 'POST',
			data: { default_model: DEFAULT_MODEL },
		});
	});

	test('fails with the model and provider named when the provider does not list the model', async ({
		context,
		baseURL,
		editor,
		page,
		aiFeedback,
	}) => {
		await useFakeProviders(context, baseURL, 'missing');
		await aiFeedback.createAndSavePost(
			{ title: 'Model pinning', content: 'Content to review.' },
			{ editor }
		);
		await aiFeedback.openSidebar();

		const response = await aiFeedback.runReview();
		expect(response.request().postDataJSON().model).toBe(DEFAULT_MODEL);
		expect(response.status()).toBe(400);

		const notice = page.locator('.ai-feedback-error-notice');
		await expect(notice).toContainText(
			'Claude Sonnet 5.5 (claude-sonnet-5-5) is not available from E2E Anthropic, so Fresh Eyes did not send the request.'
		);
		await expect(notice).toContainText(
			'Error code: ai_feedback_model_unavailable'
		);
		await expect(page.locator('.ai-feedback-review-summary')).toHaveCount(
			0
		);
	});

	test('runs on the selected provider and model and records it', async ({
		context,
		baseURL,
		editor,
		aiFeedback,
	}) => {
		await useFakeProviders(context, baseURL, 'listed');
		await aiFeedback.createAndSavePost(
			{ title: 'Model pinning', content: 'Content to review.' },
			{ editor }
		);
		await aiFeedback.openSidebar();

		const response = await aiFeedback.runReview();
		expect(response.status()).toBe(200);

		const body = await response.json();
		expect(body.model).toBe(DEFAULT_MODEL);
		expect(body.answered_by).toEqual({
			provider: 'anthropic',
			model: DEFAULT_MODEL,
		});
		expect(body.summary_text).toBe(
			`Answered by anthropic/${DEFAULT_MODEL}.`
		);
	});
});
