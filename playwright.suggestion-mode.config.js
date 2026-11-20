/**
 * Playwright configuration for the Suggestion Mode e2e suite.
 *
 * Runs only tests/e2e/suggestion-mode/specs, against the wp-env instance
 * from .wp-env.suggestion-mode.json: the pinned Gutenberg build with its
 * Suggestion Mode experiment on. The main suite in playwright.config.js
 * keeps running against stock wp-env.
 */
const path = require('path');
const { defineConfig } = require('@playwright/test');

process.env.WP_ARTIFACTS_PATH ??= path.join(
	process.cwd(),
	'artifacts',
	'suggestion-mode'
);
process.env.WP_BASE_URL ??= `http://localhost:${
	process.env.WP_ENV_PORT || 8892
}`;

const defaultConfig = require('@wordpress/scripts/config/playwright.config');

module.exports = defineConfig({
	...defaultConfig,
	testDir: './tests/e2e/suggestion-mode/specs',
	use: {
		...defaultConfig.use,
		baseURL: process.env.WP_BASE_URL,
	},
	webServer: {
		...defaultConfig.webServer,
		command: 'npm run env:suggestion-mode:start',
		port: Number(new URL(process.env.WP_BASE_URL).port),
	},
});
