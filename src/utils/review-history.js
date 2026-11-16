/**
 * Review history helpers.
 */
import {
	dateI18n,
	getDate,
	getSettings as getDateSettings,
} from '@wordpress/date';
import { __, _n, sprintf } from '@wordpress/i18n';

/*
 * Review timestamps are ISO 8601 with a UTC offset. Older entries are MySQL
 * datetimes in site time, so they go through getDate(), which reads a string
 * without an offset in the site timezone rather than the browser's.
 */

/**
 * Get a review timestamp in milliseconds, for sorting.
 *
 * @param {string} timestamp Review timestamp.
 * @return {number} Milliseconds since the epoch, or 0 without a timestamp.
 */
export function getReviewTime(timestamp) {
	return (timestamp && getDate(timestamp).getTime()) || 0;
}

/**
 * Format a review timestamp for display in the site timezone.
 *
 * @param {string} timestamp Review timestamp.
 * @param {string} format    PHP-style date format. Defaults to the site datetime format.
 * @return {string} Formatted date.
 */
export function formatReviewDate(timestamp, format) {
	if (!timestamp) {
		return '';
	}
	return dateI18n(
		format || getDateSettings().formats.datetime,
		getDate(timestamp)
	);
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
		return __('Same as previous review', 'copydesk-ai');
	}

	if (delta < 0) {
		return sprintf(
			/* translators: %d: number of feedback items */
			_n(
				'%d fewer item than previous review',
				'%d fewer items than previous review',
				-delta,
				'copydesk-ai'
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
			'copydesk-ai'
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
		_n('%d feedback item', '%d feedback items', count, 'copydesk-ai'),
		count
	);
}
