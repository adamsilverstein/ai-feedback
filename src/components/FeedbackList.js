/**
 * Feedback List Component
 *
 * Displays a list of feedback items (grouped and individual).
 */
import { __ } from '@wordpress/i18n';
import FeedbackItem from './FeedbackItem';

/**
 * Feedback list component.
 *
 * @param {Object} props              Component props.
 * @param {Array}  props.feedbackItems Array of feedback items.
 * @return {JSX.Element|null} Feedback list component.
 */
export default function FeedbackList({ feedbackItems }) {
	if (!feedbackItems || feedbackItems.length === 0) {
		return null;
	}

	return (
		<div
			className="ai-feedback-list"
			role="list"
			aria-label={__('Feedback items', 'ai-feedback')}
		>
			{feedbackItems.map((item, index) => (
				<div key={item.block_id || `item-${index}`} role="listitem">
					<FeedbackItem item={item} />
				</div>
			))}
		</div>
	);
}
