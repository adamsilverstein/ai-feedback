/**
 * External dependencies
 */
import { describe, it, expect } from 'vitest';

/**
 * Internal dependencies
 */
import { getActiveTip, getTips } from '../index';

const ALL_FEATURES = {
	modelCount: 3,
	focusAreaCount: 4,
	toneCount: 5,
	reviewShortcut: 'Shift+Alt+R',
};

/**
 * Get the IDs of every tip before the given one.
 *
 * @param {string} id Tip ID.
 * @return {Array<string>} Earlier tip IDs.
 */
function idsBefore(id) {
	const ids = getTips(ALL_FEATURES).map((tip) => tip.id);
	return ids.slice(0, ids.indexOf(id));
}

describe('getActiveTip', () => {
	it('returns null before the first review', () => {
		expect(getActiveTip({ reviewCount: 0 })).toBeNull();
	});

	it('returns the first tip after one review', () => {
		expect(getActiveTip({ reviewCount: 1 }).id).toBe('notes-on-blocks');
	});

	it('skips dismissed tips', () => {
		expect(
			getActiveTip({
				reviewCount: 3,
				dismissedTips: ['notes-on-blocks'],
			}).id
		).toBe('reply-to-notes');
	});

	it('does not show tips before their review threshold', () => {
		expect(
			getActiveTip({
				reviewCount: 2,
				dismissedTips: ['notes-on-blocks', 'reply-to-notes'],
				...ALL_FEATURES,
			})
		).toBeNull();
	});

	it.each([
		['focus-areas', 3, { focusAreaCount: 1 }],
		['target-tone', 4, { toneCount: 0 }],
		['model-selection', 5, { modelCount: 1 }],
		['keyboard-shortcuts', 10, { reviewShortcut: '' }],
	])(
		'shows %s only when the feature is available',
		(id, reviewCount, unavailable) => {
			const dismissedTips = idsBefore(id);
			expect(
				getActiveTip({ reviewCount, dismissedTips, ...ALL_FEATURES }).id
			).toBe(id);
			expect(
				getActiveTip({
					reviewCount,
					dismissedTips,
					...ALL_FEATURES,
					...unavailable,
				})?.id
			).not.toBe(id);
		}
	);

	it('puts the review shortcut in the keyboard tip', () => {
		const tip = getActiveTip({
			reviewCount: 10,
			dismissedTips: idsBefore('keyboard-shortcuts'),
			...ALL_FEATURES,
		});
		expect(tip.content).toContain('Shift+Alt+R');
	});

	it('returns null once every tip is dismissed', () => {
		expect(
			getActiveTip({
				reviewCount: 10,
				...ALL_FEATURES,
				dismissedTips: getTips(ALL_FEATURES).map((tip) => tip.id),
			})
		).toBeNull();
	});

	it('defines tips with unique ids in ascending review order', () => {
		const tips = getTips(ALL_FEATURES);
		const ids = tips.map((tip) => tip.id);
		expect(new Set(ids).size).toBe(ids.length);
		const thresholds = tips.map((tip) => tip.minReviews);
		expect([...thresholds].sort((a, b) => a - b)).toEqual(thresholds);
	});
});
