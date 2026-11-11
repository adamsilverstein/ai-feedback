/**
 * E2E tests for review error handling.
 */
const { test, expect } = require('../fixtures');

test.describe('Error Handling', () => {
	test.beforeEach(async ({ editor, aiFeedback }) => {
		await aiFeedback.createAndSavePost(
			{ title: 'Error Handling', content: 'Content to review.' },
			{ editor }
		);
		await aiFeedback.openSidebar();
	});

	test('shows the message and code for a server error', async ({
		page,
		aiFeedback,
	}) => {
		await aiFeedback.mockReviewAPIError(
			500,
			'ai_request_failed',
			'The AI provider is currently unavailable.'
		);
		await aiFeedback.runReview();

		const notice = page.locator('.ai-feedback-error-notice');
		await expect(notice).toHaveClass(/is-error/);
		await expect(notice).toContainText(
			'The AI provider is currently unavailable.'
		);
		await expect(notice).toContainText('Error code: ai_request_failed');
		// Unknown errors get no follow-up action.
		await expect(notice.getByRole('link')).toHaveCount(0);
		await expect(notice.locator('.ai-feedback-error-help')).toHaveCount(0);
	});

	test('asks the user to wait after a rate limit error', async ({
		page,
		aiFeedback,
	}) => {
		await aiFeedback.mockReviewAPIError(
			429,
			'rate_limit_exceeded',
			'You have reached the maximum number of reviews per hour (10). Please try again later.'
		);
		await aiFeedback.runReview();

		const notice = page.locator('.ai-feedback-error-notice');
		await expect(notice).toContainText(
			'maximum number of reviews per hour'
		);
		await expect(notice).toContainText('Error code: rate_limit_exceeded');
		await expect(notice).toContainText(
			'Please wait before making another request.'
		);
	});

	for (const { status, message } of [
		{ status: 402, message: 'Insufficient credit balance' },
		{ status: 401, message: 'Invalid API key provided' },
	]) {
		test(`links to Connectors settings for a ${status} provider error`, async ({
			page,
			aiFeedback,
		}) => {
			// Core AI Client errors carry the provider status in data.status.
			await aiFeedback.mockReviewAPIError(
				500,
				'prompt_client_error',
				message,
				{ status }
			);
			await aiFeedback.runReview();

			const notice = page.locator('.ai-feedback-error-notice');
			await expect(notice).toContainText(message);

			const link = notice.getByRole('link', {
				name: 'Open Connectors Settings',
			});
			await expect(link).toBeVisible();
			await expect(link).toHaveAttribute(
				'href',
				/options-general\.php\?page=connectors$/
			);
			await expect(link).toHaveAttribute('target', '_blank');
		});
	}

	test('dismisses the error notice', async ({ page, aiFeedback }) => {
		await aiFeedback.mockReviewAPIError(
			500,
			'server_error',
			'Something went wrong'
		);
		await aiFeedback.runReview();

		const notice = page.locator('.ai-feedback-error-notice');
		await expect(notice).toBeVisible();

		await notice.getByRole('button', { name: 'Close' }).click();
		await expect(notice).toBeHidden();
		await expect(aiFeedback.getReviewButton()).toBeEnabled();
	});

	for (const errorCode of ['failed', 'timedout']) {
		test(`recovers from a network error (${errorCode})`, async ({
			page,
			aiFeedback,
		}) => {
			await aiFeedback.routeREST('/review', (route) =>
				route.abort(errorCode)
			);
			const response = await aiFeedback.runReview();
			expect(response).toBeNull();

			const notice = page.locator('.ai-feedback-error-notice');
			await expect(notice).toBeVisible();
			await expect(
				notice.locator('.ai-feedback-error-message')
			).not.toBeEmpty();
			await expect(aiFeedback.getReviewButton()).toBeEnabled();
		});
	}

	test('can retry after an error', async ({ page, aiFeedback }) => {
		await aiFeedback.mockReviewAPIError(
			503,
			'ai_request_failed',
			'Temporary failure'
		);
		await aiFeedback.runReview();

		const notice = page.locator('.ai-feedback-error-notice');
		await expect(notice).toContainText('Temporary failure');

		// Let the retry reach the mock AI backend.
		await aiFeedback.unrouteREST('/review');
		const response = await aiFeedback.runReview();
		expect(response.ok()).toBe(true);

		await expect(notice).toBeHidden();
		await expect(page.locator('.ai-feedback-review-summary')).toContainText(
			'1 feedback item'
		);
	});

	test('keeps the previous review when a later review fails', async ({
		page,
		aiFeedback,
	}) => {
		await aiFeedback.runReview();
		const summary = page.locator('.ai-feedback-review-summary');
		await expect(summary).toContainText('1 feedback item');

		await aiFeedback.mockReviewAPIError(
			500,
			'ai_request_failed',
			'Second review failed'
		);
		await aiFeedback.runReview();

		await expect(page.locator('.ai-feedback-error-notice')).toContainText(
			'Second review failed'
		);
		await expect(summary).toContainText('1 feedback item');
	});

	test('retries automatically when the AI service is unavailable', async ({
		page,
		editor,
		aiFeedback,
	}) => {
		await aiFeedback.createAndSavePost(
			{ title: 'Outage Test', content: 'Content for outage test.' },
			{ editor }
		);

		await aiFeedback.openSidebar();

		let calls = 0;
		// Match both pretty permalinks and the ?rest_route= form, plus any
		// query args apiFetch adds.
		const isReviewRequest = (url) =>
			/\/ai-feedback\/v1\/review(?:$|[?&])/.test(
				decodeURIComponent(url.toString())
			);
		await page.route(isReviewRequest, async (route) => {
			calls++;
			if (calls === 1) {
				await route.fulfill({
					status: 503,
					contentType: 'application/json',
					body: JSON.stringify({
						code: 'ai_service_unavailable',
						message:
							'The AI service is temporarily unavailable. Your content was not changed. Please try again shortly.',
						data: { status: 503, retry_after: 3 },
					}),
				});
				return;
			}
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					review_id: 'retry-review-id',
					notes: [],
					note_ids: [],
					block_mapping: {},
					summary: {
						total_notes: 0,
						by_category: {},
						by_severity: {},
					},
					summary_text: 'Recovered after retry.',
					note_count: 0,
					timestamp: new Date().toISOString(),
				}),
			});
		});

		// Click directly: waiting for the button to settle could outlast the
		// retry delay and miss the notice.
		await page
			.locator('.ai-feedback-panel button.is-primary')
			.filter({ hasText: /Review( Document)?/i })
			.first()
			.click();

		const panel = page.locator('.ai-feedback-panel');
		await expect(
			panel.getByText('Retrying automatically in 3 seconds.')
		).toBeVisible();
		await expect(
			panel.getByRole('button', { name: 'Retry now' })
		).toBeVisible();

		await expect(panel.getByText('Recovered after retry.')).toBeVisible({
			timeout: 10000,
		});
		expect(calls).toBe(2);
	});
});
