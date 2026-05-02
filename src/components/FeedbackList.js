/**
 * Feedback List Component
 *
 * Displays a list of feedback items (grouped and individual) with a
 * user-controlled sort order.
 */
import { __ } from '@wordpress/i18n';
import { useMemo, useState } from '@wordpress/element';
import { SelectControl } from '@wordpress/components';
import FeedbackItem from './FeedbackItem';

const SORT_PRIORITY = 'priority';
const SORT_DOCUMENT = 'document';
const SORT_CATEGORY = 'category';

/**
 * Best-effort document position for an item, handling both single and grouped
 * shapes (groups expose `block_indexes` instead of `block_index`).
 *
 * @param {Object} item Feedback item.
 * @return {number} Position used for ordering.
 */
function getDocumentPosition(item) {
	if (typeof item.block_index === 'number') {
		return item.block_index;
	}
	if (Array.isArray(item.block_indexes) && item.block_indexes.length > 0) {
		return Math.min(...item.block_indexes);
	}
	return Number.MAX_SAFE_INTEGER;
}

/**
 * Sort feedback items by the requested order. Returns a new array.
 *
 * @param {Array}  items  Feedback items.
 * @param {string} sortBy Sort key.
 * @return {Array} Sorted feedback items.
 */
function sortFeedback(items, sortBy) {
	const sorted = [...items];
	switch (sortBy) {
		case SORT_DOCUMENT:
			sorted.sort(
				(a, b) => getDocumentPosition(a) - getDocumentPosition(b)
			);
			break;
		case SORT_CATEGORY:
			sorted.sort((a, b) => {
				const ca = a.category || '';
				const cb = b.category || '';
				if (ca !== cb) {
					return ca.localeCompare(cb);
				}
				return (b.priority || 0) - (a.priority || 0);
			});
			break;
		case SORT_PRIORITY:
		default:
			sorted.sort((a, b) => {
				const pa = a.priority || 0;
				const pb = b.priority || 0;
				if (pa !== pb) {
					return pb - pa;
				}
				return getDocumentPosition(a) - getDocumentPosition(b);
			});
			break;
	}
	return sorted;
}

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
							<FeedbackItem item={item} />
						</div>
					);
				})}
			</div>
		</>
	);
}
