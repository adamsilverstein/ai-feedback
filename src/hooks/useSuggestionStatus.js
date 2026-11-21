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
 * Notes by id, and the note holding each edit's suggestion, by the id of the
 * note that proposed the edit. Built once for each list of notes core-data
 * returns, since every feedback item reads it on every store change.
 */
const indexes = new WeakMap();

/**
 * Indexes the loaded notes.
 *
 * A thread's root note may hold an AI reply's edit, named in its payload.
 * The payload is meta an editor can write, so a named owner only counts when
 * it is not itself a thread's root note; the server checks the rest, see
 * Suggestions::get_owner_id().
 *
 * @param {Object[]} notes Comment records from core-data.
 * @return {{byId: Map<number, Object>, holders: Map<number, Object>}} The index.
 */
function getIndex(notes) {
	let index = indexes.get(notes);
	if (!index) {
		const byId = new Map(notes.map((note) => [note.id, note]));
		const holders = new Map();
		for (const note of notes) {
			const ownerId = getSuggestionOwnerId(note);
			if (
				ownerId !== null &&
				(ownerId === note.id || byId.get(ownerId)?.parent !== 0)
			) {
				holders.set(ownerId, note);
			}
		}
		index = { byId, holders };
		indexes.set(notes, index);
	}
	return index;
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
	const { byId, holders } = getIndex(notes);
	const holder = holders.get(Number(item.id));
	if (holder) {
		return getRecordSuggestionStatus(holder);
	}
	// The record says the note is not a suggestion for its own edit.
	return byId.has(Number(item.id)) ? null : (item.suggestion_status ?? null);
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
	const notes = selectNotes(select);
	const record = notes ? getIndex(notes).byId.get(Number(item.id)) : null;
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
