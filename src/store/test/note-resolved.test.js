/**
 * Internal dependencies
 */
import { describe, it, expect, vi } from 'vitest';
import reducer, { initialState, TYPES } from '../reducer';
import { getUnresolvedNotesCount } from '../selectors';
import { setNoteResolved } from '../actions';
import { refreshCoreNotes } from '../../utils/refresh-core-notes';

vi.mock('@wordpress/block-editor', () => ({ store: 'core/block-editor' }));

vi.mock('../../utils/refresh-core-notes', () => ({
	refreshCoreNotes: vi.fn(),
}));

const stateWithNotes = {
	...initialState,
	lastReview: {
		notes: [
			{ id: 1, is_resolved: false },
			{ id: 2, is_resolved: false },
		],
	},
};

describe('SET_NOTE_RESOLVED', () => {
	it('flags only the matching note', () => {
		const state = reducer(stateWithNotes, {
			type: TYPES.SET_NOTE_RESOLVED,
			noteId: 2,
			isResolved: true,
		});

		expect(state.lastReview.notes).toEqual([
			{ id: 1, is_resolved: false },
			{ id: 2, is_resolved: true },
		]);
		expect(getUnresolvedNotesCount(state)).toBe(1);
	});

	it('ignores the action without a review', () => {
		const state = reducer(initialState, {
			type: TYPES.SET_NOTE_RESOLVED,
			noteId: 1,
			isResolved: true,
		});

		expect(state).toBe(initialState);
	});
});

describe('setNoteResolved', () => {
	it.each([
		[true, 'resolve'],
		[false, 'unresolve'],
	])('with %s calls the %s endpoint', (isResolved, endpoint) => {
		const generator = setNoteResolved(5, isResolved);

		expect(generator.next().value.request).toEqual({
			path: `/ai-feedback/v1/notes/5/${endpoint}`,
			method: 'POST',
		});
		expect(generator.next({ success: true }).value).toEqual({
			type: TYPES.SET_NOTE_RESOLVED,
			noteId: 5,
			isResolved,
			threadId: null,
			threadResolved: false,
		});
	});

	it('refreshes core notes when the thread status changed', () => {
		refreshCoreNotes.mockClear();
		const generator = setNoteResolved(5, true);
		generator.next();

		expect(
			generator.next({
				success: true,
				thread_id: 3,
				thread_resolved: true,
				thread_changed: true,
			}).value
		).toEqual({
			type: TYPES.SET_NOTE_RESOLVED,
			noteId: 5,
			isResolved: true,
			threadId: 3,
			threadResolved: true,
		});
		expect(refreshCoreNotes).toHaveBeenCalled();
	});

	it('leaves core notes alone when the thread is unchanged', () => {
		refreshCoreNotes.mockClear();
		const generator = setNoteResolved(5, true);
		generator.next();
		generator.next({ success: true, thread_id: 3, thread_changed: false });

		expect(refreshCoreNotes).not.toHaveBeenCalled();
	});
});

describe('SET_NOTE_RESOLVED thread status', () => {
	it.each([
		[true, 'approved'],
		[false, 'hold'],
	])(
		'mirrors a %s thread status on the top-level note',
		(threadResolved, status) => {
			const state = reducer(stateWithNotes, {
				type: TYPES.SET_NOTE_RESOLVED,
				noteId: 2,
				isResolved: threadResolved,
				threadId: 1,
				threadResolved,
			});

			expect(state.lastReview.notes[0].status).toBe(status);
			expect(state.lastReview.notes[1].status).toBeUndefined();
		}
	);
});
