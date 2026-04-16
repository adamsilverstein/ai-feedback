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
 * @param {Object} props               Component props.
 * @param {Array}  props.feedbackItems Array of feedback items.
 * @return {Element|null} Feedback list component.
 */
export default function FeedbackList({ feedbackItems }) {
	if (!feedbackItems || feedbackItems.length === 0) {
		return null;
	}

	return (
		<div
			id="ai-feedback-items"
			className="ai-feedback-list"
			role="list"
			aria-label={__('Feedback items', 'ai-feedback')}
		>
			{feedbackItems.map((item, index) => {
				// For grouped items, use the first block_id from block_ids array
				// For single items, use block_id directly
				// Fallback to index if neither is available
				const key =
					item.id ||
					(item.is_group ? item.block_ids?.[0] : item.block_id) ||
					`item-${index}`;

				return (
					<div key={key} role="listitem">
						<FeedbackItem item={item} />
					</div>
				);
			})}
		</div>
	);
}
