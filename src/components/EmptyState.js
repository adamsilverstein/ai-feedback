/**
 * Empty State Component
 */
import { Icon, Button } from '@wordpress/components';
import { commentContent, pencil, check } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';

/**
 * EmptyState component shown when no reviews exist.
 *
 * @param {Object}   props               Component props.
 * @param {Function} props.onStartReview Function to start a review.
 * @param {boolean}  props.canReview     Whether a review can be started.
 * @param {boolean}  props.hasContent    Whether the post has content.
 * @param {boolean}  props.isSaved       Whether the post is saved.
 * @return {JSX.Element} EmptyState component.
 */
export default function EmptyState({
	onStartReview,
	canReview,
	hasContent,
	isSaved,
}) {
	return (
		<div className="ai-feedback-empty-state">
			<div className="ai-feedback-empty-icon">
				<Icon icon={commentContent} size={48} />
			</div>

			<h4>{__('No reviews yet', 'ai-feedback')}</h4>

			<p className="ai-feedback-empty-description">
				{__(
					'Get AI-powered feedback on your writing to improve clarity, tone, and structure.',
					'ai-feedback'
				)}
			</p>

			{!canReview ? (
				<div className="ai-feedback-empty-prereq">
					<h5>{__('Before you start:', 'ai-feedback')}</h5>
					<ul>
						{!hasContent && (
							<li>
								<Icon icon={pencil} size={16} />
								{__(
									'Add some content to your post',
									'ai-feedback'
								)}
							</li>
						)}
						{!isSaved && (
							<li>
								<Icon icon={check} size={16} />
								{__('Save your post as a draft', 'ai-feedback')}
							</li>
						)}
					</ul>
				</div>
			) : (
				<Button variant="primary" onClick={onStartReview}>
					{__('Review Document', 'ai-feedback')}
				</Button>
			)}
		</div>
	);
}
