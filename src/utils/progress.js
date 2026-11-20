/**
 * How far the author has worked through a review's feedback.
 *
 * A note counts as addressed when it was dismissed (or its quick fix applied)
 * in the sidebar, its suggested edit was accepted or rejected, or its thread
 * was resolved in the Notes panel. A deleted, trashed or spam note is left
 * out entirely: core never loads those into the editor. Review_Progress
 * applies the same rules on the server, and tests/fixtures/addressed-cases.json
 * holds the cases both are tested against.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { getRecordSuggestionStatus } from '../hooks/useSuggestionStatus';

/**
 * Suggestion statuses that mean the author acted on a suggested edit.
 */
export const ADDRESSED_SUGGESTION_STATUSES = [
	'applied',
	'applied-unsaved',
	'rejected',
	'rejected-unsaved',
];

/**
 * Whether the author has dealt with a note.
 *
 * @param {Object}      note           Feedback note from the review.
 * @param {Object}      context        Current state.
 * @param {Object|null} context.record The note's core-data record, when loaded.
 * @param {string|null} context.status Status of the note's thread (`approved` once resolved), when known.
 * @return {boolean} True when addressed.
 */
export function isNoteAddressed(note, { record = null, status = null } = {}) {
	if (note.is_resolved) {
		return true;
	}

	const suggestionStatus = record
		? getRecordSuggestionStatus(record)
		: (note.suggestion_status ?? null);
	if (ADDRESSED_SUGGESTION_STATUSES.includes(suggestionStatus)) {
		return true;
	}

	return status === 'approved';
}

/**
 * Count the addressed notes of a review.
 *
 * @param {Array}      notes   Feedback notes from the review.
 * @param {Array|null} records The post's note records from core-data, or null
 *                             while they are loading. Only a loaded list can
 *                             show that a note was deleted.
 * @return {{ addressed: number, total: number }} Counts of saved, existing notes.
 */
export function getFeedbackProgress(notes, records = null) {
	const byId = new Map((records || []).map((record) => [record.id, record]));
	const reviewNotes = new Map(
		(notes || []).filter((note) => note?.id).map((note) => [note.id, note])
	);

	let addressed = 0;
	let total = 0;
	reviewNotes.forEach((note) => {
		const record = byId.get(note.id) || null;
		if (records && !record) {
			return;
		}

		const threadId = note.parent || note.id;
		const thread = byId.get(threadId) || reviewNotes.get(threadId);
		total++;
		if (isNoteAddressed(note, { record, status: thread?.status ?? null })) {
			addressed++;
		}
	});

	return { addressed, total };
}

/**
 * Describe progress through a review's feedback.
 *
 * @param {{ addressed: number, total: number }} progress Progress counts.
 * @return {string} Text such as "5 of 12 addressed".
 */
export function describeProgress({ addressed, total }) {
	if (total > 0 && addressed >= total) {
		return sprintf(
			/* translators: %d: number of feedback notes */
			_n(
				'The %d note from this review is addressed.',
				'All %d notes from this review are addressed.',
				total,
				'fresh-eyes'
			),
			total
		);
	}

	return sprintf(
		/* translators: 1: number of notes addressed, 2: number of notes */
		_n(
			'%1$d of %2$d note addressed',
			'%1$d of %2$d notes addressed',
			total,
			'fresh-eyes'
		),
		addressed,
		total
	);
}

/**
 * Describe what changed since the previous review.
 *
 * @param {Object|null} progress            `{ addressed, still_open }` from the review, null for a first review.
 * @param {number}      progress.addressed  Notes left open by the previous review that are now addressed.
 * @param {number}      progress.still_open Notes left open by the previous review that still are.
 * @param {number}      newCount            Notes this review added.
 * @return {string} Text such as "3 addressed, 1 new", or an empty string without a previous review.
 */
export function describeComparison(progress, newCount) {
	if (!progress) {
		return '';
	}

	const addressed = sprintf(
		/* translators: %d: number of notes */
		_n('%d addressed', '%d addressed', progress.addressed, 'fresh-eyes'),
		progress.addressed
	);
	const added = sprintf(
		/* translators: %d: number of notes */
		_n('%d new', '%d new', newCount, 'fresh-eyes'),
		newCount
	);

	if (progress.still_open > 0) {
		return sprintf(
			/* translators: 1: "3 addressed", 2: "1 new", 3: "2 still open" */
			__('Since the last review: %1$s, %2$s, %3$s', 'fresh-eyes'),
			addressed,
			added,
			sprintf(
				/* translators: %d: number of notes */
				_n(
					'%d still open',
					'%d still open',
					progress.still_open,
					'fresh-eyes'
				),
				progress.still_open
			)
		);
	}

	return sprintf(
		/* translators: 1: "3 addressed", 2: "1 new" */
		__('Since the last review: %1$s, %2$s', 'fresh-eyes'),
		addressed,
		added
	);
}
