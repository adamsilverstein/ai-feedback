/**
 * Revisions of a thread's suggested edit.
 *
 * A thread's first edit is the review's quick fix on the note, or the first
 * AI reply's edit. Each later AI reply's edit revises it, and only the newest
 * can be applied or accepted: the earlier ones are superseded.
 */
import { __, sprintf } from '@wordpress/i18n';
import { isReplyEdit } from './is-reply-edit';

/**
 * Lists the edits proposed in a thread, oldest first.
 *
 * @param {Object|null} note    The thread's note, with `id`, `parent` and `quick_fix`.
 * @param {Object[]}    replies The thread's replies, oldest first.
 * @return {Array<{ownerId: number, edit: Object}>} Note that owns each edit, and the edit.
 */
export function getThreadEdits(note, replies = []) {
	const edits = [];
	// A secondary note's quick fix is not the thread's first edit.
	if (note?.id && !note.parent && note.quick_fix) {
		edits.push({ ownerId: note.id, edit: note.quick_fix });
	}
	for (const reply of replies || []) {
		if (reply.is_ai && isReplyEdit(reply.edit)) {
			edits.push({ ownerId: reply.id, edit: reply.edit });
		}
	}
	return edits;
}

/**
 * Lists the notes whose edits a newer edit in the thread supersedes.
 *
 * @param {Object|null} note    The thread's note.
 * @param {Object[]}    replies The thread's replies, oldest first.
 * @return {number[]} Owner IDs of every edit but the newest.
 */
export function getSupersededOwnerIds(note, replies) {
	return getThreadEdits(note, replies)
		.slice(0, -1)
		.map(({ ownerId }) => ownerId);
}

/**
 * Finds the newest edit in a thread.
 *
 * @param {Object|null} note    The thread's note.
 * @param {Object[]}    replies The thread's replies, oldest first.
 * @return {{ownerId: number, edit: Object}|null} Newest edit, or null.
 */
export function getLatestEdit(note, replies) {
	const edits = getThreadEdits(note, replies);
	return edits[edits.length - 1] ?? null;
}

/**
 * Labels a revision of a thread's suggested edit.
 *
 * Matches Notes_Manager::revision_label(), which labels it in the Notes UI.
 *
 * @param {number|null} revision Revision number, from 1.
 * @return {string} Label, or '' without a revision.
 */
export function getRevisionLabel(revision) {
	if (!revision) {
		return '';
	}
	if (revision <= 1) {
		return __('Suggested edit', 'fresh-eyes');
	}
	return sprintf(
		/* translators: %d: revision number of a suggested edit, 2 or more. */
		__('Revised suggestion (%d)', 'fresh-eyes'),
		revision
	);
}

/**
 * Suggestion statuses after which an edit is no longer waiting on the author.
 */
const DECIDED_STATUSES = [
	'applied',
	'applied-unsaved',
	'rejected',
	'rejected-unsaved',
	'outdated',
];

/**
 * Whether the newest edit in a thread is still waiting on the author.
 *
 * @param {Object|null} latest          Newest edit, from getLatestEdit().
 * @param {Object}      state           What the editor knows about it.
 * @param {string|null} state.status    Its suggestion status, if it is a suggestion.
 * @param {boolean}     state.isApplied Whether it was applied this session.
 * @return {boolean} True when there is an edit to refine.
 */
export function isEditPending(latest, { status = null, isApplied = false }) {
	return !!latest && !isApplied && !DECIDED_STATUSES.includes(status);
}

/**
 * Quick replies offered under the latest AI message.
 *
 * Each is a prefilled reply sent through the normal reply path. Which ones
 * show depends on whether the thread has an edit waiting on the author.
 *
 * @param {boolean} hasPendingEdit Whether the thread's newest edit is pending.
 * @return {Array<{key: string, label: string, message: string}>} Quick replies.
 */
export function getQuickReplies(hasPendingEdit) {
	const why = {
		key: 'why',
		label: __('Why?', 'fresh-eyes'),
		message: __('Why do you suggest this?', 'fresh-eyes'),
	};

	if (hasPendingEdit) {
		return [
			{
				key: 'shorter',
				label: __('Shorter', 'fresh-eyes'),
				message: __('Make it shorter.', 'fresh-eyes'),
			},
			{
				key: 'try-another',
				label: __('Try another', 'fresh-eyes'),
				message: __('Try a different version.', 'fresh-eyes'),
			},
			why,
		];
	}

	return [
		{
			key: 'make-change',
			label: __('Make that change', 'fresh-eyes'),
			message: __('Please make this change.', 'fresh-eyes'),
		},
		{
			key: 'example',
			label: __('Show me an example', 'fresh-eyes'),
			message: __('Show me an example.', 'fresh-eyes'),
		},
		why,
	];
}
