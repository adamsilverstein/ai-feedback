/**
 * Custom test fixtures for AI Feedback E2E tests.
 */
const { test: base, expect } = require('@playwright/test');
const {
	Admin,
	Editor,
	PageUtils,
	RequestUtils,
} = require('@wordpress/e2e-test-utils-playwright');

const REST_NAMESPACE = '/ai-feedback/v1';

const restRouteMatchers = new Map();

/**
 * Build a Playwright URL matcher for a plugin REST route.
 *
 * Works with both pretty permalinks (`/wp-json/...`) and the plain
 * `?rest_route=...` form wp-env uses by default.
 *
 * @param {string} route Route relative to the plugin namespace, e.g. '/review'.
 * @return {Function} URL predicate.
 */
function restRoute(route) {
	// Reuse one matcher per route so page.unroute() can find the handler.
	if (!restRouteMatchers.has(route)) {
		const expected = REST_NAMESPACE + route;
		restRouteMatchers.set(route, (url) => {
			const restRouteParam = url.searchParams.get('rest_route');
			if (restRouteParam !== null) {
				return restRouteParam === expected;
			}
			return url.pathname.endsWith('/wp-json' + expected);
		});
	}
	return restRouteMatchers.get(route);
}

/**
 * Build a review response shaped like the plugin's REST response.
 *
 * @param {Object} overrides Fields to override.
 * @return {Object} Review response.
 */
function buildReviewResponse(overrides = {}) {
	return {
		review_id: 'e2e-review',
		post_id: 1,
		model: 'claude-sonnet-4',
		notes: [],
		note_ids: [],
		block_mapping: {},
		summary: { by_severity: {}, by_category: {} },
		summary_text: '',
		note_count: 0,
		timestamp: new Date().toISOString(),
		...overrides,
	};
}

/**
 * AI Feedback custom utilities.
 */
class AIFeedbackUtils {
	constructor({ page, admin }) {
		this.page = page;
		this.admin = admin;
	}

	/**
	 * Open the AI Feedback sidebar.
	 *
	 * @param {Object}  options                     - Options object.
	 * @param {boolean} options.dismissWelcomeModal - Whether to dismiss the welcome modal if it appears. Default: true.
	 */
	async openSidebar({ dismissWelcomeModal = true } = {}) {
		// Specific region for the editor top bar where buttons and menu live
		const topBar = this.page.getByRole('region', {
			name: 'Editor top bar',
		});

		// The custom panel inside the sidebar
		const customPanel = this.page.locator('.ai-feedback-panel');

		// The sidebar is pinned to the top bar by default. Wait for the
		// toggle so a freshly loaded editor is not mistaken for a closed one.
		const toggle = topBar.getByRole('button', { name: 'AI Feedback' });
		await toggle.waitFor({ state: 'visible' });
		if ((await toggle.getAttribute('aria-pressed')) !== 'true') {
			await toggle.click();
		}

		// Wait for the custom panel to be visible
		await customPanel.waitFor({ state: 'visible', timeout: 10000 });

		// Check for "Loading..." state
		const loadingText = customPanel.getByText(/Loading/i);
		const isLoadingVisible = await loadingText
			.isVisible()
			.catch(() => false);
		if (isLoadingVisible) {
			await expect(loadingText).toBeHidden({ timeout: 15000 });
		}

		// Handle welcome modal behavior
		const welcomeModal = this.page.getByRole('dialog', {
			name: /Welcome to AI Feedback|AI Feedback Setup Required/,
		});

		// Wait for either the modal or sidebar content to be ready
		// The modal might take time to render after sidebar opens (it makes an API call)
		// Note: The review button might not exist when post is empty (empty state)
		try {
			await Promise.race([
				welcomeModal.waitFor({ state: 'visible', timeout: 5000 }),
				// Wait for content to be ready - either review button or empty state heading
				customPanel
					.locator('h4, button.is-primary')
					.first()
					.waitFor({ state: 'visible', timeout: 5000 }),
			]);
		} catch {
			// Neither appeared in time, continue anyway
		}

		// If dismissWelcomeModal is true, dismiss the modal if it's visible
		if (dismissWelcomeModal) {
			const isWelcomeModalVisible = await welcomeModal
				.isVisible()
				.catch(() => false);
			if (isWelcomeModalVisible) {
				// Try "Get Started" first (normal modal), then "Close" (setup required modal)
				const getStartedButton = welcomeModal.getByRole('button', {
					name: 'Get Started',
				});
				// The modal header also has a "Close" button; either one dismisses.
				const closeButton = welcomeModal
					.getByRole('button', { name: 'Close', exact: true })
					.last();

				const hasGetStarted = await getStartedButton
					.isVisible()
					.catch(() => false);
				if (hasGetStarted) {
					await getStartedButton.click();
				} else {
					await closeButton.click();
				}

				// Wait for modal to close
				await welcomeModal.waitFor({ state: 'hidden', timeout: 5000 });
			}
		}
	}

	/**
	 * Create a new post with content and save it as draft.
	 *
	 * @param {Object} options         - Options object.
	 * @param {string} options.title   - Post title.
	 * @param {string} options.content - Paragraph content to type.
	 * @param {Object} deps            - Dependencies.
	 * @param {Object} deps.editor     - Editor fixture.
	 */
	async createAndSavePost({ title, content }, { editor }) {
		await this.admin.createNewPost({ title });
		await editor.insertBlock({ name: 'core/paragraph' });
		await this.page.keyboard.type(content);
		await this.page.getByRole('button', { name: 'Save draft' }).click();
		await this.page.waitForSelector('.editor-post-saved-state.is-saved');
	}

	/**
	 * Select a model from the model selector.
	 *
	 * @param {string} modelId - Model ID to select (e.g., 'gpt-4o').
	 */
	async selectModel(modelId) {
		const modelSelect = this.page.getByLabel('AI Model');
		await modelSelect.selectOption(modelId);
	}

	/**
	 * Expand Review Settings panel if collapsed.
	 */
	async expandReviewSettings() {
		const reviewSettingsButton = this.page.getByRole('button', {
			name: 'Review Settings',
		});
		const isExpanded =
			await reviewSettingsButton.getAttribute('aria-expanded');

		if (isExpanded === 'false') {
			await reviewSettingsButton.click();
		}
	}

	/**
	 * Wait for settings to be saved (accounting for debounce).
	 * Waits for the settings API request to complete.
	 *
	 * @param {number} timeout - Maximum time to wait in milliseconds (default 3000ms).
	 */
	async waitForSettingsSave(timeout = 3000) {
		try {
			// Wait for the settings POST request to complete
			await this.page.waitForResponse(
				(response) =>
					response.url().includes('/ai-feedback/v1/settings') &&
					response.request().method() === 'POST' &&
					response.status() === 200,
				{ timeout }
			);
		} catch (error) {
			// If no request was made (settings unchanged), just wait for debounce time
			await this.page.waitForTimeout(600);
		}
	}

	/**
	 * Locate the primary review button, in either the empty state or the
	 * Review Document panel.
	 *
	 * @return {import('@playwright/test').Locator} Review button.
	 */
	getReviewButton() {
		return this.page
			.locator('.ai-feedback-panel button.is-primary')
			.filter({ hasText: /Review(ing)?/i })
			.first();
	}

	/**
	 * Mock the review API with a custom response.
	 *
	 * @param {Object} response      - Review response; merged over a default review.
	 * @param {Object} options       - Options object.
	 * @param {number} options.delay - Milliseconds to wait before responding.
	 */
	async mockReviewAPI(response, { delay = 0 } = {}) {
		await this.page.route(restRoute('/review'), async (route) => {
			if (delay) {
				await new Promise((resolve) => setTimeout(resolve, delay));
			}
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(buildReviewResponse(response)),
			});
		});
	}

	/**
	 * Mock the review API to return an error.
	 *
	 * @param {number} status  - HTTP status code.
	 * @param {string} code    - Error code.
	 * @param {string} message - Error message.
	 * @param {Object} data    - Optional error data, e.g. `{ status: 402 }`.
	 */
	async mockReviewAPIError(status, code, message, data = { status }) {
		await this.page.route(restRoute('/review'), async (route) => {
			await route.fulfill({
				status,
				contentType: 'application/json',
				body: JSON.stringify({ code, message, data }),
			});
		});
	}

	/**
	 * Route a plugin REST endpoint with a custom handler.
	 *
	 * @param {string}   route   - Route relative to the plugin namespace.
	 * @param {Function} handler - Playwright route handler.
	 */
	async routeREST(route, handler) {
		await this.page.route(restRoute(route), handler);
	}

	/**
	 * Remove all handlers registered for a plugin REST endpoint.
	 *
	 * @param {string} route - Route relative to the plugin namespace.
	 */
	async unrouteREST(route) {
		await this.page.unroute(restRoute(route));
	}

	/**
	 * Click the review button and wait for the review request to finish.
	 *
	 * @return {Promise<import('@playwright/test').Response|null>} Review response, or null when the request failed at the network level.
	 */
	async runReview() {
		const requestPromise = this.page.waitForRequest(
			(request) =>
				request.method() === 'POST' &&
				restRoute('/review')(new URL(request.url()))
		);
		await this.getReviewButton().click();
		const request = await requestPromise;
		// Resolves to null when the request fails at the network level.
		const response = await request.response();
		await expect(this.getReviewButton()).not.toHaveClass(/is-busy/);
		return response;
	}

	/**
	 * Start a review and wait for completion.
	 * Assumes API is already mocked if needed.
	 *
	 * @param {number} timeout          - Maximum time to wait for the review to complete and button to return to ready state. Default: 10000ms.
	 * @param {number} reviewingTimeout - Maximum time to wait for the "Reviewing" button state to appear. Default: 1000ms.
	 */
	async startReviewAndWait(timeout = 10000, reviewingTimeout = 1000) {
		// Use the primary button specifically
		const reviewButton = this.page
			.locator('button.is-primary')
			.filter({ hasText: /Review( Document)?/i })
			.first();
		await reviewButton.click();

		// Wait for reviewing state to appear
		try {
			await this.page.locator('button.is-primary.is-busy').waitFor({
				state: 'visible',
				timeout: reviewingTimeout,
			});
		} catch (error) {
			// Reviewing state might not appear if review completes very quickly.
			// Only ignore timeout errors, re-throw others.
			if (!error.message?.includes('Timeout')) {
				throw error;
			}
		}

		// Wait for review to complete (button no longer busy)
		await this.page
			.locator('.ai-feedback-panel button.is-primary:not(.is-busy)')
			.first()
			.waitFor({
				state: 'visible',
				timeout,
			});
	}
}

/**
 * Extend the base test with custom fixtures.
 */
const test = base.extend({
	// Route reviews through the plugin's mock AI response (see
	// tests/mu-plugins/ai-feedback-e2e.php) so no real provider is called.
	e2eMockMode: [
		async ({ context }, use) => {
			await context.addCookies([
				{
					name: 'ai_feedback_e2e',
					value: '1',
					url: process.env.WP_BASE_URL || 'http://localhost:8889',
				},
			]);
			await use();
		},
		{ auto: true },
	],
	// The welcome or setup modal opens after an async status check, so it
	// can cover the sidebar at any point. Mark it as seen up front.
	skipWelcomeModal: [
		async ({ page }, use) => {
			await page.addInitScript(() => {
				try {
					window.localStorage.setItem('ai-feedback-welcomed', 'true');
				} catch {}
			});
			await use();
		},
		{ auto: true },
	],
	pageUtils: async ({ page }, use) => {
		await use(new PageUtils({ page }));
	},
	editor: async ({ page }, use) => {
		await use(new Editor({ page }));
	},
	admin: async ({ page, pageUtils, editor }, use) => {
		await use(new Admin({ page, pageUtils, editor }));
	},
	requestUtils: async ({}, use) => {
		const requestUtils = await RequestUtils.setup({
			baseURL: process.env.WP_BASE_URL || 'http://localhost:8889',
			user: {
				username: 'admin',
				password: 'password',
			},
		});
		await use(requestUtils);
	},
	aiFeedback: async ({ page, admin }, use) => {
		await use(new AIFeedbackUtils({ page, admin }));
	},
});

module.exports = { test, expect, buildReviewResponse };
