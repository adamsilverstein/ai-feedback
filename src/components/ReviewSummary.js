/**
 * Review Summary Component
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect } from '@wordpress/element';

/**
 * Review Summary component.
 *
 * @param {Object} props        Component props.
 * @param {Object} props.review Review object.
 * @return {JSX.Element} Review summary component.
 */
export default function ReviewSummary({ review }) {
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
	} = review;

	const hasNotes = notes && notes.length > 0;
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
				{__('Review Summary', 'ai-feedback')}
			</h3>

			{/* AI-generated summary text */}
			{summaryText && (
				<div className="ai-summary-text">
					<p>{summaryText}</p>
				</div>
			)}

			{/* Note count headline */}
			{noteCount !== undefined && (
				<div
					className="summary-headline"
					role="status"
					aria-live="polite"
				>
					<strong>
						{noteCount === 0
							? __('No feedback items', 'ai-feedback')
							: sprintf(
									/* translators: %d: number of feedback items */
									_n(
										'%d feedback item',
										'%d feedback items',
										noteCount,
										'ai-feedback'
									),
									noteCount
								)}
					</strong>
				</div>
			)}

			{/* Screen reader summary stats */}
			{summary && summary.by_severity && (
				<p id="summary-stats" className="screen-reader-text">
					{sprintf(
						/* translators: 1: critical count, 2: important count, 3: suggestion count */
						__(
							'%1$d critical, %2$d important, %3$d suggestions',
							'ai-feedback'
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
					<h4>{__('By Severity', 'ai-feedback')}</h4>
					<ul aria-label={__('Feedback by severity', 'ai-feedback')}>
						{summary.by_severity.critical > 0 && (
							<li className="severity-critical">
								<span
									className="severity-badge severity-critical"
									role="img"
									aria-label={sprintf(
										/* translators: %s: severity level */
										__('Severity level: %s', 'ai-feedback'),
										__('Critical', 'ai-feedback')
									)}
								>
									🔴
								</span>{' '}
								{__('Critical', 'ai-feedback')}:{' '}
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
										__('Severity level: %s', 'ai-feedback'),
										__('Important', 'ai-feedback')
									)}
								>
									🟡
								</span>{' '}
								{__('Important', 'ai-feedback')}:{' '}
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
										__('Severity level: %s', 'ai-feedback'),
										__('Suggestion', 'ai-feedback')
									)}
								>
									🟢
								</span>{' '}
								{__('Suggestion', 'ai-feedback')}:{' '}
								{summary.by_severity.suggestion}
							</li>
						)}
					</ul>
				</div>
			)}

			{summary && summary.by_category && (
				<div className="summary-by-category">
					<h4>{__('By Category', 'ai-feedback')}</h4>
					<ul aria-label={__('Feedback by category', 'ai-feedback')}>
						{Object.entries(summary.by_category)
							.filter(([, count]) => count > 0)
							.map(([category, count], index, array) => {
								const categoryLabel =
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
						{__('Reviewed with:', 'ai-feedback')} {model}
					</em>
				</p>
			)}

			{hasNotes && (
				<div className="notes-info">
					<p className="description">
						{__(
							'Feedback notes have been attached to your content blocks. Look for the note indicators in the editor.',
							'ai-feedback'
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
									'ai-feedback'
								),
								mappedBlockCount
							)}
						</p>
					)}
					{review.notes_error && (
						<p className="error">
							⚠ {__('Note creation warning:', 'ai-feedback')}{' '}
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
							'ai-feedback'
						)}
					</p>
				</div>
			)}
		</section>
	);
}
