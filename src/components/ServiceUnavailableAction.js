/**
 * Service Unavailable Action Component
 */
import { Button } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Automatic retries before asking the user to try again later.
 */
export const MAX_AUTO_RETRIES = 2;

/**
 * Default and longest seconds to wait before an automatic retry, matching
 * the server's DEFAULT_RETRY_AFTER and MAX_RETRY_AFTER.
 */
const DEFAULT_RETRY_AFTER = 30;
const MAX_RETRY_AFTER = 300;

/**
 * Retry controls shown when a review failed because the AI service was down.
 *
 * Retries automatically after the delay the server suggests, up to
 * MAX_AUTO_RETRIES times in a row, and always offers a manual retry.
 * Retries only happen while the panel is open.
 *
 * @param {Object}   props         Component props.
 * @param {Object}   props.error   Error with `data.retry_after` in seconds.
 * @param {number}   props.retries Consecutive unavailable failures, including this one.
 * @param {Function} props.onRetry Starts the review again. Returns (or resolves to) false when the review can't start, such as when the post is empty.
 * @return {Element} Retry controls.
 */
export default function ServiceUnavailableAction({ error, retries, onRetry }) {
	const [isCancelled, setIsCancelled] = useState(false);
	const [isBlocked, setIsBlocked] = useState(false);
	const onRetryRef = useRef(onRetry);
	onRetryRef.current = onRetry;

	const suggested = Number(error?.data?.retry_after);
	const retryAfter =
		suggested > 0
			? Math.min(suggested, MAX_RETRY_AFTER)
			: DEFAULT_RETRY_AFTER;
	const isAutoRetrying =
		retries <= MAX_AUTO_RETRIES && !isCancelled && !isBlocked;

	const retry = () => {
		Promise.resolve(onRetryRef.current()).then((started) => {
			if (started === false) {
				setIsBlocked(true);
			}
		});
	};
	const retryRef = useRef(retry);
	retryRef.current = retry;

	useEffect(() => {
		if (!isAutoRetrying) {
			return;
		}
		const timer = setTimeout(() => retryRef.current(), retryAfter * 1000);
		return () => clearTimeout(timer);
	}, [isAutoRetrying, retryAfter]);

	if (isBlocked) {
		return (
			<p className="ai-feedback-error-help">
				{__(
					'Add some content and save the post, then run the review again.',
					'ai-feedback'
				)}
			</p>
		);
	}

	return (
		<>
			<p className="ai-feedback-error-help">
				{isAutoRetrying
					? sprintf(
							/* translators: %d: number of seconds. */
							_n(
								'Retrying automatically in %d second.',
								'Retrying automatically in %d seconds.',
								retryAfter,
								'ai-feedback'
							),
							retryAfter
						)
					: __(
							'The AI service is still unavailable. Try again in a few minutes.',
							'ai-feedback'
						)}
			</p>
			<Button
				variant="link"
				onClick={retry}
				className="ai-feedback-error-action"
			>
				{__('Retry now', 'ai-feedback')}
			</Button>
			{isAutoRetrying && (
				<Button
					variant="link"
					onClick={() => setIsCancelled(true)}
					className="ai-feedback-error-action"
				>
					{__('Cancel automatic retry', 'ai-feedback')}
				</Button>
			)}
		</>
	);
}
