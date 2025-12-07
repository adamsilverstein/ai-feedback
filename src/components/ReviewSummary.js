/**
 * Review Summary Component
 */
import { __ } from '@wordpress/i18n';

/**
 * Review Summary component.
 *
 * @param {Object} props          Component props.
 * @param {Object} props.review   Review object.
 * @return {JSX.Element} Review summary component.
 */
export default function ReviewSummary( { review } ) {
	if ( ! review || ! review.summary ) {
		return null;
	}

	const { summary, notes, model_used: modelUsed } = review;

	return (
		<div className="ai-feedback-review-summary">
			<div className="summary-stats">
				<div className="stat">
					<strong>{ summary.total_notes || 0 }</strong>
					<span>
						{ __( 'Total Feedback Items', 'ai-feedback' ) }
					</span>
				</div>
			</div>

			{ summary.by_severity && (
				<div className="summary-by-severity">
					<h4>{ __( 'By Severity', 'ai-feedback' ) }</h4>
					<ul>
						{ summary.by_severity.critical > 0 && (
							<li>
								🔴{ ' ' }
								{ __( 'Critical', 'ai-feedback' ) }:{ ' ' }
								{ summary.by_severity.critical }
							</li>
						) }
						{ summary.by_severity.important > 0 && (
							<li>
								🟡{ ' ' }
								{ __( 'Important', 'ai-feedback' ) }:{ ' ' }
								{ summary.by_severity.important }
							</li>
						) }
						{ summary.by_severity.suggestion > 0 && (
							<li>
								🟢{ ' ' }
								{ __( 'Suggestion', 'ai-feedback' ) }:{ ' ' }
								{ summary.by_severity.suggestion }
							</li>
						) }
					</ul>
				</div>
			) }

			{ summary.by_category && (
				<div className="summary-by-category">
					<h4>{ __( 'By Category', 'ai-feedback' ) }</h4>
					<ul>
						{ Object.entries( summary.by_category ).map(
							( [ category, count ] ) => (
								<li key={ category }>
									{ category.charAt( 0 ).toUpperCase() +
										category.slice( 1 ) }
									: { count }
								</li>
							)
						) }
					</ul>
				</div>
			) }

			{ modelUsed && (
				<p className="model-used">
					<em>
						{ __( 'Reviewed with:', 'ai-feedback' ) } { modelUsed }
					</em>
				</p>
			) }

			{ notes && notes.length > 0 && (
				<div className="notes-info">
					<p className="description">
						{ __(
							'Feedback notes have been attached to your content blocks. Look for the note indicators in the editor.',
							'ai-feedback'
						) }
					</p>
					{ review.note_ids && review.note_ids.length > 0 && (
						<p className="success">
							✓{ ' ' }
							{ __( 'Created', 'ai-feedback' ) }{ ' ' }
							{ review.note_ids.length }{ ' ' }
							{ __( 'WordPress Notes', 'ai-feedback' ) }
						</p>
					) }
					{ review.notes_error && (
						<p className="error">
							⚠{ ' ' }
							{ __(
								'Note creation warning:',
								'ai-feedback'
							) }{ ' ' }
							{ review.notes_error }
						</p>
					) }
				</div>
			) }
		</div>
	);
}
