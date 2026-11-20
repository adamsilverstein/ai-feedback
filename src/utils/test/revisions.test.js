/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import {
	getLatestEdit,
	getQuickReplies,
	getRevisionLabel,
	getSupersededOwnerIds,
	getThreadEdits,
	isEditPending,
} from '../revisions';

const quickFix = { find: 'last year', replace: 'in 2025' };
const edit = { type: 'replace_text', find: 'last year', replace: '2025' };
const replies = [
	{ id: 301, is_ai: false, edit: null },
	{ id: 302, is_ai: true, edit },
	{ id: 303, is_ai: true, edit: null },
	{ id: 304, is_ai: false, edit },
	{ id: 305, is_ai: true, edit },
];

describe('getThreadEdits', () => {
	it('lists the quick fix, then each AI reply edit, oldest first', () => {
		expect(
			getThreadEdits({ id: 300, quick_fix: quickFix }, replies)
		).toEqual([
			{ ownerId: 300, edit: quickFix },
			{ ownerId: 302, edit },
			{ ownerId: 305, edit },
		]);
	});

	it('lists a formatting fix as the first edit, like a quick fix', () => {
		const change = {
			type: 'set_attributes',
			attributes: { level: 3 },
			from: { level: 4 },
		};

		expect(
			getThreadEdits({ id: 300, block_change: change }, replies)
		).toEqual([
			{ ownerId: 300, edit: change },
			{ ownerId: 302, edit },
			{ ownerId: 305, edit },
		]);
		expect(
			getSupersededOwnerIds({ id: 300, block_change: change }, replies)
		).toEqual([300, 302]);
	});

	it('leaves out a secondary note’s quick fix', () => {
		expect(
			getThreadEdits({ id: 310, parent: 300, quick_fix: quickFix }, [])
		).toEqual([]);
	});

	it('works without the note', () => {
		expect(getThreadEdits(null, replies).map((e) => e.ownerId)).toEqual([
			302, 305,
		]);
	});
});

describe('getSupersededOwnerIds and getLatestEdit', () => {
	const note = { id: 300, quick_fix: quickFix };

	it('supersede every edit but the newest', () => {
		expect(getSupersededOwnerIds(note, replies)).toEqual([300, 302]);
		expect(getLatestEdit(note, replies)).toEqual({ ownerId: 305, edit });
	});

	it('leave a lone quick fix current', () => {
		expect(getSupersededOwnerIds(note, [])).toEqual([]);
		expect(getLatestEdit(note, []).ownerId).toBe(300);
		expect(getLatestEdit({ id: 300 }, [])).toBe(null);
	});
});

describe('getRevisionLabel', () => {
	it('labels the first edit and numbers revisions', () => {
		expect(getRevisionLabel(1)).toBe('Suggested edit');
		expect(getRevisionLabel(2)).toBe('Revised suggestion (2)');
		expect(getRevisionLabel(null)).toBe('');
	});
});

describe('isEditPending', () => {
	const latest = { ownerId: 305, edit };

	it('is pending until the author acts on it', () => {
		expect(isEditPending(latest, {})).toBe(true);
		expect(isEditPending(latest, { status: 'pending' })).toBe(true);
	});

	it.each([
		'applied',
		'applied-unsaved',
		'rejected',
		'rejected-unsaved',
		'outdated',
		'some-future-status',
	])('is not pending once its suggestion is %s', (status) => {
		expect(isEditPending(latest, { status })).toBe(false);
	});

	it('is not pending once applied, or without an edit', () => {
		expect(isEditPending(latest, { isApplied: true })).toBe(false);
		expect(isEditPending(null, {})).toBe(false);
	});
});

describe('getQuickReplies', () => {
	it('offers to refine a pending edit', () => {
		expect(getQuickReplies(true).map((reply) => reply.label)).toEqual([
			'Shorter',
			'Try another',
			'Why?',
		]);
	});

	it('offers to make a change when there is no pending edit', () => {
		expect(getQuickReplies(false).map((reply) => reply.label)).toEqual([
			'Make that change',
			'Show me an example',
			'Why?',
		]);
	});

	it('sends a full sentence, not the label', () => {
		expect(
			getQuickReplies(true).find((reply) => reply.key === 'shorter')
				.message
		).toBe('Make it shorter.');
	});
});

describe('structural edits', () => {
	const remove = { type: 'remove_block' };
	const move = {
		type: 'move_block',
		position: 'before',
		target_block_id: '',
		target_name: 'core/heading',
		target_text: 'Intro',
	};

	it('count a review note’s structural edit as the first edit', () => {
		expect(
			getThreadEdits({ id: 300, block_action: remove }, [
				{ id: 302, is_ai: true, edit: move },
			])
		).toEqual([
			{ ownerId: 300, edit: remove },
			{ ownerId: 302, edit: move },
		]);
		expect(
			getSupersededOwnerIds({ id: 300, block_action: remove }, [
				{ id: 302, is_ai: true, edit: move },
			])
		).toEqual([300]);
	});

	it('offer no “Shorter” for a pending structural edit', () => {
		expect(getQuickReplies(true, true).map(({ key }) => key)).toEqual([
			'try-another',
			'why',
		]);
		expect(getQuickReplies(false, true).map(({ key }) => key)).toEqual([
			'make-change',
			'example',
			'why',
		]);
	});
});
