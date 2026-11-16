/**
 * Tests for useReplyStatus.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, waitFor, act } from '@testing-library/react';

// Status polls go to mockApiFetch, requests to answer the reply to mockProcess.
const mockApiFetch = vi.fn();
const mockProcess = vi.fn();
vi.mock('@wordpress/api-fetch', () => ({
	default: (args) =>
		args.method === 'POST' ? mockProcess(args) : mockApiFetch(args),
}));

import useReplyStatus, {
	MAX_PENDING_MS,
	MAX_POLL_INTERVAL_MS,
	MAX_UNKNOWN_POLLS,
	RETRY_COOLDOWN_BUFFER_MS,
	SLOW_REPLY_MS,
} from './useReplyStatus';

describe('useReplyStatus', () => {
	beforeEach(() => {
		mockApiFetch.mockReset();
		mockProcess.mockReset();
		// By default the answer request is still running.
		mockProcess.mockReturnValue(new Promise(() => {}));
	});

	it('asks the server to answer the reply without waiting for cron', async () => {
		mockApiFetch.mockResolvedValue({ reply_id: 200, status: 'pending' });
		mockProcess.mockResolvedValueOnce({
			reply_id: 200,
			status: 'complete',
			ai_reply_comment_id: 555,
		});

		const { result } = renderHook(() => useReplyStatus(200));

		await waitFor(() => expect(result.current.status).toBe('complete'));
		expect(result.current.aiReplyCommentId).toBe(555);
		expect(mockProcess).toHaveBeenCalledTimes(1);
		expect(mockProcess).toHaveBeenCalledWith({
			path: '/ai-feedback/v1/replies/200/process',
			method: 'POST',
			data: { retry: false },
		});
	});

	it('keeps polling when the answer request fails', async () => {
		mockProcess.mockRejectedValueOnce(new Error('gateway timeout'));
		mockApiFetch.mockResolvedValueOnce({
			reply_id: 200,
			status: 'pending',
		});

		const { result } = renderHook(() => useReplyStatus(200));

		await waitFor(() => expect(mockProcess).toHaveBeenCalledTimes(1));
		expect(result.current.status).toBe('pending');
		expect(result.current.error).toBeNull();
	});

	it('logs a failed answer request when debugging is on', async () => {
		window.localStorage.setItem('AI_FEEDBACK_DEBUG', 'true');
		const debug = vi.spyOn(console, 'debug').mockImplementation(() => {});
		const error = new Error('forbidden');
		mockProcess.mockRejectedValueOnce(error);
		mockApiFetch.mockResolvedValue({ reply_id: 200, status: 'pending' });

		renderHook(() => useReplyStatus(200));

		try {
			await waitFor(() =>
				expect(debug).toHaveBeenCalledWith(
					'[AI-Feedback]',
					'Reply process request failed',
					200,
					error
				)
			);
		} finally {
			debug.mockRestore();
			window.localStorage.removeItem('AI_FEEDBACK_DEBUG');
		}
	});

	it('flags a reply that is taking longer than expected', async () => {
		vi.useFakeTimers();
		mockApiFetch.mockResolvedValue({ reply_id: 200, status: 'pending' });

		const { result } = renderHook(() => useReplyStatus(200));

		await act(async () => {
			await Promise.resolve();
		});
		expect(result.current.isSlow).toBe(false);

		await act(async () => {
			vi.advanceTimersByTime(SLOW_REPLY_MS);
		});

		expect(result.current.isSlow).toBe(true);
		expect(result.current.status).toBe('pending');

		vi.useRealTimers();
	});

	it('retries the answer with the retry flag and resets the slow state', async () => {
		vi.useFakeTimers();
		mockApiFetch.mockResolvedValue({ reply_id: 200, status: 'pending' });

		const { result } = renderHook(() => useReplyStatus(200));

		await act(async () => {
			vi.advanceTimersByTime(SLOW_REPLY_MS);
		});
		expect(result.current.isSlow).toBe(true);

		await act(async () => {
			result.current.retry();
		});

		expect(result.current.isSlow).toBe(false);
		expect(mockProcess).toHaveBeenCalledTimes(2);
		expect(mockProcess).toHaveBeenLastCalledWith({
			path: '/ai-feedback/v1/replies/200/process',
			method: 'POST',
			data: { retry: true },
		});

		vi.useRealTimers();
	});

	it('retries a failed reply', async () => {
		const failed = {
			reply_id: 200,
			status: 'failed',
			error: 'Timed out.',
		};
		mockApiFetch.mockResolvedValueOnce(failed);
		// The answer request finds the reply already failed.
		mockProcess.mockResolvedValueOnce(failed);

		const { result } = renderHook(() => useReplyStatus(200));
		await waitFor(() => expect(result.current.status).toBe('failed'));

		mockApiFetch.mockResolvedValue({ reply_id: 200, status: 'pending' });
		await act(async () => {
			result.current.retry();
		});

		await waitFor(() => expect(result.current.status).toBe('pending'));
		expect(mockProcess).toHaveBeenLastCalledWith(
			expect.objectContaining({ data: { retry: true } })
		);
	});

	it('blocks a retry until the server cooldown has passed', async () => {
		vi.useFakeTimers();
		const failed = {
			reply_id: 200,
			status: 'failed',
			error: 'No API key.',
			retry_after: 6,
		};
		mockApiFetch.mockResolvedValueOnce(failed);
		// The answer request finds the reply already failed.
		mockProcess.mockResolvedValueOnce(failed);

		const { result } = renderHook(() => useReplyStatus(200));
		await act(async () => {
			await vi.advanceTimersByTimeAsync(0);
		});
		expect(result.current.status).toBe('failed');
		expect(result.current.canRetry).toBe(false);

		await act(async () => {
			await vi.advanceTimersByTimeAsync(
				6000 + RETRY_COOLDOWN_BUFFER_MS - 1
			);
		});
		expect(result.current.canRetry).toBe(false);

		await act(async () => {
			await vi.advanceTimersByTimeAsync(1);
		});
		expect(result.current.status).toBe('failed');
		expect(result.current.canRetry).toBe(true);

		vi.useRealTimers();
	});

	it('blocks a retry the server ignored during the cooldown', async () => {
		mockApiFetch.mockResolvedValue({ reply_id: 200, status: 'pending' });
		mockProcess.mockResolvedValueOnce({
			reply_id: 200,
			status: 'failed',
			retry_after: 3,
		});

		const { result } = renderHook(() => useReplyStatus(200));

		await waitFor(() => expect(result.current.status).toBe('failed'));
		expect(result.current.canRetry).toBe(false);
	});

	it('allows a retry right away when the server reports no cooldown', async () => {
		const failed = {
			reply_id: 200,
			status: 'failed',
			error: 'Timed out.',
		};
		mockApiFetch.mockResolvedValueOnce(failed);
		// The answer request finds the reply already failed.
		mockProcess.mockResolvedValueOnce(failed);

		const { result } = renderHook(() => useReplyStatus(200));

		await waitFor(() => expect(result.current.status).toBe('failed'));
		expect(result.current.canRetry).toBe(true);
	});

	it('waits for its own answer request when a poll reports a stale failure', async () => {
		// The poll can reach the server before the answer request claims the
		// reply, and then reports the outcome of the previous attempt.
		let resolveProcess;
		mockProcess.mockReturnValueOnce(
			new Promise((resolve) => {
				resolveProcess = resolve;
			})
		);
		mockApiFetch.mockResolvedValue({
			reply_id: 200,
			status: 'failed',
			error: 'The AI reply timed out.',
		});

		const { result } = renderHook(() => useReplyStatus(200));

		await waitFor(() => expect(mockApiFetch).toHaveBeenCalledTimes(1));
		expect(result.current.status).toBe('pending');
		expect(result.current.error).toBeNull();

		await act(async () => {
			resolveProcess({
				reply_id: 200,
				status: 'complete',
				ai_reply_comment_id: 555,
			});
		});

		expect(result.current.status).toBe('complete');
		expect(result.current.aiReplyCommentId).toBe(555);
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
		const failed = {
			reply_id: 200,
			status: 'failed',
			error: 'AI request timed out.',
		};
		mockApiFetch.mockResolvedValueOnce(failed);
		// The answer request finds the reply already failed.
		mockProcess.mockResolvedValueOnce(failed);

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
