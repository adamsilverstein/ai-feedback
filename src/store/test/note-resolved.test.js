/**
 * Internal dependencies
 */
import reducer, { initialState, TYPES } from '../reducer';
import { getUnresolvedNotesCount } from '../selectors';
import { setNoteResolved } from '../actions';

jest.mock('@wordpress/block-editor', () => ({ store: 'core/block-editor' }));

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
		});
	});
});
