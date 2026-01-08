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

		// Check if sidebar is already open
		const isOpen = await customPanel.isVisible().catch(() => false);

		if (!isOpen) {
			// First, check if the plugin is pinned to the toolbar
			const pinnedButton = topBar.getByRole('button', {
				name: 'AI Feedback',
			});
			const isPinnedVisible = await pinnedButton
				.isVisible()
				.catch(() => false);

			if (isPinnedVisible) {
				await pinnedButton.click();
			} else {
				// Not pinned, use the Options (three dots) menu
				const optionsButton = topBar.getByRole('button', {
					name: 'Options',
					exact: true,
				});

				await optionsButton.click();

				// Wait for the menu to appear and click the AI Feedback item
				await this.page
					.getByRole('menuitemcheckbox', { name: 'AI Feedback' })
					.click();
			}
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
				const getStartedButton = this.page.getByRole('button', {
					name: 'Get Started',
				});
				const closeButton = this.page.getByRole('button', {
					name: 'Close',
				});

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
	 * Select a model from the model selector.
	 *
	 * @param {string} modelId - Model ID to select (e.g., 'gpt-4o').
	 */
	async selectModel(modelId) {
		const modelSelect = this.page.getByLabel('AI Model');
		await modelSelect.selectOption(modelId);
	}

	/**
	 * Toggle a focus area checkbox.
	 *
	 * @param {string}  label   - Label of the focus area (e.g., 'Content Quality').
	 * @param {boolean} checked - Whether to check or uncheck.
	 */
	async toggleFocusArea(label, checked) {
		const checkbox = this.page.getByLabel(label, { exact: true });
		if (checked) {
			await checkbox.check();
		} else {
			await checkbox.uncheck();
		}
	}

	/**
	 * Select a target tone.
	 *
	 * @param {string} toneId - Tone ID to select (e.g., 'academic').
	 */
	async selectTone(toneId) {
		const toneSelect = this.page.getByLabel('Target Tone');
		await toneSelect.selectOption(toneId);
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
	 * Mock the review API with a custom response.
	 *
	 * @param {Object} response - Custom response object.
	 */
	async mockReviewAPI(response) {
		await this.page.route(
			'**/wp-json/ai-feedback/v1/review',
			async (route) => {
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify(response),
				});
			}
		);
	}

	/**
	 * Mock the review API to return an error.
	 *
	 * @param {number} status  - HTTP status code.
	 * @param {string} code    - Error code.
	 * @param {string} message - Error message.
	 */
	async mockReviewAPIError(status, code, message) {
		await this.page.route(
			'**/wp-json/ai-feedback/v1/review',
			async (route) => {
				await route.fulfill({
					status,
					contentType: 'application/json',
					body: JSON.stringify({ code, message }),
				});
			}
		);
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
			// Reviewing state might not appear if review completes very quickly
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
	pageUtils: async ({ page }, use) => {
		await use(new PageUtils({ page }));
	},
	editor: async ({ page }, use) => {
		await use(new Editor({ page }));
	},
	admin: async ({ page, pageUtils }, use) => {
		await use(new Admin({ page, pageUtils }));
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

module.exports = { test, expect };
