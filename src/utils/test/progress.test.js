/**
 * External dependencies
 */
import { describe, it, expect, vi } from 'vitest';

/**
 * Internal dependencies
 */
import {
	describeComparison,
	describeProgress,
	getFeedbackProgress,
	isNoteAddressed,
} from '../progress';

vi.mock('@wordpress/editor', () => ({ store: 'core/editor' }));

/**
 * A core-data note record.
 *
 * @param {number} id     Note ID.
 * @param {Object} fields Extra fields.
 * @return {Object} Record.
 */
const record = (id, fields = {}) => ({
	id,
	parent: 0,
	status: 'hold',
	meta: {},
	...fields,
});

describe('isNoteAddressed', () => {
	it('is false for an open note', () => {
		expect(isNoteAddressed({ id: 1 })).toBe(false);
	});

	it('counts a note dismissed in the sidebar', () => {
		expect(isNoteAddressed({ id: 1, is_resolved: true })).toBe(true);
	});

	it.each([
		['applied', true],
		['applied-unsaved', true],
		['rejected', true],
		['rejected-unsaved', true],
		['pending', false],
		['outdated', false],
	])(
		'reads suggestion status %s from the note record',
		(status, expected) => {
			const loaded = record(1, {
				meta: { _wp_suggestion: '{}', _wp_suggestion_status: status },
			});
			expect(isNoteAddressed({ id: 1 }, { record: loaded })).toBe(
				expected
			);
		}
	);

	it('falls back to the review status until the record loads', () => {
		expect(
			isNoteAddressed({ id: 1, suggestion_status: 'rejected' }, {})
		).toBe(true);
	});

	it('prefers the record over a stale review status', () => {
		expect(
			isNoteAddressed(
				{ id: 1, suggestion_status: 'applied' },
				{ record: record(1) }
			)
		).toBe(false);
	});

	it('counts a note in a resolved thread', () => {
		expect(isNoteAddressed({ id: 1 }, { status: 'approved' })).toBe(true);
	});
});

describe('getFeedbackProgress', () => {
	const notes = [
		{ id: 1, parent: 0, status: 'hold' },
		{ id: 2, parent: 1, status: 'hold' },
		{ id: 3, parent: 0, status: 'hold', is_resolved: true },
		{ id: 4, parent: 0, status: 'hold' },
	];

	it('uses the review alone while records load', () => {
		expect(getFeedbackProgress(notes, null)).toEqual({
			addressed: 1,
			total: 4,
		});
	});

	it('counts every note of a thread resolved in the Notes panel', () => {
		const records = [
			record(1, { status: 'approved' }),
			record(2, { parent: 1 }),
			record(3),
			record(4),
		];
		expect(getFeedbackProgress(notes, records)).toEqual({
			addressed: 3,
			total: 4,
		});
	});

	it('uses the thread status from the review before records load', () => {
		const resolved = notes.map((note) =>
			note.id === 1 ? { ...note, status: 'approved' } : note
		);
		expect(getFeedbackProgress(resolved, null)).toEqual({
			addressed: 3,
			total: 4,
		});
	});

	it('leaves out notes that were deleted', () => {
		const records = [record(1), record(2, { parent: 1 }), record(3)];
		expect(getFeedbackProgress(notes, records)).toEqual({
			addressed: 1,
			total: 3,
		});
	});

	it('leaves out feedback that never became a note', () => {
		expect(
			getFeedbackProgress([{ title: 'Unsaved' }, { id: 5 }], null)
		).toEqual({ addressed: 0, total: 1 });
	});

	it('is empty without a review', () => {
		expect(getFeedbackProgress(undefined, null)).toEqual({
			addressed: 0,
			total: 0,
		});
	});
});

describe('describeProgress', () => {
	it('counts addressed notes', () => {
		expect(describeProgress({ addressed: 5, total: 12 })).toBe(
			'5 of 12 notes addressed'
		);
	});

	it('says when every note is addressed', () => {
		expect(describeProgress({ addressed: 12, total: 12 })).toBe(
			'All 12 notes from this review are addressed.'
		);
	});
});

describe('describeComparison', () => {
	it('is empty for a first review', () => {
		expect(describeComparison(null, 4)).toBe('');
	});

	it('reports addressed and new notes', () => {
		expect(describeComparison({ addressed: 3, still_open: 0 }, 1)).toBe(
			'Since the last review: 3 addressed, 1 new'
		);
	});

	it('adds the notes still open', () => {
		expect(describeComparison({ addressed: 0, still_open: 2 }, 0)).toBe(
			'Since the last review: 0 addressed, 0 new, 2 still open'
		);
	});
});
