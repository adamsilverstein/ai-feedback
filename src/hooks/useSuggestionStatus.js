/**
 * useSuggestionStatus hook.
 *
 * Gutenberg records Accept / Reject on the note through core-data, so the
 * note record the editor has loaded holds the current status. The status
 * from the review response is only a fallback until that record loads.
 */
import { useSelect } from '@wordpress/data';
import { getNotesQuery } from './useReplyThreads';

/**
 * Reads the suggestion status from a note's comment record.
 *
 * @param {Object} record Comment record from core-data.
 * @return {string|null} Status, or null when the note is not a suggestion.
 */
export function getRecordSuggestionStatus(record) {
	if (!record?.meta?._wp_suggestion) {
		return null;
	}
	return record.meta._wp_suggestion_status || 'pending';
}

/**
 * @param {Object} item Feedback item with `id` and `suggestion_status`.
 * @return {string|null} Suggestion status, or null when the note is not a suggestion.
 */
export default function useSuggestionStatus(item) {
	return useSelect(
		(select) => {
			const postId = select('core/editor').getCurrentPostId();
			const notes = postId
				? select('core').getEntityRecords(
						'root',
						'comment',
						getNotesQuery(postId)
					)
				: null;
			const record = notes?.find((note) => note.id === item.id);
			return record
				? getRecordSuggestionStatus(record)
				: (item.suggestion_status ?? null);
		},
		[item.id, item.suggestion_status]
	);
}
