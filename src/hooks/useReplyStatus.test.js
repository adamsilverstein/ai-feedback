/**
 * Tests for useReplyStatus.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, waitFor, act } from '@testing-library/react';

const mockApiFetch = vi.fn();
vi.mock('@wordpress/api-fetch', () => ({
	default: (args) => mockApiFetch(args),
}));

import useReplyStatus, {
	MAX_PENDING_MS,
	MAX_POLL_INTERVAL_MS,
	MAX_UNKNOWN_POLLS,
} from './useReplyStatus';

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
		vi.useFakeTimers();

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
			vi.advanceTimersByTime(10_000);
		});

		expect(mockApiFetch).toHaveBeenCalledTimes(1);

		vi.useRealTimers();
	});

	it('gives up with a failed status after repeated unknown responses', async () => {
		vi.useFakeTimers();
		mockApiFetch.mockResolvedValue({ reply_id: 200, status: 'unknown' });

		const { result } = renderHook(() => useReplyStatus(200));

		for (let i = 0; i < MAX_UNKNOWN_POLLS; i++) {
			await act(async () => {
				await Promise.resolve();
				vi.advanceTimersByTime(MAX_POLL_INTERVAL_MS);
			});
		}

		await waitFor(() => expect(result.current.status).toBe('failed'));
		expect(mockApiFetch).toHaveBeenCalledTimes(MAX_UNKNOWN_POLLS);

		act(() => {
			vi.advanceTimersByTime(MAX_POLL_INTERVAL_MS * 5);
		});
		expect(mockApiFetch).toHaveBeenCalledTimes(MAX_UNKNOWN_POLLS);

		vi.useRealTimers();
	});

	it('gives up on a reply that stays pending too long', async () => {
		vi.useFakeTimers();
		mockApiFetch.mockResolvedValue({ reply_id: 200, status: 'pending' });

		const { result } = renderHook(() => useReplyStatus(200));

		const polls = Math.ceil(MAX_PENDING_MS / MAX_POLL_INTERVAL_MS) + 5;
		for (let i = 0; i < polls; i++) {
			await act(async () => {
				await Promise.resolve();
				vi.advanceTimersByTime(MAX_POLL_INTERVAL_MS);
			});
		}

		await waitFor(() => expect(result.current.status).toBe('failed'));
		expect(result.current.error).toBe(
			'The AI reply is taking longer than expected.'
		);

		vi.useRealTimers();
	});

	it('does not report the previous reply status after the ID changes', async () => {
		mockApiFetch.mockResolvedValueOnce({
			reply_id: 200,
			status: 'complete',
		});
		mockApiFetch.mockReturnValue(new Promise(() => {}));

		const { result, rerender } = renderHook(
			({ id }) => useReplyStatus(id),
			{
				initialProps: { id: 200 },
			}
		);
		await waitFor(() => expect(result.current.status).toBe('complete'));

		rerender({ id: 201 });

		expect(result.current.status).toBe('pending');
	});
});
