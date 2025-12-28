/**
 * AI Feedback Panel Component
 */
import { PanelBody, Notice, Button } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store';
import { hasTextContent, extractBlockData } from '../utils/block-utils';

import ModelSelector from './ModelSelector';
import SettingsPanel from './SettingsPanel';
import ReviewButton from './ReviewButton';
import ReviewSummary from './ReviewSummary';
import EmptyState from './EmptyState';

/**
 * Settings page URL.
 */
const SETTINGS_PAGE_URL = '/wp-admin/admin.php?page=ai-feedback-settings';

/**
 * Get action button for specific error types.
 *
 * @param {Object} error Error object with code, message, and data.
 * @return {JSX.Element|null} Action button or null.
 */
function getErrorAction(error) {
	// Check if error is related to API credits or billing.
	// Note: We use string matching on error messages because the PHP AI Client
	// wraps all AI provider errors under the same 'ai_request_failed' code.
	// Credit/billing errors from providers (Anthropic, OpenAI) typically include
	// these keywords in a stable format.
	if (
		error.code === 'ai_request_failed' &&
		typeof error.message === 'string' &&
		(error.message.toLowerCase().includes('credit') ||
			error.message.toLowerCase().includes('billing'))
	) {
		return (
			<Button
				variant="link"
				href={SETTINGS_PAGE_URL}
				target="_blank"
				rel="noopener noreferrer"
				className="ai-feedback-error-action"
			>
				{__('Go to Settings', 'ai-feedback')}
			</Button>
		);
	}

	// Check for rate limit errors
	if (error.code === 'rate_limit_exceeded') {
		return (
			<p className="ai-feedback-error-help">
				{__(
					'Please wait before making another request.',
					'ai-feedback'
				)}
			</p>
		);
	}

	return null;
}

/**
 * AI Feedback Panel component.
 *
 * @return {JSX.Element} Panel component.
 */
export default function AIFeedbackPanel() {
	const {
		error,
		lastReview,
		isLoadingSettings,
		postId,
		editorBlocks,
		isReviewing,
		selectedModel,
		focusAreas,
		targetTone,
		postTitle,
	} = useSelect(
		(select) => ({
			error: select(STORE_NAME).getError(),
			lastReview: select(STORE_NAME).getLastReview(),
			isLoadingSettings: select(STORE_NAME).isLoadingSettings(),
			postId: select(editorStore).getCurrentPostId(),
			editorBlocks: select(blockEditorStore).getBlocks(),
			isReviewing: select(STORE_NAME).isReviewing(),
			selectedModel: select(STORE_NAME).getSelectedModel(),
			focusAreas: select(STORE_NAME).getFocusAreas(),
			targetTone: select(STORE_NAME).getTargetTone(),
			postTitle: select(editorStore).getEditedPostAttribute('title'),
		}),
		[]
	);

	const { clearError, startReview } = useDispatch(STORE_NAME);

	// Check if post has content (any text blocks)
	const hasContent = hasTextContent(editorBlocks);

	const isSaved = !!postId;
	const canReview = isSaved && hasContent;

	/**
	 * Handle starting a review from empty state.
	 */
	const handleStartReview = async () => {
		if (!canReview) {
			return;
		}

		const blocks = extractBlockData(editorBlocks);

		try {
			await startReview({
				postId,
				title: postTitle,
				blocks,
				model: selectedModel,
				focusAreas,
				targetTone,
			});
		} catch (reviewError) {
			// Error is already in the store
			// eslint-disable-next-line no-console
			console.error('Review failed:', reviewError);
		}
	};

	if (isLoadingSettings) {
		return (
			<div className="ai-feedback-panel">
				<PanelBody>
					<p>{__('Loading…', 'ai-feedback')}</p>
				</PanelBody>
			</div>
		);
	}

	return (
		<div className="ai-feedback-panel">
			{error && (
				<Notice
					status="error"
					isDismissible={true}
					onRemove={clearError}
					className="ai-feedback-error-notice"
				>
					<div className="ai-feedback-error-content">
						<div className="ai-feedback-error-message">
							{error.message}
						</div>
						{error.code && (
							<div className="ai-feedback-error-code">
								{__('Error code:', 'ai-feedback')} {error.code}
							</div>
						)}
						{getErrorAction(error)}
					</div>
				</Notice>
			)}

			{!lastReview && !isReviewing ? (
				<EmptyState
					onStartReview={handleStartReview}
					canReview={canReview}
					hasContent={hasContent}
					isSaved={isSaved}
				/>
			) : (
				<>
					<PanelBody
						title={__('Review Settings', 'ai-feedback')}
						initialOpen={true}
					>
						<ModelSelector />
						<SettingsPanel />
					</PanelBody>

					<PanelBody
						title={__('Review Document', 'ai-feedback')}
						initialOpen={true}
					>
						<ReviewButton />
					</PanelBody>

					{lastReview && (
						<PanelBody
							title={__('Last Review', 'ai-feedback')}
							initialOpen={true}
						>
							<ReviewSummary review={lastReview} />
						</PanelBody>
					)}
				</>
			)}
		</div>
	);
}
