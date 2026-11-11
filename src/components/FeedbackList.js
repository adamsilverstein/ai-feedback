/**
 * Feedback List Component
 *
 * Displays a list of feedback items (grouped and individual) with a
 * user-controlled sort order.
 */
import { __ } from '@wordpress/i18n';
import { useMemo, useState } from '@wordpress/element';
import { SelectControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import FeedbackItem from './FeedbackItem';
import useReplyThreads from '../hooks/useReplyThreads';
import {
	SORT_CATEGORY,
	SORT_DOCUMENT,
	SORT_PRIORITY,
	sortFeedback,
} from '../utils/priority';

/**
 * Feedback list component.
 *
 * @param {Object} props               Component props.
 * @param {Array}  props.feedbackItems Array of feedback items.
 * @return {Element|null} Feedback list component.
 */
export default function FeedbackList({ feedbackItems }) {
	const [sortBy, setSortBy] = useState(SORT_PRIORITY);

	const sortedItems = useMemo(
		() => sortFeedback(feedbackItems || [], sortBy),
		[feedbackItems, sortBy]
	);

	const postId = useSelect(
		(select) => select(editorStore).getCurrentPostId(),
		[]
	);
	const { threads, onReplyComplete } = useReplyThreads(postId);

	if (!feedbackItems || feedbackItems.length === 0) {
		return null;
	}

	return (
		<>
			<div className="ai-feedback-list-controls">
				<SelectControl
					label={__('Sort by', 'ai-feedback')}
					value={sortBy}
					options={[
						{
							label: __('Priority (recommended)', 'ai-feedback'),
							value: SORT_PRIORITY,
						},
						{
							label: __('Document order', 'ai-feedback'),
							value: SORT_DOCUMENT,
						},
						{
							label: __('Category', 'ai-feedback'),
							value: SORT_CATEGORY,
						},
					]}
					onChange={setSortBy}
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
			</div>
			<div
				id="ai-feedback-items"
				className="ai-feedback-list"
				role="list"
				aria-label={__('Feedback items', 'ai-feedback')}
			>
				{sortedItems.map((item, index) => {
					// For grouped items, use the first block_id from block_ids array.
					// For single items, use block_id directly.
					// Fallback to index if neither is available.
					const key =
						item.id ||
						(item.is_group ? item.block_ids?.[0] : item.block_id) ||
						`item-${index}`;

					return (
						<div key={key} role="listitem">
							<FeedbackItem
								item={item}
								thread={threads[item.parent || item.id]}
								onReplyComplete={onReplyComplete}
							/>
						</div>
					);
				})}
			</div>
		</>
	);
}
