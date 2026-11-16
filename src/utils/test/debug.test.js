/**
 * Internal dependencies
 */
import { describe, it, expect, vi, afterEach } from 'vitest';
import { debugLog } from '../debug';

describe('debugLog', () => {
	afterEach(() => {
		window.localStorage.clear();
		vi.restoreAllMocks();
	});

	it('stays quiet without the debug flag', () => {
		const spy = vi.spyOn(console, 'debug').mockImplementation(() => {});

		debugLog('hidden');

		expect(spy).not.toHaveBeenCalled();
	});

	it('logs when AI_FEEDBACK_DEBUG is on', () => {
		const spy = vi.spyOn(console, 'debug').mockImplementation(() => {});
		window.localStorage.setItem('AI_FEEDBACK_DEBUG', 'true');

		debugLog('shown', 1);

		expect(spy).toHaveBeenCalledWith('[AI-Feedback]', 'shown', 1);
	});
});
