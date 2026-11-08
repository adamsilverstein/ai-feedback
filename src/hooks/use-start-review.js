/**
 * Hook for starting a review from anywhere in the editor.
 */
import { useSelect, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as noticesStore } from '@wordpress/notices';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store';
import { extractBlockData } from '../utils/block-utils';

/**
 * Get a callback that starts a review of the current post.
 *
 * @return {{ startReview: Function, canStartReview: boolean }} Review starter.
 */
export default function useStartReview() {
	const {
		postId,
		postStatus,
		postTitle,
		isReviewing,
		selectedModel,
		focusAreas,
		targetTone,
		feedbackLocale,
	} = useSelect(
		(select) => ({
			postId: select(editorStore).getCurrentPostId(),
			postStatus: select(editorStore).getEditedPostAttribute('status'),
			postTitle: select(editorStore).getEditedPostAttribute('title'),
			isReviewing: select(STORE_NAME).isReviewing(),
			selectedModel: select(STORE_NAME).getSelectedModel(),
			focusAreas: select(STORE_NAME).getFocusAreas(),
			targetTone: select(STORE_NAME).getTargetTone(),
			feedbackLocale: select(STORE_NAME).getFeedbackLocale(),
		}),
		[]
	);

	const { startReview } = useDispatch(STORE_NAME);
	const { createWarningNotice } = useDispatch(noticesStore);
	const { getBlocks } = useSelect(blockEditorStore);

	const canStartReview =
		!!postId && postStatus !== 'auto-draft' && !isReviewing;

	const start = async () => {
		if (!canStartReview) {
			if (!isReviewing) {
				createWarningNotice(
					__('Save your post first to enable review.', 'ai-feedback'),
					{ type: 'snackbar' }
				);
			}
			return;
		}

		const blocks = extractBlockData(getBlocks());

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
				title: postTitle,
				blocks,
				model: selectedModel,
				focusAreas,
				targetTone,
				locale: feedbackLocale,
			});
		} catch (error) {
			// The error is already in the store and shown in the sidebar.
		}
	};

	return { startReview: start, canStartReview };
}
