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
	 */
	async openSidebar() {
		// The sidebar panel - WordPress uses complementary role for plugin sidebars
		const sidebarPanel = this.page
			.locator('.ai-feedback-sidebar, [class*="ai-feedback"]')
			.first();

		// Check if sidebar is already open by looking for the panel heading
		const isOpen = await sidebarPanel.isVisible().catch(() => false);

		if (!isOpen) {
			// Open the options menu if not already open - use exact match
			const optionsButton = this.page.getByRole('button', {
				name: 'Options',
				exact: true,
			});
			const menuVisible = await this.page
				.getByRole('menu', { name: 'Options' })
				.isVisible()
				.catch(() => false);

			if (!menuVisible) {
				await optionsButton.click();
			}

			// Click AI Feedback menu item
			const sidebarButton = this.page.getByRole('menuitemcheckbox', {
				name: 'AI Feedback',
			});
			await sidebarButton.click();
		}

		// Wait for the sidebar content to be visible - look for the primary Review Document button
		await this.page
			.locator('button.is-primary:has-text("Review Document")')
			.waitFor({ state: 'visible', timeout: 10000 });
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
		await this.page
			.getByRole('button', { name: 'Review Document' })
			.click();

		// Wait for reviewing state to appear and then disappear
		try {
			await this.page
				.getByRole('button', { name: /Reviewing/i })
				.waitFor({
					state: 'visible',
					timeout: reviewingTimeout,
				});
		} catch (error) {
			// Reviewing state might not appear if review completes very quickly
			// Only ignore timeout errors, re-throw other errors
			if (
				error.name !== 'TimeoutError' &&
				!error.message?.includes('Timeout')
			) {
				throw error;
			}
		}

		// Wait for review to complete
		await this.page
			.getByRole('button', { name: 'Review Document' })
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

module.exports = { test, expect };
