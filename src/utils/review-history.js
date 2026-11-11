/**
 * Review history helpers.
 */
import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Format a stored MySQL timestamp (site time) for display.
 *
 * @param {string} timestamp MySQL datetime string.
 * @return {string} Formatted date.
 */
export function formatReviewDate(timestamp) {
	if (!timestamp) {
		return '';
	}
	const { formats } = getDateSettings();
	return dateI18n(formats.datetime, timestamp);
}

/**
 * Describe how the feedback count changed from the previous review.
 *
 * @param {number}           count         This review's feedback count.
 * @param {number|undefined} previousCount The previous review's count.
 * @return {string} Change description, or an empty string for the oldest review.
 */
export function describeChange(count, previousCount) {
	if (previousCount === undefined) {
		return '';
	}

	const delta = count - previousCount;
	if (delta === 0) {
		return __('Same as previous review', 'ai-feedback');
	}

	if (delta < 0) {
		return sprintf(
			/* translators: %d: number of feedback items */
			_n(
				'%d fewer item than previous review',
				'%d fewer items than previous review',
				-delta,
				'ai-feedback'
			),
			-delta
		);
	}

	return sprintf(
		/* translators: %d: number of feedback items */
		_n(
			'%d more item than previous review',
			'%d more items than previous review',
			delta,
			'ai-feedback'
		),
		delta
	);
}

/**
 * Feedback item count label.
 *
 * @param {number} count Number of feedback items.
 * @return {string} Label such as "3 feedback items".
 */
export function formatNoteCount(count) {
	return sprintf(
		/* translators: %d: number of feedback items */
		_n('%d feedback item', '%d feedback items', count, 'ai-feedback'),
		count
	);
}
