/**
 * useReplyStatus hook.
 *
 * Polls /ai-feedback/v1/replies/{id}/status for a given user reply comment
 * and returns its current processing state. Polling stops once the server
 * reports a terminal state (`complete` or `failed`), when it gives up, or
 * when the component unmounts.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

export const POLL_INTERVAL_MS = 2000;

/**
 * Longest wait between polls. The interval doubles up to this while pending.
 */
export const MAX_POLL_INTERVAL_MS = 15000;

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
	};
}

/**
 * @param {number|null} replyId User reply comment ID to poll for, or null/0 to disable.
 * @return {{status: string, aiReplyCommentId: number | null, error: string | null}}
 *         Current reply status. `status` is one of `idle | pending | complete | failed | unknown`.
 */
export default function useReplyStatus(replyId) {
	const [state, setState] = useState(() => initialState(replyId));

	// Track an active timer so unmount/deps change can cancel it cleanly.
	const timeoutRef = useRef(null);

	useEffect(() => {
		setState(initialState(replyId));
		if (!replyId) {
			return undefined;
		}

		let cancelled = false;
		let unknownPolls = 0;
		let interval = POLL_INTERVAL_MS;
		const startedAt = Date.now();

		const fail = (error) =>
			setState({
				id: replyId,
				status: 'failed',
				aiReplyCommentId: null,
				error,
			});

		const poll = async () => {
			try {
				const response = await apiFetch({
					path: `/ai-feedback/v1/replies/${replyId}/status`,
					method: 'GET',
				});

				if (cancelled) {
					return;
				}

				const next = {
					id: replyId,
					status: response?.status || 'unknown',
					aiReplyCommentId: response?.ai_reply_comment_id ?? null,
					error: response?.error ?? null,
				};

				if (next.status === 'unknown') {
					unknownPolls++;
					if (unknownPolls >= MAX_UNKNOWN_POLLS) {
						fail(
							__(
								'No AI reply was scheduled for this comment.',
								'ai-feedback'
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
							'ai-feedback'
						)
					);
					return;
				}

				setState(next);

				// Keep polling only while the server reports in-flight work.
				if (next.status === 'pending' || next.status === 'unknown') {
					timeoutRef.current = setTimeout(poll, interval);
					interval = Math.min(interval * 2, MAX_POLL_INTERVAL_MS);
				}
			} catch (err) {
				if (cancelled) {
					return;
				}
				fail(
					err?.message ||
						__(
							'Could not check the AI reply status.',
							'ai-feedback'
						)
				);
			}
		};

		poll();

		return () => {
			cancelled = true;
			if (timeoutRef.current) {
				clearTimeout(timeoutRef.current);
				timeoutRef.current = null;
			}
		};
	}, [replyId]);

	// Until the effect resets state for a new ID, report the new ID as pending
	// rather than leaking the previous reply's result for a render.
	const current = state.id === replyId ? state : initialState(replyId);
	const { status, aiReplyCommentId, error } = current;

	return { status, aiReplyCommentId, error };
}
