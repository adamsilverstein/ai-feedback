/**
 * Tip Notice Component
 *
 * Shows the next progressive tip the user hasn't dismissed yet.
 */
import { Notice } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as keyboardShortcutsStore } from '@wordpress/keyboard-shortcuts';
import { store as preferencesStore } from '@wordpress/preferences';
import { STORE_NAME } from '../store';
import { PREFERENCES_SCOPE, PREFERENCE_KEYS, getActiveTip } from '../tips';
import { navigateToBlock } from '../utils/navigate-to-block';
import {
	getAdjacentFeedbackBlock,
	getFeedbackBlockIds,
} from '../utils/feedback-navigation';

const REVIEW_SHORTCUT = 'ai-feedback/review';
const EMPTY_ARRAY = [];

/**
 * Tip Notice component.
 *
 * @return {Element|null} Tip notice or null when there's nothing to show.
 */
export default function TipNotice() {
	const { firstNoteClientId, ...tipsState } = useSelect((select) => {
		const { get } = select(preferencesStore);
		const aiFeedback = select(STORE_NAME);
		return {
			// Topmost block with feedback in document order, matching the
			// next feedback shortcut. Notes come back sorted by priority.
			firstNoteClientId: getAdjacentFeedbackBlock(
				select(blockEditorStore).getClientIdsWithDescendants(),
				getFeedbackBlockIds(aiFeedback.getLastReview()?.notes),
				null,
				1
			),
			reviewCount:
				get(PREFERENCES_SCOPE, PREFERENCE_KEYS.reviewCount) || 0,
			dismissedTips:
				get(PREFERENCES_SCOPE, PREFERENCE_KEYS.dismissedTips) ||
				EMPTY_ARRAY,
			modelCount: aiFeedback.getAvailableModels()?.length || 0,
			focusAreaCount: aiFeedback.getAvailableFocusAreas()?.length || 0,
			toneCount: aiFeedback.getAvailableTones()?.length || 0,
			reviewShortcut:
				select(keyboardShortcutsStore).getShortcutRepresentation(
					REVIEW_SHORTCUT
				) || '',
		};
	}, []);
	const { dismissedTips } = tipsState;
	const { set } = useDispatch(preferencesStore);

	const actionHandlers = {
		'select-first-note': firstNoteClientId
			? () => navigateToBlock(firstNoteClientId)
			: null,
	};

	const tip = getActiveTip(tipsState);

	if (!tip) {
		return null;
	}

	const dismiss = () => {
		set(PREFERENCES_SCOPE, PREFERENCE_KEYS.dismissedTips, [
			...dismissedTips,
			tip.id,
		]);
	};

	const actionHandler = tip.action ? actionHandlers[tip.action.type] : null;
	const actions = actionHandler
		? [
				{
					label: tip.action.label,
					onClick: actionHandler,
					variant: 'link',
				},
			]
		: [];

	return (
		<Notice
			status="info"
			className="ai-feedback-tip"
			onRemove={dismiss}
			// Speaking the tip would cut off the review complete
			// announcement, which lands at the same moment.
			spokenMessage=""
			actions={actions}
		>
			{tip.content}
		</Notice>
	);
}
