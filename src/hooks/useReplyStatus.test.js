// @jest-environment jsdom
/* eslint-env jest */
/**
 * Tests for useReplyStatus.
 */
import { renderHook, waitFor, act } from '@testing-library/react';

const mockApiFetch = jest.fn();
jest.mock('@wordpress/api-fetch', () => (args) => mockApiFetch(args));

import useReplyStatus from './useReplyStatus';

describe('useReplyStatus', () => {
	beforeEach(() => {
		mockApiFetch.mockReset();
	});

	it('returns idle and does not fetch when replyId is falsy', () => {
		const { result } = renderHook(() => useReplyStatus(null));
		expect(result.current.status).toBe('idle');
		expect(mockApiFetch).not.toHaveBeenCalled();
	});

	it('transitions to complete and exposes the AI reply comment ID', async () => {
		mockApiFetch.mockResolvedValueOnce({
			reply_id: 200,
			status: 'complete',
			ai_reply_comment_id: 555,
		});

		const { result } = renderHook(() => useReplyStatus(200));

		await waitFor(() => expect(result.current.status).toBe('complete'));
		expect(result.current.aiReplyCommentId).toBe(555);
		expect(result.current.error).toBeNull();
		// Terminal state — only the first call is made.
		expect(mockApiFetch).toHaveBeenCalledTimes(1);
		expect(mockApiFetch).toHaveBeenCalledWith({
			path: '/ai-feedback/v1/replies/200/status',
			method: 'GET',
		});
	});

	it('surfaces the error message on failed status', async () => {
		mockApiFetch.mockResolvedValueOnce({
			reply_id: 200,
			status: 'failed',
			error: 'AI request timed out.',
		});

		const { result } = renderHook(() => useReplyStatus(200));

		await waitFor(() => expect(result.current.status).toBe('failed'));
		expect(result.current.error).toBe('AI request timed out.');
	});

	it('surfaces fetch errors as a failed status', async () => {
		mockApiFetch.mockRejectedValueOnce(new Error('network down'));

		const { result } = renderHook(() => useReplyStatus(200));

		await waitFor(() => expect(result.current.status).toBe('failed'));
		expect(result.current.error).toBe('network down');
	});

	it('stops polling when the component unmounts mid-pending', async () => {
		jest.useFakeTimers();

		mockApiFetch.mockResolvedValue({
			reply_id: 200,
			status: 'pending',
		});

		const { result, unmount } = renderHook(() => useReplyStatus(200));

		// Flush the initial poll.
		await act(async () => {
			await Promise.resolve();
		});

		await waitFor(() => expect(result.current.status).toBe('pending'));
		expect(mockApiFetch).toHaveBeenCalledTimes(1);

		unmount();

		// Advance past the poll interval; no additional fetch should fire.
		act(() => {
			jest.advanceTimersByTime(10_000);
		});

		expect(mockApiFetch).toHaveBeenCalledTimes(1);

		jest.useRealTimers();
	});
});
