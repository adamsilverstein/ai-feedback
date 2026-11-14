/**
 * Internal dependencies
 */
import { describe, it, expect } from 'vitest';
import { getModelName } from '../selectors';

describe('getModelName', () => {
	const state = {
		availableModels: [
			{ id: 'claude-sonnet-5-5', name: 'Claude Sonnet 5.5' },
			{ id: 'gpt-4o', name: 'GPT-4o' },
		],
	};

	it('returns the display name of a listed model', () => {
		expect(getModelName(state, 'gpt-4o')).toBe('GPT-4o');
	});

	it('falls back to the ID for a model that is not listed', () => {
		expect(getModelName(state, 'retired-model')).toBe('retired-model');
	});

	it('falls back to the ID before models load', () => {
		expect(getModelName({}, 'gpt-4o')).toBe('gpt-4o');
	});
});
