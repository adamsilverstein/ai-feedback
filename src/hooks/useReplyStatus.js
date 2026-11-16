/**
 * useReplyStatus hook.
 *
 * Asks the server to answer a user reply comment through
 * /ai-feedback/v1/replies/{id}/process, so the answer does not wait on
 * WP-Cron, and polls /replies/{id}/status until it lands. Polling stops once
 * the server reports a terminal state (`complete` or `failed`), when it gives
 * up, or when the component unmounts.
 *
 * The server ignores a retry for a few seconds after a failed attempt, and
 * reports the time left as `retry_after`. `canRetry` is false until then.
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { debugLog } from '../utils/debug';

export const POLL_INTERVAL_MS = 2000;

/**
 * Longest wait between polls. The interval doubles up to this while pending.
 */
export const MAX_POLL_INTERVAL_MS = 15000;

/**
 * How long a reply can be pending before the editor offers a retry.
 */
export const SLOW_REPLY_MS = 30 * 1000;

/**
 * How long to wait on a pending reply before giving up. Covers a cron worker
 * that died without recording a result.
 */
export const MAX_PENDING_MS = 10 * 60 * 1000;

/**
 * How many `unknown` responses to tolerate before giving up. A detected reply
 * is marked pending as soon as it is saved, so a lasting `unknown` means no
 * AI reply was ever scheduled.
 */
export const MAX_UNKNOWN_POLLS = 5;

/**
 * Build the state for a reply that is still being generated.
 *
 * @param {number|null} id Reply ID.
 * @return {Object} State.
 */
function initialState(id) {
	return {
		id,
		status: id ? 'pending' : 'idle',
		aiReplyCommentId: null,
		error: null,
		isSlow: false,
		canRetry: true,
	};
}

/**
 * @param {number|null} replyId User reply comment ID to poll for, or null/0 to disable.
 * @return {{status: string, aiReplyCommentId: number | null, error: string | null, isSlow: boolean, canRetry: boolean, retry: () => void}}
 *         Current reply status. `status` is one of `idle | pending | complete | failed | unknown`.
 *         `isSlow` is true once a pending reply has waited SLOW_REPLY_MS,
 *         `retry()` asks the server to try again, and `canRetry` is false
 *         while the server would ignore a retry.
 */
export default function useReplyStatus(replyId) {
	const [state, setState] = useState(() => initialState(replyId));
	// Retries count per reply, so a new reply starts with a plain request.
	const [retries, setRetries] = useState({ id: replyId, count: 0 });
	const attempt = retries.id === replyId ? retries.count : 0;

	// Track active timers so unmount/deps change can cancel them cleanly.
	const timeoutRef = useRef(null);
	const slowTimeoutRef = useRef(null);
	const cooldownTimeoutRef = useRef(null);

	const retry = useCallback(
		() =>
			setRetries((current) => ({
				id: replyId,
				count: (current.id === replyId ? current.count : 0) + 1,
			})),
		[replyId]
	);

	useEffect(() => {
		setState(initialState(replyId));
		if (!replyId) {
			return undefined;
		}

		let cancelled = false;
		let finished = false;
		let unknownPolls = 0;
		// Whether this hook's request to answer the reply is still running.
		let isAnswering = true;
		let interval = POLL_INTERVAL_MS;
		const startedAt = Date.now();

		const stop = () => {
			finished = true;
			clearTimeout(timeoutRef.current);
			clearTimeout(slowTimeoutRef.current);
			timeoutRef.current = null;
			slowTimeoutRef.current = null;
		};

		// `retryAfter` is how many seconds the server will ignore a retry for.
		const fail = (error, retryAfter = 0) => {
			stop();
			const seconds = Number(retryAfter) || 0;
			setState({
				id: replyId,
				status: 'failed',
				aiReplyCommentId: null,
				error,
				isSlow: false,
				canRetry: seconds <= 0,
			});
			if (seconds > 0) {
				cooldownTimeoutRef.current = setTimeout(() => {
					if (!cancelled) {
						setState((current) => ({ ...current, canRetry: true }));
					}
				}, seconds * 1000);
			}
		};

		slowTimeoutRef.current = setTimeout(() => {
			if (!cancelled && !finished) {
				setState((current) => ({ ...current, isSlow: true }));
			}
		}, SLOW_REPLY_MS);

		// Answer the reply in this request rather than waiting on WP-Cron,
		// which never runs on some hosts. Polling keeps going meanwhile, and
		// covers a request that fails or times out.
		apiFetch({
			path: `/ai-feedback/v1/replies/${replyId}/process`,
			method: 'POST',
			data: { retry: attempt > 0 },
		})
			.then((response) => {
				isAnswering = false;
				if (cancelled || finished) {
					return;
				}
				if (response?.status === 'complete') {
					stop();
					setState({
						id: replyId,
						status: 'complete',
						aiReplyCommentId: response.ai_reply_comment_id ?? null,
						error: null,
						isSlow: false,
						canRetry: true,
					});
				} else if (response?.status === 'failed') {
					fail(response.error ?? null, response.retry_after);
				}
			})
			// Polling still reports the outcome, so only log the failure.
			.catch((err) => {
				isAnswering = false;
				debugLog('Reply process request failed', replyId, err);
			});

		const poll = async () => {
			try {
				const response = await apiFetch({
					path: `/ai-feedback/v1/replies/${replyId}/status`,
					method: 'GET',
				});

				if (cancelled || finished) {
					return;
				}

				const next = {
					id: replyId,
					status: response?.status || 'unknown',
					aiReplyCommentId: response?.ai_reply_comment_id ?? null,
					error: response?.error ?? null,
				};

				// A failure seen while the answer request is running is the
				// previous attempt's: the poll can reach the server before
				// that request claims the reply. Its response has the outcome.
				if (next.status === 'failed' && isAnswering) {
					next.status = 'pending';
					next.error = null;
				}

				if (next.status === 'unknown') {
					unknownPolls++;
					if (unknownPolls >= MAX_UNKNOWN_POLLS) {
						fail(
							__(
								'No AI reply was scheduled for this comment.',
								'copydesk-ai'
							)
						);
						return;
					}
				}

				if (
					next.status === 'pending' &&
					Date.now() - startedAt >= MAX_PENDING_MS
				) {
					fail(
						__(
							'The AI reply is taking longer than expected.',
							'copydesk-ai'
						)
					);
					return;
				}

				// Keep polling only while the server reports in-flight work.
				if (next.status === 'pending' || next.status === 'unknown') {
					setState((current) => ({
						...next,
						isSlow: current.isSlow,
						canRetry: true,
					}));
					timeoutRef.current = setTimeout(poll, interval);
					interval = Math.min(interval * 2, MAX_POLL_INTERVAL_MS);
				} else if (next.status === 'failed') {
					fail(next.error, response?.retry_after);
				} else {
					stop();
					setState({ ...next, isSlow: false, canRetry: true });
				}
			} catch (err) {
				if (cancelled || finished) {
					return;
				}
				fail(
					err?.message ||
						__(
							'Could not check the AI reply status.',
							'copydesk-ai'
						)
				);
			}
		};

		poll();

		return () => {
			cancelled = true;
			clearTimeout(timeoutRef.current);
			clearTimeout(slowTimeoutRef.current);
			clearTimeout(cooldownTimeoutRef.current);
			timeoutRef.current = null;
			slowTimeoutRef.current = null;
			cooldownTimeoutRef.current = null;
		};
	}, [replyId, attempt]);

	// Until the effect resets state for a new ID, report the new ID as pending
	// rather than leaking the previous reply's result for a render.
	const current = state.id === replyId ? state : initialState(replyId);
	const { status, aiReplyCommentId, error, isSlow, canRetry } = current;

	return { status, aiReplyCommentId, error, isSlow, canRetry, retry };
}
