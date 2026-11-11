/**
 * Hook for starting a review from anywhere in the editor.
 */
import { useDispatch, useRegistry } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as noticesStore } from '@wordpress/notices';
import { speak } from '@wordpress/a11y';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store';
import { extractBlockData } from '../utils/block-utils';

/**
 * Get a callback that starts a review of the current post.
 *
 * Store values are read when the callback runs, so the hook doesn't re-render
 * its component on every editor change.
 *
 * @return {{ startReview: Function }} Review starter.
 */
export default function useStartReview() {
	const registry = useRegistry();
	const { startReview } = useDispatch(STORE_NAME);
	const { createWarningNotice } = useDispatch(noticesStore);

	const start = async () => {
		const feedback = registry.select(STORE_NAME);

		if (feedback.isReviewing()) {
			speak(__('A review is already in progress.', 'ai-feedback'));
			return;
		}

		const editor = registry.select(editorStore);

		const postId = editor.getCurrentPostId();
		if (
			!postId ||
			editor.getEditedPostAttribute('status') === 'auto-draft'
		) {
			createWarningNotice(
				__('Save your post first to enable review.', 'ai-feedback'),
				{ type: 'snackbar' }
			);
			return;
		}

		const blocks = extractBlockData(
			registry.select(blockEditorStore).getBlocks()
		);

		if (blocks.length === 0) {
			createWarningNotice(
				__('No content blocks found to review.', 'ai-feedback'),
				{ type: 'snackbar' }
			);
			return;
		}

		try {
			await startReview({
				postId,
				title: editor.getEditedPostAttribute('title'),
				blocks,
				model: feedback.getSelectedModel(),
				focusAreas: feedback.getFocusAreas(),
				targetTone: feedback.getTargetTone(),
				locale: feedback.getFeedbackLocale(),
			});
		} catch (error) {
			// The error is already in the store and shown in the sidebar.
		}
	};

	return { startReview: start };
}
