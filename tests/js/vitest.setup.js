/**
 * Vitest setup: DOM matchers and cleanup between component tests.
 */
import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach, vi } from 'vitest';

afterEach(() => {
	cleanup();
});

// jsdom has no matchMedia, which some @wordpress/components use.
if (!window.matchMedia) {
	window.matchMedia = (query) => ({
		matches: false,
		media: query,
		onchange: null,
		addListener: () => {},
		removeListener: () => {},
		addEventListener: () => {},
		removeEventListener: () => {},
		dispatchEvent: () => false,
	});
}

// Testing Library's waitFor advances fake timers through a global `jest`.
globalThis.jest = {
	advanceTimersByTime: (ms) => vi.advanceTimersByTime(ms),
};
