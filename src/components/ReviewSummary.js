/**
 * Review Summary Component
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import FeedbackList from './FeedbackList';
import ModelName from './ModelName';
import ReviewStrengths from './ReviewStrengths';
import { FeedbackDone, FeedbackProgressRow } from './FeedbackProgress';
import { describeComparison } from '../utils/progress';

/**
 * Labels for the feedback categories, built at render time so translations apply.
 *
 * @return {Object} Labels keyed by category.
 */
const getCategoryLabels = () => ({
	content: __('Content', 'fresh-eyes'),
	tone: __('Tone', 'fresh-eyes'),
	flow: __('Flow', 'fresh-eyes'),
	design: __('Design', 'fresh-eyes'),
	grammar: __('Grammar', 'fresh-eyes'),
});

/**
 * Review Summary component.
 *
 * @param {Object} props            Component props.
 * @param {Object} props.review     Review object.
 * @param {Object} [props.progress] `{ addressed, total }` for the review's notes.
 * @return {Element} Review summary component.
 */
export default function ReviewSummary({ review, progress = null }) {
	const [showAddressed, setShowAddressed] = useState(false);

	// Debug logging when review changes
	useEffect(() => {
		if (review) {
			// eslint-disable-next-line no-console
			console.log('[AI-Feedback] ReviewSummary received review:', {
				reviewId: review.review_id,
				hasSummary: !!review.summary,
				hasSummaryText: !!review.summary_text,
				summaryTextLength: review.summary_text?.length || 0,
				notesCount: review.notes?.length || 0,
				noteCount: review.note_count,
				model: review.model,
				blockMappingKeys: review.block_mapping
					? Object.keys(review.block_mapping)
					: [],
			});
		}
	}, [review]);

	if (!review) {
		// eslint-disable-next-line no-console
		console.log('[AI-Feedback] ReviewSummary: No review data');
		return null;
	}

	const {
		summary,
		summary_text: summaryText,
		notes,
		note_count: noteCount,
		model,
		block_mapping: blockMapping,
		persona_label: personaLabel,
	} = review;
	const personaTakeaway = personaLabel ? review.persona_takeaway : '';

	const hasNotes = notes && notes.length > 0;
	const comparison = describeComparison(review.progress, noteCount ?? 0);
	const showProgress =
		hasNotes && !review.is_in_progress && progress?.total > 0;
	const isDone = showProgress && progress.addressed >= progress.total;

	// Once a note is restored, addressing it again shows the done state again.
	if (!isDone && showAddressed) {
		setShowAddressed(false);
	}

	const mappedBlockCount = blockMapping
		? Object.keys(blockMapping).length
		: 0;

	// Calculate severity counts for screen reader summary
	const criticalCount = summary?.by_severity?.critical || 0;
	const importantCount = summary?.by_severity?.important || 0;
	const suggestionCount = summary?.by_severity?.suggestion || 0;

	return (
		<section
			className="ai-feedback-review-summary"
			aria-labelledby="review-summary-heading"
		>
			<h3 id="review-summary-heading" className="screen-reader-text">
				{__('Review Summary', 'fresh-eyes')}
			</h3>

			{review.is_in_progress && (
				<p className="ai-feedback-review-status description">
					{__(
						'Review in progress. Showing feedback from the parts finished so far.',
						'fresh-eyes'
					)}
				</p>
			)}
			{review.is_partial && (
				<p className="ai-feedback-review-status description">
					{__(
						'This review did not finish. Showing feedback from the parts that finished before it stopped.',
						'fresh-eyes'
					)}
				</p>
			)}

			{/* AI-generated summary text */}
			{(summaryText || personaTakeaway) && (
				<div className="ai-summary-text">
					{personaTakeaway && (
						<p className="ai-feedback-persona-takeaway">
							<strong>
								{sprintf(
									/* translators: %s: reader persona, such as "Busy skimmer". */
									__('%s:', 'fresh-eyes'),
									personaLabel
								)}
							</strong>{' '}
							{personaTakeaway}
						</p>
					)}
					{summaryText && <p>{summaryText}</p>}
				</div>
			)}

			<ReviewStrengths strengths={review.strengths} />

			{/* Note count headline */}
			{noteCount !== undefined && (
				<div
					className="summary-headline"
					role="status"
					aria-live="polite"
				>
					<strong>
						{noteCount === 0
							? __('No feedback items', 'fresh-eyes')
							: sprintf(
									/* translators: %d: number of feedback items */
									_n(
										'%d feedback item',
										'%d feedback items',
										noteCount,
										'fresh-eyes'
									),
									noteCount
								)}
					</strong>
				</div>
			)}

			{comparison && (
				<p className="ai-feedback-comparison">{comparison}</p>
			)}

			{/* Screen reader summary stats */}
			{summary && summary.by_severity && (
				<p id="summary-stats" className="screen-reader-text">
					{sprintf(
						/* translators: 1: critical count, 2: important count, 3: suggestion count */
						__(
							'%1$d critical, %2$d important, %3$d suggestions',
							'fresh-eyes'
						),
						criticalCount,
						importantCount,
						suggestionCount
					)}
				</p>
			)}

			{/* Statistical breakdown */}
			{summary && summary.by_severity && (
				<div
					className="summary-by-severity"
					aria-describedby="summary-stats"
				>
					<h4>{__('By Severity', 'fresh-eyes')}</h4>
					<ul aria-label={__('Feedback by severity', 'fresh-eyes')}>
						{summary.by_severity.critical > 0 && (
							<li className="severity-critical">
								<span
									className="severity-badge severity-critical"
									role="img"
									aria-label={sprintf(
										/* translators: %s: severity level */
										__('Severity level: %s', 'fresh-eyes'),
										__('Critical', 'fresh-eyes')
									)}
								>
									🔴
								</span>{' '}
								{__('Critical', 'fresh-eyes')}:{' '}
								{summary.by_severity.critical}
							</li>
						)}
						{summary.by_severity.important > 0 && (
							<li className="severity-important">
								<span
									className="severity-badge severity-important"
									role="img"
									aria-label={sprintf(
										/* translators: %s: severity level */
										__('Severity level: %s', 'fresh-eyes'),
										__('Important', 'fresh-eyes')
									)}
								>
									🟡
								</span>{' '}
								{__('Important', 'fresh-eyes')}:{' '}
								{summary.by_severity.important}
							</li>
						)}
						{summary.by_severity.suggestion > 0 && (
							<li className="severity-suggestion">
								<span
									className="severity-badge severity-suggestion"
									role="img"
									aria-label={sprintf(
										/* translators: %s: severity level */
										__('Severity level: %s', 'fresh-eyes'),
										__('Suggestion', 'fresh-eyes')
									)}
								>
									🟢
								</span>{' '}
								{__('Suggestion', 'fresh-eyes')}:{' '}
								{summary.by_severity.suggestion}
							</li>
						)}
					</ul>
				</div>
			)}

			{summary && summary.by_category && (
				<div className="summary-by-category">
					<h4>{__('By Category', 'fresh-eyes')}</h4>
					<ul aria-label={__('Feedback by category', 'fresh-eyes')}>
						{Object.entries(summary.by_category)
							.filter(([, count]) => count > 0)
							.map(([category, count], index, array) => {
								const categoryLabel =
									getCategoryLabels()[category] ||
									category.charAt(0).toUpperCase() +
										category.slice(1);
								return (
									<li
										key={category}
										aria-setsize={array.length}
										aria-posinset={index + 1}
									>
										<span className="category-badge">
											{categoryLabel}
										</span>
										: {count}
									</li>
								);
							})}
					</ul>
				</div>
			)}

			{model && (
				<p className="model-used">
					<em>
						{__('Reviewed with:', 'fresh-eyes')}{' '}
						<ModelName id={model} />
					</em>
				</p>
			)}

			{hasNotes && (
				<div className="notes-info">
					<p className="description">
						{__(
							'Feedback notes have been attached to your content blocks. Look for the note indicators in the editor.',
							'fresh-eyes'
						)}
					</p>
					{mappedBlockCount > 0 && (
						<p className="success">
							✓{' '}
							{sprintf(
								/* translators: %d: number of blocks */
								_n(
									'Created notes for %d block',
									'Created notes for %d blocks',
									mappedBlockCount,
									'fresh-eyes'
								),
								mappedBlockCount
							)}
						</p>
					)}
					{review.notes_error && (
						<p className="error">
							⚠ {__('Note creation warning:', 'fresh-eyes')}{' '}
							{review.notes_error}
						</p>
					)}
				</div>
			)}

			{!hasNotes && noteCount === 0 && (
				<div className="notes-info">
					<p className="success">
						✓{' '}
						{__(
							'Great job! The AI found no issues with your content.',
							'fresh-eyes'
						)}
					</p>
				</div>
			)}

			{/* The done state says the same, so the row gives way to it. */}
			{showProgress && (!isDone || showAddressed) && (
				<FeedbackProgressRow progress={progress} />
			)}

			{isDone && !showAddressed && (
				<FeedbackDone
					progress={progress}
					onShowFeedback={() => setShowAddressed(true)}
				/>
			)}

			{/* Detailed feedback list */}
			{hasNotes && (!isDone || showAddressed) && (
				<div className="feedback-details">
					<h4>{__('Feedback Details', 'fresh-eyes')}</h4>
					<FeedbackList feedbackItems={notes} />
				</div>
			)}
		</section>
	);
}
