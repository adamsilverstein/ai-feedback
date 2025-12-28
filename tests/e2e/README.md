# E2E Testing Guide

This document describes how to run and maintain the End-to-End (E2E) tests for the AI Feedback plugin.

## Overview

The E2E tests use [Playwright](https://playwright.dev/) with WordPress's E2E test utilities (`@wordpress/e2e-test-utils-playwright`) to test the plugin in a real browser environment.

## Prerequisites

1. Node.js 18+ and npm 9+
2. Docker (for running WordPress via wp-env)
3. All npm dependencies installed (`npm install`)

## Running Tests

### Quick Start

```bash
# Start the WordPress environment
npm run env:start

# Run all E2E tests
npm run test:e2e

# Run E2E tests in watch mode (for development)
npm run test:e2e:watch

# Run specific test file
npx playwright test tests/e2e/specs/settings.spec.js

# Run tests in headed mode (see the browser)
npx playwright test --headed
```

### WordPress Environment

The E2E tests require a running WordPress instance. The plugin uses `wp-env` for local development:

```bash
# Start wp-env
npm run env:start

# Stop wp-env
npm run env:stop

# Clean wp-env (reset to fresh state)
npm run env:clean
```

The WordPress site will be available at:
- Development site: http://localhost:8889
- Admin: http://localhost:8889/wp-admin (admin/password)

## Test Structure

```
tests/e2e/
├── fixtures.js              # Custom test fixtures and utilities
└── specs/
    └── settings.spec.js     # Settings persistence tests
```

### Fixtures

The `fixtures.js` file provides custom utilities for testing the AI Feedback plugin:

- `admin`: WordPress admin utilities from `@wordpress/e2e-test-utils-playwright`
- `editor`: WordPress editor utilities
- `page`: Playwright page object
- `aiFeedback`: Custom utilities for AI Feedback plugin

### Custom AI Feedback Utilities

The `aiFeedback` fixture provides:

- `openSidebar()`: Opens the AI Feedback sidebar
- `selectModel(modelId)`: Selects an AI model
- `toggleFocusArea(label, checked)`: Toggles a focus area checkbox
- `selectTone(toneId)`: Selects a target tone
- `expandReviewSettings()`: Expands the Review Settings panel
- `waitForSettingsSave(ms)`: Waits for debounced settings to save (default 600ms)

## Writing Tests

### Example Test

```javascript
const { test, expect } = require('../fixtures');

test.describe('My Feature', () => {
	test.beforeEach(async ({ admin }) => {
		await admin.createNewPost();
	});

	test('does something', async ({ admin, page, aiFeedback }) => {
		// Open the AI Feedback sidebar
		await aiFeedback.openSidebar();

		// Interact with the UI
		await aiFeedback.selectModel('gpt-4o');

		// Assert expected behavior
		const modelSelect = page.getByLabel('AI Model');
		await expect(modelSelect).toHaveValue('gpt-4o');
	});
});
```

### Best Practices

1. **Use Custom Fixtures**: Use the `aiFeedback` fixture for plugin-specific actions
2. **Wait for Debouncing**: Use `aiFeedback.waitForSettingsSave()` after changing settings
3. **Isolate Tests**: Each test should be independent and not rely on state from other tests
4. **Clean State**: Use `beforeEach` to set up a clean state for each test
5. **Descriptive Names**: Use clear, descriptive test names that explain what is being tested

## Configuration

The Playwright configuration is in `playwright.config.js` at the project root. It extends the default WordPress scripts configuration.

Key settings:
- Base URL: `http://localhost:8889` (wp-env default)
- Test directory: `./tests/e2e/specs`
- Browser: Chromium (desktop)
- Headless: `true` (can override with `--headed` flag)
- Artifacts: `./artifacts/` (screenshots, videos, traces)

## Debugging Tests

### View Browser

Run tests in headed mode to see the browser:

```bash
npx playwright test --headed
```

### Step Through Tests

Use the Playwright Inspector to step through tests:

```bash
npx playwright test --debug
```

### Screenshots and Videos

Failed tests automatically capture:
- Screenshots (on failure)
- Videos (on retry)
- Traces (on failure)

Artifacts are saved to `./artifacts/test-results/`.

### Logging

Add console logs in tests for debugging:

```javascript
test('my test', async ({ page }) => {
	console.log('Current URL:', page.url());
});
```

## Continuous Integration

The tests are designed to run in CI environments:

- Tests run in headless mode by default
- Failed tests are retried 2 times in CI
- Test artifacts are uploaded for debugging

## Troubleshooting

### Tests Fail to Start

1. Ensure Docker is running
2. Ensure wp-env is started: `npm run env:start`
3. Check if port 8889 is available

### Tests Timeout

1. Increase timeout in test: `test.setTimeout(120000);`
2. Check if WordPress is responding: http://localhost:8889
3. Check Docker container logs: `npm run env:logs`

### Selector Issues

1. Use Playwright Inspector to find selectors: `npx playwright test --debug`
2. Check if elements are visible: `await expect(element).toBeVisible()`
3. Wait for elements: `await page.waitForSelector('...')`

## Related Documentation

- [Playwright Documentation](https://playwright.dev/docs/intro)
- [WordPress E2E Test Utils](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-e2e-test-utils-playwright/)
- [wp-env Documentation](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/)
