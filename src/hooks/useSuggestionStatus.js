/**
 * useSuggestionStatus hook.
 *
 * Gutenberg records Accept / Reject on the note through core-data, so the
 * note record the editor has loaded holds the current status. An AI reply's
 * edit is suggested on its thread's root note, whose payload names the
 * reply. The status from the review response is only a fallback until the
 * records load.
 */
import { useSelect } from '@wordpress/data';
import { getNotesQuery } from './useReplyThreads';
import { getSuggestionOwnerId } from '../utils/suggestions';

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
 * Reads the notes the editor has loaded for the post.
 *
 * @param {(store: string) => Object} select Registry select.
 * @return {Object[]|null} Comment records, or null before they load.
 */
function selectNotes(select) {
	const postId = select('core/editor').getCurrentPostId();
	return postId
		? select('core').getEntityRecords(
				'root',
				'comment',
				getNotesQuery(postId)
			)
		: null;
}

/**
 * Reads the suggestion status of a note's own edit with a registry's `select`.
 *
 * @param {(store: string) => Object} select Registry select.
 * @param {Object}                    item   Feedback item with `id` and `suggestion_status`.
 * @return {string|null} Suggestion status, or null when the note's edit is not a suggestion.
 */
export function selectSuggestionStatus(select, item) {
	const notes = selectNotes(select);
	if (!notes) {
		return item.suggestion_status ?? null;
	}
	// A thread's root note may hold an AI reply's edit, named in its payload.
	const holder = notes.find(
		(note) => getSuggestionOwnerId(note) === Number(item.id)
	);
	if (holder) {
		return getRecordSuggestionStatus(holder);
	}
	const record = notes.find((note) => note.id === item.id);
	// The record says the note is not a suggestion for its own edit.
	return record ? null : (item.suggestion_status ?? null);
}

/**
 * Reads the status of the suggestion a note holds, whether it is the note's
 * own edit or an AI reply's.
 *
 * @param {(store: string) => Object} select Registry select.
 * @param {Object}                    item   Feedback item with `id` and `suggestion_status`.
 * @return {string|null} Suggestion status, or null when the note holds no suggestion.
 */
export function selectHeldSuggestionStatus(select, item) {
	const record = selectNotes(select)?.find((note) => note.id === item.id);
	return record
		? getRecordSuggestionStatus(record)
		: (item.suggestion_status ?? null);
}

/**
 * @param {Object} item Feedback item with `id` and `suggestion_status`.
 * @return {string|null} Suggestion status, or null when the note is not a suggestion.
 */
export default function useSuggestionStatus(item) {
	return useSelect(
		(select) =>
			selectSuggestionStatus(select, {
				id: item.id,
				suggestion_status: item.suggestion_status,
			}),
		[item.id, item.suggestion_status]
	);
}

/**
 * @param {Object} item Feedback item with `id` and `suggestion_status`.
 * @return {string|null} Status of the suggestion the note holds, its own edit's or an AI reply's.
 */
export function useHeldSuggestionStatus(item) {
	return useSelect(
		(select) =>
			selectHeldSuggestionStatus(select, {
				id: item.id,
				suggestion_status: item.suggestion_status,
			}),
		[item.id, item.suggestion_status]
	);
}
