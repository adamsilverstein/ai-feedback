/**
 * Progressive tips shown as users complete more reviews.
 *
 * Seen state lives in the core preferences store so it follows the user
 * across browsers instead of being tied to localStorage.
 */
import { __, sprintf } from '@wordpress/i18n';
import { dispatch, select } from '@wordpress/data';
import { store as preferencesStore } from '@wordpress/preferences';

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
 * Count a completed review toward unlocking tips.
 */
export function recordCompletedReview() {
	const reviewCount =
		select(preferencesStore).get(
			PREFERENCES_SCOPE,
			PREFERENCE_KEYS.reviewCount
		) || 0;
	dispatch(preferencesStore).set(
		PREFERENCES_SCOPE,
		PREFERENCE_KEYS.reviewCount,
		reviewCount + 1
	);
}

/**
 * Get the tip definitions, in display order.
 *
 * `minReviews` is the completed review count at which a tip becomes
 * eligible. `isAvailable` hides tips for features the site can't use.
 * `action.type` is resolved to a handler by the TipNotice component.
 *
 * @param {Object} context                Site and editor context.
 * @param {string} context.reviewShortcut Display form of the review shortcut.
 * @return {Array<Object>} Tip definitions.
 */
export function getTips({ reviewShortcut = '' } = {}) {
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
			id: 'focus-areas',
			minReviews: 3,
			isAvailable: ({ focusAreaCount }) => focusAreaCount > 1,
			content: __(
				'Tip: Pick Focus Areas under Review Settings to get more targeted feedback on what matters to you.',
				'ai-feedback'
			),
		},
		{
			id: 'target-tone',
			minReviews: 4,
			isAvailable: ({ toneCount }) => toneCount > 0,
			content: __(
				'Tip: Set a Target Tone under Review Settings to match your audience. Suggestions will be tailored to it.',
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
		{
			id: 'feedback-language',
			minReviews: 7,
			content: __(
				'Tip: Use the Feedback Language setting under Review Settings to get feedback in a different language.',
				'ai-feedback'
			),
		},
		{
			id: 'keyboard-shortcuts',
			minReviews: 10,
			isAvailable: () => !!reviewShortcut,
			content: sprintf(
				/* translators: %s: keyboard shortcut, e.g. Shift+Alt+R. */
				__(
					'Tip: Press %s to start a review from anywhere in the editor, even with the sidebar closed.',
					'ai-feedback'
				),
				reviewShortcut
			),
		},
	];
}

/**
 * Find the first tip the user is eligible for and hasn't dismissed.
 *
 * @param {Object}        state                Tips state.
 * @param {number}        state.reviewCount    Completed reviews.
 * @param {Array<string>} state.dismissedTips  Dismissed tip IDs.
 * @param {number}        state.modelCount     Available model count.
 * @param {number}        state.focusAreaCount Available focus area count.
 * @param {number}        state.toneCount      Available tone count.
 * @param {string}        state.reviewShortcut Display form of the review shortcut.
 * @param {Array<Object>} tips                 Tip definitions.
 * @return {Object|null} The tip to show, or null.
 */
export function getActiveTip(state, tips = getTips(state)) {
	const {
		reviewCount = 0,
		dismissedTips = [],
		modelCount = 0,
		focusAreaCount = 0,
		toneCount = 0,
	} = state;

	return (
		tips.find(
			(tip) =>
				reviewCount >= tip.minReviews &&
				!dismissedTips.includes(tip.id) &&
				(!tip.isAvailable ||
					tip.isAvailable({ modelCount, focusAreaCount, toneCount }))
		) || null
	);
}
