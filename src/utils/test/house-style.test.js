/**
 * Tests for the house style REST helpers.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import apiFetch from '@wordpress/api-fetch';
import {
	HOUSE_STYLE_PATH,
	addRule,
	deleteRule,
	dismissProposal,
	hasRule,
	loadHouseStyle,
	resetHouseStyle,
	ruleKey,
	subscribeHouseStyle,
	suggestRules,
	updateRule,
} from '../house-style';

vi.mock('@wordpress/api-fetch', () => ({ default: vi.fn() }));

const STATE = {
	site: [{ id: 's1', text: 'Use the Oxford comma' }],
	user: [{ id: 'u1', text: 'Headings use sentence case' }],
	can_manage_site: false,
	max_rules: 20,
	max_length: 150,
};

beforeEach(() => {
	resetHouseStyle();
	apiFetch.mockReset();
});

describe('ruleKey and hasRule', () => {
	it('ignores case, spacing and closing punctuation', () => {
		expect(ruleKey('  Headings  use Sentence case. ')).toBe(
			'headings use sentence case'
		);
		expect(ruleKey('Use the Oxford comma?;')).toBe('use the oxford comma');
	});

	it('finds a rule in either list', () => {
		expect(hasRule(STATE, 'use the oxford comma.')).toBe(true);
		expect(hasRule(STATE, 'Headings use sentence case')).toBe(true);
		expect(hasRule(STATE, 'Spell out numbers')).toBe(false);
		expect(hasRule(null, 'Anything')).toBe(false);
	});
});

describe('loadHouseStyle', () => {
	it('fetches once and shares the result', async () => {
		apiFetch.mockResolvedValue(STATE);

		await expect(loadHouseStyle()).resolves.toBe(STATE);
		await loadHouseStyle();

		expect(apiFetch).toHaveBeenCalledTimes(1);
		expect(apiFetch).toHaveBeenCalledWith({ path: HOUSE_STYLE_PATH });
	});

	it('fetches again after a failure', async () => {
		apiFetch.mockRejectedValueOnce(new Error('offline'));
		apiFetch.mockResolvedValueOnce(STATE);

		await expect(loadHouseStyle()).rejects.toThrow('offline');
		await expect(loadHouseStyle()).resolves.toBe(STATE);
	});
});

describe('changes', () => {
	it('adds a rule from a proposal and tells listeners', async () => {
		const listener = vi.fn();
		subscribeHouseStyle(listener);
		apiFetch.mockResolvedValue(STATE);

		await addRule({ text: 'Rule', scope: 'site', noteId: 12 });

		expect(apiFetch).toHaveBeenCalledWith({
			path: HOUSE_STYLE_PATH,
			method: 'POST',
			data: { text: 'Rule', scope: 'site', note_id: 12 },
		});
		expect(listener).toHaveBeenCalledWith(STATE);
		// The shared copy is the new state, so a later load needs no request.
		await expect(loadHouseStyle()).resolves.toBe(STATE);
		expect(apiFetch).toHaveBeenCalledTimes(1);
	});

	it('adds a personal rule without a note by default', async () => {
		apiFetch.mockResolvedValue(STATE);

		await addRule({ text: 'Rule' });

		expect(apiFetch.mock.calls[0][0].data).toEqual({
			text: 'Rule',
			scope: 'user',
		});
	});

	it('updates and deletes a rule in its scope', async () => {
		apiFetch.mockResolvedValue(STATE);

		await updateRule('u1', 'New text', 'user');
		await deleteRule('s1', 'site');

		expect(apiFetch).toHaveBeenNthCalledWith(1, {
			path: `${HOUSE_STYLE_PATH}/u1`,
			method: 'PUT',
			data: { text: 'New text', scope: 'user' },
		});
		expect(apiFetch).toHaveBeenNthCalledWith(2, {
			path: `${HOUSE_STYLE_PATH}/s1?scope=site`,
			method: 'DELETE',
		});
	});

	it('dismisses a proposal', async () => {
		apiFetch.mockResolvedValue({});

		await dismissProposal(12);

		expect(apiFetch).toHaveBeenCalledWith({
			path: `${HOUSE_STYLE_PATH}/proposals/12/dismiss`,
			method: 'POST',
		});
	});

	it('stops telling a listener once it unsubscribes', async () => {
		const listener = vi.fn();
		const unsubscribe = subscribeHouseStyle(listener);
		unsubscribe();
		apiFetch.mockResolvedValue(STATE);

		await addRule({ text: 'Rule' });

		expect(listener).not.toHaveBeenCalled();
	});
});

describe('suggestRules', () => {
	it('asks for suggestions without changing the shared rules', async () => {
		const listener = vi.fn();
		subscribeHouseStyle(listener);
		apiFetch.mockResolvedValue({ suggestions: ['Write WordPress'] });

		await expect(
			suggestRules({ scope: 'site', model: 'some-model' })
		).resolves.toEqual({ suggestions: ['Write WordPress'] });

		expect(apiFetch).toHaveBeenCalledWith({
			path: '/ai-feedback/v1/house-style/suggestions/posts',
			method: 'POST',
			data: { scope: 'site', model: 'some-model' },
		});
		expect(listener).not.toHaveBeenCalled();
	});

	it('defaults to the user’s own posts and the default model', async () => {
		apiFetch.mockResolvedValue({ suggestions: [] });

		await suggestRules();

		expect(apiFetch.mock.lastCall[0].data).toEqual({ scope: 'user' });
	});
});
