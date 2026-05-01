/**
 * useReplyStatus hook.
 *
 * Polls /ai-feedback/v1/replies/{id}/status for a given user reply comment
 * and returns its current processing state. Polling stops once the server
 * reports a terminal state (`complete` or `failed`) or the component unmounts.
 *
 * Terminal statuses are cached so re-renders don't spawn new polls.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const POLL_INTERVAL_MS = 2000;

/**
 * @param {number|null} replyId User reply comment ID to poll for, or null/0 to disable.
 * @return {{status: string, aiReplyCommentId: number | null, error: string | null}}
 *         Current reply status. `status` is one of `idle | pending | complete | failed | unknown`.
 */
export default function useReplyStatus(replyId) {
	const [state, setState] = useState(() => ({
		status: replyId ? 'pending' : 'idle',
		aiReplyCommentId: null,
		error: null,
	}));

	// Track an active timer so unmount/deps change can cancel it cleanly.
	const timeoutRef = useRef(null);

	useEffect(() => {
		if (!replyId) {
			setState({ status: 'idle', aiReplyCommentId: null, error: null });
			return undefined;
		}

		// Show the pending state synchronously so the spinner is visible
		// before the first poll returns (otherwise there is a ~2s blank gap).
		setState((prev) =>
			prev.status === 'pending' && prev.aiReplyCommentId === null
				? prev
				: { status: 'pending', aiReplyCommentId: null, error: null }
		);

		let cancelled = false;

		const clearPendingTimer = () => {
			if (timeoutRef.current) {
				clearTimeout(timeoutRef.current);
				timeoutRef.current = null;
			}
		};

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
					status: response.status || 'unknown',
					aiReplyCommentId: response.ai_reply_comment_id || null,
					error: response.error || null,
				};

				setState(next);

				// Keep polling only while the server reports in-flight work.
				if (next.status === 'pending' || next.status === 'unknown') {
					timeoutRef.current = setTimeout(poll, POLL_INTERVAL_MS);
				}
			} catch (err) {
				if (cancelled) {
					return;
				}
				setState({
					status: 'failed',
					aiReplyCommentId: null,
					error: err?.message || 'Poll request failed.',
				});
			}
		};

		poll();

		return () => {
			cancelled = true;
			clearPendingTimer();
		};
	}, [replyId]);

	return state;
}
