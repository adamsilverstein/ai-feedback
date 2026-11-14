/**
 * AI Feedback Panel Component
 */
import { PanelBody } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store';
import { hasTextContent, extractBlockData } from '../utils/block-utils';
import { isPostSaved } from '../utils/post-status';

import ModelSelector from './ModelSelector';
import LanguageSelector from './LanguageSelector';
import FocusAreaSelector from './FocusAreaSelector';
import ToneSelector from './ToneSelector';
import ReviewButton from './ReviewButton';
import ReviewSummary from './ReviewSummary';
import ExportReview from './ExportReview';
import ReviewHistory from './ReviewHistory';
import EmptyState from './EmptyState';
import StatusAnnouncer from './StatusAnnouncer';
import ErrorNotice from './ErrorNotice';
import WelcomeModal from './WelcomeModal';
import TipNotice from './TipNotice';
import SkipLinks from './SkipLinks';

const REVIEW_BUTTON_ID = 'ai-feedback-review-button';
const RESULTS_ID = 'ai-feedback-results';
const ERROR_ID = 'ai-feedback-error';

/**
 * AI Feedback Panel component.
 *
 * @return {Element} Panel component.
 */
export default function AIFeedbackPanel() {
	const {
		error,
		lastReview,
		isLoadingSettings,
		postId,
		postStatus,
		editorBlocks,
		isReviewing,
		selectedModel,
		focusAreas,
		targetTone,
		feedbackLocale,
		postTitle,
		availableModels,
		unavailableRetries,
		chunkSize,
	} = useSelect((select) => {
		// Settings are loaded by the getSettings resolver, so select them to
		// trigger the fetch of available models and locales.
		select(STORE_NAME).getSettings();

		return {
			error: select(STORE_NAME).getError(),
			lastReview: select(STORE_NAME).getLastReview(),
			isLoadingSettings: select(STORE_NAME).isLoadingSettings(),
			postId: select(editorStore).getCurrentPostId(),
			postStatus: select(editorStore).getEditedPostAttribute('status'),
			editorBlocks: select(blockEditorStore).getBlocks(),
			isReviewing: select(STORE_NAME).isReviewing(),
			selectedModel: select(STORE_NAME).getSelectedModel(),
			focusAreas: select(STORE_NAME).getFocusAreas(),
			targetTone: select(STORE_NAME).getTargetTone(),
			feedbackLocale: select(STORE_NAME).getFeedbackLocale(),
			postTitle: select(editorStore).getEditedPostAttribute('title'),
			availableModels: select(STORE_NAME).getAvailableModels(),
			unavailableRetries: select(STORE_NAME).getUnavailableRetries(),
			chunkSize: select(STORE_NAME).getChunkSize(),
		};
	}, []);

	const { clearError, startReview } = useDispatch(STORE_NAME);

	// The settings sit above the progress and the results, so bring each
	// into view when a review starts and when it finishes.
	const wasReviewing = useRef(isReviewing);
	useEffect(() => {
		if (wasReviewing.current === isReviewing) {
			return;
		}
		wasReviewing.current = isReviewing;

		// A failed review keeps the previous results, so show the error
		// notice above them instead.
		let targetId = REVIEW_BUTTON_ID;
		if (!isReviewing) {
			targetId = error ? ERROR_ID : RESULTS_ID;
		}
		if (!isReviewing && !error && !lastReview) {
			return;
		}
		const reduceMotion = window.matchMedia?.(
			'(prefers-reduced-motion: reduce)'
		)?.matches;
		document.getElementById(targetId)?.scrollIntoView?.({
			behavior: reduceMotion ? 'auto' : 'smooth',
			block: 'start',
		});
	}, [isReviewing, lastReview, error]);

	// Check if post has content (any text blocks)
	const hasContent = hasTextContent(editorBlocks);

	const isSaved = isPostSaved(postId, postStatus);
	const canReview = isSaved && hasContent;

	/**
	 * Handle starting a review from empty state or a retry.
	 *
	 * @param {Object}  options         Options.
	 * @param {boolean} options.isRetry Whether this retries a review that failed because the AI service was unavailable.
	 * @return {Promise<boolean|undefined>} False when the review could not start.
	 */
	const handleStartReview = async ({ isRetry = false } = {}) => {
		if (!canReview) {
			return false;
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
				locale: feedbackLocale,
				chunkSize,
				isRetry,
			});
		} catch {
			// Error is already in the store, no additional action needed
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

	const showModelSelector = availableModels.length > 1;
	const hasReviewContent = !!lastReview || isReviewing;
	const hasFeedbackItems =
		!!lastReview && Array.isArray(lastReview.notes)
			? lastReview.notes.length > 0
			: false;

	return (
		<div className="ai-feedback-panel">
			<SkipLinks
				hasResults={!!lastReview}
				showModel={showModelSelector}
				hasSettings={hasReviewContent}
				hasFeedbackItems={hasFeedbackItems}
			/>
			<WelcomeModal />
			<StatusAnnouncer
				isReviewing={isReviewing}
				lastReview={lastReview}
				error={error}
			/>
			{error && (
				<div id={ERROR_ID}>
					<ErrorNotice
						error={error}
						onRemove={clearError}
						retries={unavailableRetries}
						onRetry={() => handleStartReview({ isRetry: true })}
					/>
				</div>
			)}

			{/* EmptyState and the review panel are mutually exclusive render
			    paths, so the REVIEW_BUTTON_ID below is in the DOM exactly
			    once at a time and always wraps the actionable button. */}
			{!lastReview && !isReviewing ? (
				<EmptyState
					onStartReview={() => handleStartReview()}
					canReview={canReview}
					hasContent={hasContent}
					isSaved={isSaved}
					reviewButtonId={REVIEW_BUTTON_ID}
				/>
			) : (
				<>
					{/* ID used by skip links for keyboard navigation */}
					<div id="ai-feedback-settings">
						<PanelBody
							title={__('Review Settings', 'ai-feedback')}
							initialOpen={true}
						>
							{showModelSelector && (
								/* ID used by skip links for keyboard navigation */
								<div id="ai-feedback-model-select">
									<ModelSelector />
								</div>
							)}
							<LanguageSelector />
							<ToneSelector />
							<FocusAreaSelector />
						</PanelBody>
					</div>

					<PanelBody
						title={__('Review Document', 'ai-feedback')}
						initialOpen={true}
					>
						{/* ID used by skip links for keyboard navigation */}
						<div id={REVIEW_BUTTON_ID}>
							<ReviewButton />
						</div>
					</PanelBody>

					{lastReview && (
						<PanelBody
							// Remount per review so new results open even
							// after the panel was closed.
							key={lastReview.review_id}
							title={__('Last Review', 'ai-feedback')}
							initialOpen={true}
						>
							{!isReviewing && <TipNotice />}
							{/* ID used by skip links for keyboard navigation */}
							<div id={RESULTS_ID}>
								<div className="ai-feedback-export-row">
									<ExportReview review={lastReview} />
								</div>
								<ReviewSummary review={lastReview} />
							</div>
						</PanelBody>
					)}
				</>
			)}

			<ReviewHistory />
		</div>
	);
}
