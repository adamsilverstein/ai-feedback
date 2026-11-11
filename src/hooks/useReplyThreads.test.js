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

import useReplyThreads, { getNotesQuery } from './useReplyThreads';

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
});
