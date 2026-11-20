/**
 * External dependencies
 */
import { describe, it, expect, vi, afterEach } from 'vitest';

/**
 * Internal dependencies
 */
import reducer, { initialState, TYPES } from '../reducer';
import { setReviewAudience, startReview } from '../actions';
import {
	getAvailablePersonas,
	getPersona,
	getReviewAudience,
} from '../selectors';

vi.mock('@wordpress/block-editor', () => ({
	store: 'core/block-editor',
}));

const PERSONAS = [
	{ id: 'copy_editor', label: 'Copy editor', description: '' },
	{ id: 'busy_skimmer', label: 'Busy skimmer', description: '' },
];

describe('persona settings', () => {
	it('default to the copy editor with no audience', () => {
		expect(getPersona(initialState)).toBe('copy_editor');
		expect(getReviewAudience(initialState)).toBe('');
		expect(getAvailablePersonas(initialState)).toEqual([]);
	});

	it('load from the settings response', () => {
		const state = reducer(initialState, {
			type: TYPES.SET_SETTINGS,
			settings: {
				default_persona: 'busy_skimmer',
				review_audience: 'plugin developers',
				available_personas: PERSONAS,
			},
		});

		expect(getPersona(state)).toBe('busy_skimmer');
		expect(getReviewAudience(state)).toBe('plugin developers');
		expect(getAvailablePersonas(state)).toEqual(PERSONAS);
	});

	it('fall back when the response has none', () => {
		const state = reducer(initialState, {
			type: TYPES.SET_SETTINGS,
			settings: {},
		});

		expect(getPersona(state)).toBe('copy_editor');
		expect(getReviewAudience(state)).toBe('');
		expect(getAvailablePersonas(state)).toEqual([]);
	});

	it('update the audience locally while it is typed', () => {
		const state = reducer(
			initialState,
			setReviewAudience('plugin developers')
		);

		expect(getReviewAudience(state)).toBe('plugin developers');
		expect(getPersona(state)).toBe('copy_editor');
	});
});

describe('startReview with a persona', () => {
	afterEach(() => {
		vi.restoreAllMocks();
	});

	/**
	 * Run startReview up to its review request.
	 *
	 * @param {Object} options startReview options.
	 * @return {Object} Request data.
	 */
	function requestData(options) {
		vi.spyOn(console, 'log').mockImplementation(() => {});
		const gen = startReview({
			postId: 1,
			title: 'Title',
			blocks: [
				{ clientId: 'a', name: 'core/paragraph', content: 'Text' },
			],
			...options,
		});
		gen.next(); // START_REVIEW.
		gen.next(); // Feedback history request.
		let result = gen.next({ has_history: false });
		while (result.value?.type === TYPES.SET_REVIEW_STAGE) {
			result = gen.next();
		}
		return result.value.request.data;
	}

	it('sends the persona and audience', () => {
		const data = requestData({
			persona: 'busy_skimmer',
			audience: 'plugin developers',
		});

		expect(data.persona).toBe('busy_skimmer');
		expect(data.audience).toBe('plugin developers');
	});

	it('leaves out an empty audience', () => {
		const data = requestData({ persona: 'copy_editor', audience: '' });

		expect(data.persona).toBe('copy_editor');
		expect(data).not.toHaveProperty('audience');
	});
});
