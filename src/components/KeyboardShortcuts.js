/**
 * Keyboard Shortcuts Component
 *
 * Registers the plugin's shortcuts so they show up in the editor's keyboard
 * shortcuts help (Shift+Alt+H, or Ctrl+Option+H on a Mac) and handles them.
 */
import { useEffect } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import {
	store as keyboardShortcutsStore,
	useShortcut,
} from '@wordpress/keyboard-shortcuts';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { speak } from '@wordpress/a11y';
import { __, sprintf } from '@wordpress/i18n';
import { STORE_NAME } from '../store';
import useStartReview from '../hooks/use-start-review';
import { navigateToBlock } from '../utils/navigate-to-block';
import {
	getAdjacentFeedbackBlock,
	getFeedbackBlockIds,
} from '../utils/feedback-navigation';

export const SIDEBAR_ID = 'ai-feedback/ai-feedback-sidebar';

const INTERFACE_STORE = 'core/interface';
const INTERFACE_SCOPE = 'core';

/**
 * Get the shortcut definitions.
 *
 * The "access" modifier (Shift+Alt, or Ctrl+Option on a Mac) is what core
 * uses for editor-level shortcuts, and these letters are unused by core.
 *
 * @return {Array<Object>} Shortcut definitions.
 */
export function getShortcuts() {
	return [
		{
			name: 'ai-feedback/review',
			category: 'global',
			description: __('Review the document with AI', 'ai-feedback'),
			keyCombination: { modifier: 'access', character: 'r' },
		},
		{
			name: 'ai-feedback/toggle-sidebar',
			category: 'global',
			description: __(
				'Show or hide the AI Feedback sidebar',
				'ai-feedback'
			),
			keyCombination: { modifier: 'access', character: 'f' },
		},
		{
			name: 'ai-feedback/next-feedback',
			category: 'global',
			description: __(
				'Go to the next block with AI feedback',
				'ai-feedback'
			),
			keyCombination: { modifier: 'access', character: 'j' },
		},
		{
			name: 'ai-feedback/previous-feedback',
			category: 'global',
			description: __(
				'Go to the previous block with AI feedback',
				'ai-feedback'
			),
			keyCombination: { modifier: 'access', character: 'k' },
		},
	];
}

/**
 * Keyboard shortcuts component. Renders nothing.
 *
 * @return {null} Nothing.
 */
export default function KeyboardShortcuts() {
	const { registerShortcut, unregisterShortcut } = useDispatch(
		keyboardShortcutsStore
	);
	const { enableComplementaryArea, disableComplementaryArea } =
		useDispatch(INTERFACE_STORE);
	const { getActiveComplementaryArea } = useSelect(INTERFACE_STORE);
	const { getClientIdsWithDescendants, getSelectedBlockClientId } =
		useSelect(blockEditorStore);
	const { getLastReview } = useSelect(STORE_NAME);
	const { startReview } = useStartReview();

	useEffect(() => {
		const shortcuts = getShortcuts();
		shortcuts.forEach((shortcut) => registerShortcut(shortcut));

		return () => {
			shortcuts.forEach(({ name }) => unregisterShortcut(name));
		};
	}, [registerShortcut, unregisterShortcut]);

	const openSidebar = () => {
		enableComplementaryArea(INTERFACE_SCOPE, SIDEBAR_ID);
	};

	useShortcut('ai-feedback/review', (event) => {
		event.preventDefault();
		// Open the sidebar so the progress and results are visible.
		openSidebar();
		startReview();
	});

	useShortcut('ai-feedback/toggle-sidebar', (event) => {
		event.preventDefault();
		if (getActiveComplementaryArea(INTERFACE_SCOPE) === SIDEBAR_ID) {
			disableComplementaryArea(INTERFACE_SCOPE);
		} else {
			openSidebar();
		}
	});

	const goToFeedback = (direction) => {
		const feedbackIds = getFeedbackBlockIds(getLastReview()?.notes);
		const orderedIds = getClientIdsWithDescendants();
		const target = getAdjacentFeedbackBlock(
			orderedIds,
			feedbackIds,
			getSelectedBlockClientId(),
			direction
		);

		if (!target) {
			speak(__('No blocks with AI feedback.', 'ai-feedback'));
			return;
		}

		navigateToBlock(target);

		const candidates = orderedIds.filter((id) => feedbackIds.has(id));
		speak(
			sprintf(
				/* translators: 1: position of the block, 2: number of blocks with feedback */
				__('Block with AI feedback, %1$d of %2$d.', 'ai-feedback'),
				candidates.indexOf(target) + 1,
				candidates.length
			)
		);
	};

	useShortcut('ai-feedback/next-feedback', (event) => {
		event.preventDefault();
		goToFeedback(1);
	});

	useShortcut('ai-feedback/previous-feedback', (event) => {
		event.preventDefault();
		goToFeedback(-1);
	});

	return null;
}
