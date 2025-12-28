/**
 * Playwright configuration for AI Feedback E2E tests.
 * Extends the WordPress scripts default configuration.
 */
const path = require('path');
const { defineConfig } = require('@playwright/test');
const defaultConfig = require('@wordpress/scripts/config/playwright.config');

process.env.WP_ARTIFACTS_PATH ??= path.join(process.cwd(), 'artifacts');
process.env.WP_BASE_URL ??= 'http://localhost:8889';

const config = defineConfig({
	...defaultConfig,
	testDir: './tests/e2e/specs',
	use: {
		...defaultConfig.use,
		// Override base URL if needed
		baseURL: process.env.WP_BASE_URL,
	},
});

module.exports = config;
