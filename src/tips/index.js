/**
 * Progressive tips shown as users complete more reviews.
 *
 * Seen state lives in the core preferences store so it follows the user
 * across browsers instead of being tied to localStorage.
 */
import { __ } from '@wordpress/i18n';

export const PREFERENCES_SCOPE = 'ai-feedback';

export const PREFERENCE_KEYS = {
	welcomeDismissed: 'welcomeDismissed',
	reviewCount: 'reviewCount',
	dismissedTips: 'dismissedTips',
};

export const PREFERENCE_DEFAULTS = {
	[PREFERENCE_KEYS.welcomeDismissed]: false,
	[PREFERENCE_KEYS.reviewCount]: 0,
	[PREFERENCE_KEYS.dismissedTips]: [],
};

/**
 * Get the tip definitions, in display order.
 *
 * `minReviews` is the completed review count at which a tip becomes
 * eligible. `isAvailable` hides tips for features the site can't use.
 * `action.type` is resolved to a handler by the TipNotice component.
 *
 * @return {Array<Object>} Tip definitions.
 */
export function getTips() {
	return [
		{
			id: 'notes-on-blocks',
			minReviews: 1,
			content: __(
				'Tip: Each piece of feedback is saved as a Note on its block. Select a block with a note icon to read, reply to, or resolve it.',
				'ai-feedback'
			),
			action: {
				type: 'select-first-note',
				label: __('Show first note', 'ai-feedback'),
			},
		},
		{
			id: 'reply-to-notes',
			minReviews: 2,
			content: __(
				'Tip: Reply to a note or resolve it before reviewing again. The next review takes your replies and open notes into account.',
				'ai-feedback'
			),
		},
		{
			id: 'feedback-language',
			minReviews: 3,
			content: __(
				'Tip: Use the Feedback Language setting under Review Settings to get feedback in a different language.',
				'ai-feedback'
			),
		},
		{
			id: 'model-selection',
			minReviews: 5,
			isAvailable: ({ modelCount }) => modelCount > 1,
			content: __(
				'Tip: Different AI models have different strengths. Try another model from Review Settings for a fresh perspective.',
				'ai-feedback'
			),
		},
	];
}

/**
 * Find the first tip the user is eligible for and hasn't dismissed.
 *
 * @param {Object}        state               Tips state.
 * @param {number}        state.reviewCount   Completed reviews.
 * @param {Array<string>} state.dismissedTips Dismissed tip IDs.
 * @param {number}        state.modelCount    Available model count.
 * @param {Array<Object>} tips                Tip definitions.
 * @return {Object|null} The tip to show, or null.
 */
export function getActiveTip(
	{ reviewCount = 0, dismissedTips = [], modelCount = 0 },
	tips = getTips()
) {
	return (
		tips.find(
			(tip) =>
				reviewCount >= tip.minReviews &&
				!dismissedTips.includes(tip.id) &&
				(!tip.isAvailable || tip.isAvailable({ modelCount }))
		) || null
	);
}
