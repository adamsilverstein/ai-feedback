/**
 * Priority helpers shared by the feedback list and feedback items.
 *
 * Thresholds come from PHP (Response_Parser::PRIORITY_*_THRESHOLD) via
 * `aiFeedbackData.priorityThresholds`, so both sides bucket scores the same way.
 */

export const SORT_PRIORITY = 'priority';
export const SORT_DOCUMENT = 'document';
export const SORT_CATEGORY = 'category';

const DEFAULT_THRESHOLDS = { high: 100, medium: 50 };

/**
 * Get the high / medium priority thresholds.
 *
 * @return {{high: number, medium: number}} Score thresholds.
 */
export function getPriorityThresholds() {
	const localized = window.aiFeedbackData?.priorityThresholds || {};
	const high = Number(localized.high);
	const medium = Number(localized.medium);

	return {
		high: Number.isFinite(high) ? high : DEFAULT_THRESHOLDS.high,
		medium: Number.isFinite(medium) ? medium : DEFAULT_THRESHOLDS.medium,
	};
}

/**
 * Bucket a numeric priority score into 'high' | 'medium' | 'low'.
 *
 * @param {number} score Priority score.
 * @return {string} Priority level.
 */
export function getPriorityLevel(score) {
	if (typeof score !== 'number' || Number.isNaN(score)) {
		return 'low';
	}
	const { high, medium } = getPriorityThresholds();
	if (score >= high) {
		return 'high';
	}
	if (score >= medium) {
		return 'medium';
	}
	return 'low';
}

/**
 * Document position for an item. Groups use their earliest member block,
 * matching Response_Parser::get_document_position().
 *
 * @param {Object} item Feedback item.
 * @return {number} Position used for ordering.
 */
export function getDocumentPosition(item) {
	const indexes = Array.isArray(item.block_indexes)
		? item.block_indexes.filter((index) => typeof index === 'number')
		: [];
	if (indexes.length > 0) {
		return Math.min(...indexes);
	}
	if (typeof item.block_index === 'number') {
		return item.block_index;
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
export function sortFeedback(items, sortBy) {
	const sorted = [...items];
	const byPriority = (a, b) => (b.priority ?? 0) - (a.priority ?? 0);
	const byPosition = (a, b) =>
		getDocumentPosition(a) - getDocumentPosition(b);

	switch (sortBy) {
		case SORT_DOCUMENT:
			sorted.sort(byPosition);
			break;
		case SORT_CATEGORY:
			sorted.sort((a, b) => {
				const ca = a.category || '';
				const cb = b.category || '';
				if (ca !== cb) {
					return ca.localeCompare(cb);
				}
				return byPriority(a, b);
			});
			break;
		case SORT_PRIORITY:
		default:
			sorted.sort((a, b) => byPriority(a, b) || byPosition(a, b));
			break;
	}
	return sorted;
}
