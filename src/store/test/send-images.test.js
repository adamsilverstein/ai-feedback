/**
 * Tests for the image setting in the store.
 */
import { describe, it, expect } from 'vitest';
import reducer, { initialState, TYPES } from '../reducer';
import { getSendImages, canManageSite } from '../selectors';

describe('image setting', () => {
	it('is on, and not editable, before settings load', () => {
		expect(getSendImages(initialState)).toBe(true);
		expect(canManageSite(initialState)).toBe(false);
	});

	it('loads from the settings response', () => {
		const state = reducer(initialState, {
			type: TYPES.SET_SETTINGS,
			settings: { send_images: false, can_manage_site: true },
		});

		expect(getSendImages(state)).toBe(false);
		expect(canManageSite(state)).toBe(true);
	});

	it('stays on when the response has none', () => {
		const state = reducer(initialState, {
			type: TYPES.SET_SETTINGS,
			settings: {},
		});

		expect(getSendImages(state)).toBe(true);
		expect(canManageSite(state)).toBe(false);
	});
});
