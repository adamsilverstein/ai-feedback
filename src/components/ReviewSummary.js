/**
 * Review Summary Component
 */
import { __ } from '@wordpress/i18n';

/**
 * Review Summary component.
 *
 * @param {Object} props        Component props.
 * @param {Object} props.review Review object.
 * @return {JSX.Element} Review summary component.
 */
export default function ReviewSummary({ review }) {
	if (!review) {
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
	const mappedBlockCount = blockMapping ? Object.keys(blockMapping).length : 0;

	return (
		<div className="ai-feedback-review-summary">
			{/* AI-generated summary text */}
			{summaryText && (
				<div className="ai-summary-text">
					<p>{summaryText}</p>
				</div>
			)}

			{/* Note count headline */}
			{noteCount !== undefined && (
				<div className="summary-headline">
					<strong>
						{noteCount === 0
							? __('No feedback items', 'ai-feedback')
							: noteCount === 1
								? __('1 feedback item', 'ai-feedback')
								: `${noteCount} ${__('feedback items', 'ai-feedback')}`}
					</strong>
				</div>
			)}

			{/* Statistical breakdown */}
			{summary && summary.by_severity && (
				<div className="summary-by-severity">
					<h4>{__('By Severity', 'ai-feedback')}</h4>
					<ul>
						{summary.by_severity.critical > 0 && (
							<li className="severity-critical">
								🔴 {__('Critical', 'ai-feedback')}:{' '}
								{summary.by_severity.critical}
							</li>
						)}
						{summary.by_severity.important > 0 && (
							<li className="severity-important">
								🟡 {__('Important', 'ai-feedback')}:{' '}
								{summary.by_severity.important}
							</li>
						)}
						{summary.by_severity.suggestion > 0 && (
							<li className="severity-suggestion">
								🟢 {__('Suggestion', 'ai-feedback')}:{' '}
								{summary.by_severity.suggestion}
							</li>
						)}
					</ul>
				</div>
			)}

			{summary && summary.by_category && (
				<div className="summary-by-category">
					<h4>{__('By Category', 'ai-feedback')}</h4>
					<ul>
						{Object.entries(summary.by_category)
							.filter(([, count]) => count > 0)
							.map(([category, count]) => (
								<li key={category}>
									{category.charAt(0).toUpperCase() +
										category.slice(1)}
									: {count}
								</li>
							))}
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
							✓ {__('Created notes for', 'ai-feedback')}{' '}
							{mappedBlockCount}{' '}
							{mappedBlockCount === 1
								? __('block', 'ai-feedback')
								: __('blocks', 'ai-feedback')}
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
		</div>
	);
}
