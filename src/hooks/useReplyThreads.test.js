/**
 * Tests for useReplyThreads.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, waitFor, act } from '@testing-library/react';

const mockApiFetch = vi.fn();
vi.mock('@wordpress/api-fetch', () => ({
	default: (args) => mockApiFetch(args),
}));

// Stand-in for the notes the editor's Notes UI has loaded.
let mockEditorNotes = [];
const mockInvalidateResolution = vi.fn();
vi.mock('@wordpress/data', () => ({
	useSelect: (mapSelect) =>
		mapSelect(() => ({
			getEntityRecords: () => mockEditorNotes,
		})),
	useDispatch: () => ({ invalidateResolution: mockInvalidateResolution }),
}));

const mockSuggestReplyEdit = vi.fn();
vi.mock('../utils/suggestions', () => ({
	suggestReplyEdit: (...args) => mockSuggestReplyEdit(...args),
}));

import useReplyThreads, {
	getNotesQuery,
	getReplyEdits,
	suggestNewReplyEdits,
} from './useReplyThreads';

const THREADS = {
	300: {
		replies: [{ id: 301, author: 'Jane', content: 'Why?', is_ai: false }],
		pending_reply_id: 301,
	},
};

describe('useReplyThreads', () => {
	beforeEach(() => {
		mockApiFetch.mockReset();
		mockInvalidateResolution.mockReset();
		mockSuggestReplyEdit.mockReset();
		mockEditorNotes = [{ id: 300, parent: 0 }];
	});

	it('does not fetch for an unsaved post', () => {
		const { result } = renderHook(() => useReplyThreads(null));

		expect(result.current.threads).toEqual({});
		expect(mockApiFetch).not.toHaveBeenCalled();
	});

	it('loads the threads for the post', async () => {
		mockApiFetch.mockResolvedValue({ post: 42, threads: THREADS });

		const { result } = renderHook(() => useReplyThreads(42));

		await waitFor(() => expect(result.current.threads).toEqual(THREADS));
		expect(mockApiFetch).toHaveBeenCalledWith({
			path: '/ai-feedback/v1/replies?post=42',
		});
	});

	it('refetches when a reply appears in the Notes UI', async () => {
		mockApiFetch.mockResolvedValue({ post: 42, threads: {} });

		const { rerender } = renderHook(() => useReplyThreads(42));
		await waitFor(() => expect(mockApiFetch).toHaveBeenCalledTimes(1));

		mockEditorNotes = [...mockEditorNotes, { id: 301, parent: 300 }];
		rerender();

		await waitFor(() => expect(mockApiFetch).toHaveBeenCalledTimes(2));
	});

	it('refetches and refreshes the Notes UI once an AI reply is saved', async () => {
		mockApiFetch.mockResolvedValue({ post: 42, threads: THREADS });

		const { result } = renderHook(() => useReplyThreads(42));
		await waitFor(() => expect(mockApiFetch).toHaveBeenCalledTimes(1));

		act(() => {
			result.current.onReplyComplete();
		});

		await waitFor(() => expect(mockApiFetch).toHaveBeenCalledTimes(2));
		expect(mockInvalidateResolution).toHaveBeenCalledWith(
			'getEntityRecords',
			['root', 'comment', getNotesQuery(42)]
		);
	});

	it('suggests the edit of an AI reply that arrives after the first load', async () => {
		const edit = { type: 'replace_text', find: 'a', replace: 'b' };
		const old = { id: 302, is_ai: true, content: 'Old.', edit };
		const fresh = { id: 304, is_ai: true, content: 'New.', edit };
		mockApiFetch.mockResolvedValue({
			post: 42,
			threads: { 300: { replies: [old], pending_reply_id: null } },
		});

		const { result } = renderHook(() => useReplyThreads(42));
		await waitFor(() => expect(mockApiFetch).toHaveBeenCalledTimes(1));
		await waitFor(() =>
			expect(result.current.threads[300].replies).toHaveLength(1)
		);
		// Edits already there on load are left to the sidebar.
		expect(mockSuggestReplyEdit).not.toHaveBeenCalled();

		mockApiFetch.mockResolvedValue({
			post: 42,
			threads: { 300: { replies: [old, fresh], pending_reply_id: null } },
		});
		act(() => {
			result.current.onReplyComplete();
		});

		await waitFor(() =>
			expect(mockSuggestReplyEdit).toHaveBeenCalledWith(fresh, 300)
		);
		expect(mockSuggestReplyEdit).toHaveBeenCalledTimes(1);
	});
});

describe('getReplyEdits', () => {
	it('lists AI replies with an edit and their thread', () => {
		const edit = { type: 'rewrite_block', content: 'x', original: 'y' };
		expect(
			getReplyEdits({
				300: {
					replies: [
						{ id: 301, is_ai: false, edit },
						{ id: 302, is_ai: true, edit: null },
						{ id: 303, is_ai: true, edit },
					],
				},
			})
		).toEqual([{ reply: { id: 303, is_ai: true, edit }, threadId: 300 }]);
	});
});

describe('suggestNewReplyEdits', () => {
	beforeEach(() => {
		mockSuggestReplyEdit.mockReset();
	});

	it('suggests each new edit once, across hook instances', () => {
		const edit = { type: 'replace_text', find: 'a', replace: 'b' };
		const threads = {
			400: { replies: [{ id: 401, is_ai: true, edit }] },
		};

		suggestNewReplyEdits(threads, new Set());
		suggestNewReplyEdits(threads, new Set());

		expect(mockSuggestReplyEdit).toHaveBeenCalledTimes(1);
		expect(mockSuggestReplyEdit).toHaveBeenCalledWith(
			threads[400].replies[0],
			400
		);
	});

	it('only records the edits on the first load', () => {
		const edit = { type: 'replace_text', find: 'a', replace: 'b' };
		const seen = suggestNewReplyEdits(
			{ 500: { replies: [{ id: 501, is_ai: true, edit }] } },
			null
		);

		expect(seen).toEqual(new Set([501]));
		expect(mockSuggestReplyEdit).not.toHaveBeenCalled();
	});
});
