/**
 * Internal dependencies
 */
import { getActiveTip, getTips } from '../index';

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
			})
		).toBeNull();
	});

	it('hides the model tip when only one model is available', () => {
		const dismissedTips = [
			'notes-on-blocks',
			'reply-to-notes',
			'feedback-language',
		];
		expect(
			getActiveTip({ reviewCount: 5, dismissedTips, modelCount: 1 })
		).toBeNull();
		expect(
			getActiveTip({ reviewCount: 5, dismissedTips, modelCount: 3 }).id
		).toBe('model-selection');
	});

	it('returns null once every tip is dismissed', () => {
		expect(
			getActiveTip({
				reviewCount: 10,
				modelCount: 3,
				dismissedTips: getTips().map((tip) => tip.id),
			})
		).toBeNull();
	});

	it('defines tips with unique ids in ascending review order', () => {
		const tips = getTips();
		const ids = tips.map((tip) => tip.id);
		expect(new Set(ids).size).toBe(ids.length);
		const thresholds = tips.map((tip) => tip.minReviews);
		expect([...thresholds].sort((a, b) => a - b)).toEqual(thresholds);
	});
});
