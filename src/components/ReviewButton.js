/**
 * Review Button Component
 */
import { Button } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { serialize } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store';

/**
 * Indeterminate Progress Bar component.
 * Shows an animated progress bar that moves back and forth.
 *
 * @return {JSX.Element} Progress bar component.
 */
function IndeterminateProgressBar() {
	return (
		<div className="ai-feedback-progress-container">
			<div className="ai-feedback-progress-bar">
				<div className="ai-feedback-progress-indicator" />
			</div>
		</div>
	);
}

/**
 * Extract text content from a block's innerHTML.
 *
 * @param {string} innerHTML The block's innerHTML.
 * @return {string} Plain text content.
 */
function extractTextContent(innerHTML) {
	if (!innerHTML) {
		return '';
	}
	// Create a temporary element to strip HTML tags
	const temp = document.createElement('div');
	temp.innerHTML = innerHTML;
	return temp.textContent || temp.innerText || '';
}

/**
 * Recursively extract blocks with their clientIds and content.
 *
 * @param {Array} blocks Array of blocks from the editor.
 * @return {Array} Simplified block data for API.
 */
function extractBlockData(blocks) {
	const result = [];

	for (const block of blocks) {
		// Get the serialized content for this block.
		// Use originalContent if available (parsed from existing HTML),
		// otherwise serialize the current block state.
		const rawContent = block.originalContent || serialize(block);
		const content = extractTextContent(rawContent);

		// Only include blocks with actual content
		if (content.trim()) {
			result.push({
				clientId: block.clientId,
				name: block.name,
				content: content.trim(),
			});
		}

		// Recursively process inner blocks
		if (block.innerBlocks && block.innerBlocks.length > 0) {
			result.push(...extractBlockData(block.innerBlocks));
		}
	}

	return result;
}

/**
 * Review Button component.
 *
 * @return {JSX.Element} Review button component.
 */
export default function ReviewButton() {
	const {
		postId,
		postTitle,
		editorBlocks,
		isReviewing,
		selectedModel,
		focusAreas,
		targetTone,
	} = useSelect(
		(select) => ({
			postId: select(editorStore).getCurrentPostId(),
			postTitle: select(editorStore).getEditedPostAttribute('title'),
			editorBlocks: select(blockEditorStore).getBlocks(),
			isReviewing: select(STORE_NAME).isReviewing(),
			selectedModel: select(STORE_NAME).getSelectedModel(),
			focusAreas: select(STORE_NAME).getFocusAreas(),
			targetTone: select(STORE_NAME).getTargetTone(),
		}),
		[]
	);

	const { startReview } = useDispatch(STORE_NAME);

	const handleReview = async () => {
		if (!postId) {
			return;
		}

		// Extract block data with clientIds
		const blocks = extractBlockData(editorBlocks);

		if (blocks.length === 0) {
			// eslint-disable-next-line no-console
			console.warn('No content blocks found to review');
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
			});
		} catch (error) {
			// Error is already in the store
			// eslint-disable-next-line no-console
			console.error('Review failed:', error);
		}
	};

	const isDisabled = !postId || isReviewing;

	return (
		<div className="ai-feedback-review-button">
			<Button
				variant="primary"
				onClick={handleReview}
				disabled={isDisabled}
				isBusy={isReviewing}
			>
				{isReviewing
					? __('Reviewing…', 'ai-feedback')
					: __('Review Document', 'ai-feedback')}
			</Button>
			{!postId && (
				<p className="description">
					{__('Save your post first to enable review', 'ai-feedback')}
				</p>
			)}
			{isReviewing && (
				<>
					<IndeterminateProgressBar />
					<p className="description">
						{__('AI is analyzing your content…', 'ai-feedback')}
					</p>
				</>
			)}
		</div>
	);
}
