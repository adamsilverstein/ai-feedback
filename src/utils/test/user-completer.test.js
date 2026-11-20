/**
 * Tests for keeping the Fresh Eyes user out of the block user completer.
 */
import { describe, it, expect, afterEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { applyFilters, addFilter, removeFilter } from '@wordpress/hooks';

import {
	getFreshEyesUserId,
	hideFreshEyesUser,
	hideUserFromCompleter,
} from '../user-completer';

const FRESH_EYES = { id: 41, name: 'Fresh Eyes', slug: 'fresh-eyes' };
const FRED = { id: 7, name: 'Fred', slug: 'fred' };

/**
 * A completer listing users, like the block editor's.
 *
 * @param {string} name Completer name.
 * @return {Object} Completer.
 */
function userCompleter(name = 'users') {
	return {
		name,
		triggerPrefix: '@',
		useItems: (filterValue) => [
			[FRESH_EYES, FRED].map((user) => ({
				key: `user-${user.slug}`,
				value: user,
				label: `${user.name} ${filterValue}`,
			})),
		],
	};
}

afterEach(() => {
	delete window.aiFeedbackData;
});

describe('getFreshEyesUserId', () => {
	it('reads a positive ID from the server', () => {
		window.aiFeedbackData = { mentionUserId: 41 };
		expect(getFreshEyesUserId()).toBe(41);
	});

	it.each([undefined, 0, -3, 'abc', 1.5])('ignores %s', (value) => {
		window.aiFeedbackData = { mentionUserId: value };
		expect(getFreshEyesUserId()).toBe(0);
	});
});

describe('hideUserFromCompleter', () => {
	it('leaves the user out of the user completer', () => {
		const completer = hideUserFromCompleter(userCompleter(), 41);
		const { result } = renderHook(() => completer.useItems('fr'));

		expect(result.current[0].map((option) => option.value.id)).toEqual([7]);
		expect(completer.name).toBe('users');
		expect(completer.triggerPrefix).toBe('@');
	});

	it('leaves other completers alone', () => {
		const other = userCompleter('links');
		expect(hideUserFromCompleter(other, 41)).toBe(other);
	});

	it('does nothing without a user', () => {
		const completer = userCompleter();
		expect(hideUserFromCompleter(completer, 0)).toBe(completer);
	});
});

describe('hideFreshEyesUser', () => {
	afterEach(() => {
		removeFilter('editor.Autocomplete.completers', 'test/defaults');
		removeFilter('editor.Autocomplete.completers', 'test/hide');
	});

	it('runs as a completers filter after the defaults are added', () => {
		window.aiFeedbackData = { mentionUserId: 41 };
		addFilter(
			'editor.Autocomplete.completers',
			'test/defaults',
			(completers = []) => [...completers, userCompleter()]
		);
		addFilter(
			'editor.Autocomplete.completers',
			'test/hide',
			hideFreshEyesUser,
			20
		);

		const [completer] = applyFilters('editor.Autocomplete.completers', []);
		const { result } = renderHook(() => completer.useItems('fresh'));

		expect(result.current[0].map((option) => option.value.name)).toEqual([
			'Fred',
		]);
	});

	it('passes anything that is not a list through', () => {
		expect(hideFreshEyesUser(undefined)).toBeUndefined();
	});
});
