/**
 * Feedback Progress Components
 *
 * A row showing how many of a review's notes are addressed, and the short
 * done state shown once they all are.
 */
import { Button, ProgressBar } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import useStartReview from '../hooks/use-start-review';
import { describeProgress } from '../utils/progress';

/**
 * Progress row.
 *
 * The bar only animates when motion is allowed: ProgressBar skips its
 * transition under `prefers-reduced-motion: reduce`.
 *
 * @param {Object} props          Component props.
 * @param {Object} props.progress `{ addressed, total }`.
 * @return {Element} Progress row.
 */
export function FeedbackProgressRow({ progress }) {
	const { addressed, total } = progress;

	return (
		<div className="ai-feedback-progress-row">
			<p className="ai-feedback-progress-text">
				{describeProgress(progress)}
			</p>
			{/* The text above says the same, so the bar is hidden from screen readers. */}
			<div aria-hidden="true">
				<ProgressBar
					className="ai-feedback-progress-bar"
					value={
						total > 0 ? Math.round((addressed / total) * 100) : 0
					}
				/>
			</div>
		</div>
	);
}

/**
 * Done state, once every note of the review is addressed.
 *
 * @param {Object}     props                Component props.
 * @param {Object}     props.progress       `{ addressed, total }`.
 * @param {() => void} props.onShowFeedback Shows the feedback list again.
 * @return {Element} Done state.
 */
export function FeedbackDone({ progress, onShowFeedback }) {
	const { startReview } = useStartReview();

	return (
		<div className="ai-feedback-done">
			<p className="ai-feedback-done-text">
				<span aria-hidden="true">✓ </span>
				{describeProgress(progress)}
			</p>
			<div className="ai-feedback-done-actions">
				<Button variant="primary" onClick={startReview}>
					{__('Review again', 'fresh-eyes')}
				</Button>
				<Button variant="link" onClick={onShowFeedback}>
					{__('Show feedback', 'fresh-eyes')}
				</Button>
			</div>
		</div>
	);
}
