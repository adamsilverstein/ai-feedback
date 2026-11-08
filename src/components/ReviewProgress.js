/**
 * Review Progress Component
 *
 * Shows which step of the review is running, with a determinate progress bar.
 */
import { ProgressBar } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { getReviewStages, getStageProgress } from '../store/review-stages';

/**
 * Review Progress component.
 *
 * Stage changes come from the store and reflect real steps. Inside a stage the
 * bar eases forward with elapsed time, since the AI call can't report progress.
 *
 * @param {Object} props          Component props.
 * @param {string} props.stageKey Current stage key.
 * @return {Element|null} Progress component.
 */
export default function ReviewProgress({ stageKey }) {
	const stages = getReviewStages();
	const stageIndex = stages.findIndex((stage) => stage.key === stageKey);
	const stage = stages[stageIndex];

	// Mounted when the review starts, so mount time is the review's start.
	const [reviewStartedAt] = useState(() => Date.now());
	const [stageStartedAt, setStageStartedAt] = useState(reviewStartedAt);
	const [now, setNow] = useState(reviewStartedAt);

	useEffect(() => {
		const interval = setInterval(() => setNow(Date.now()), 1000);
		return () => clearInterval(interval);
	}, []);

	useEffect(() => {
		const stageStart = Date.now();
		setStageStartedAt(stageStart);
		setNow(stageStart);
	}, [stageKey]);

	if (!stage) {
		return null;
	}

	// The bar eases within the current step; the counter shows total time.
	const progress = Math.round(getStageProgress(stage, now - stageStartedAt));
	const elapsedSeconds = Math.floor((now - reviewStartedAt) / 1000);

	const stepText = sprintf(
		/* translators: 1: current step number, 2: total number of steps */
		__('Step %1$d of %2$d', 'ai-feedback'),
		stageIndex + 1,
		stages.length
	);

	return (
		<div className="ai-feedback-review-progress">
			<ProgressBar
				className="ai-feedback-review-progress-bar"
				value={progress}
				aria-label={__('Review progress', 'ai-feedback')}
				aria-valuetext={sprintf(
					/* translators: 1: "Step 1 of 3", 2: description of the step */
					__('%1$s: %2$s', 'ai-feedback'),
					stepText,
					stage.label
				)}
			/>
			<p className="ai-feedback-review-progress-label" role="status">
				<span className="ai-feedback-review-progress-step">
					{stepText}
				</span>{' '}
				{stage.label}
			</p>
			{elapsedSeconds >= 5 && (
				<p
					className="ai-feedback-review-progress-elapsed description"
					aria-hidden="true"
				>
					{sprintf(
						/* translators: %d: number of seconds */
						__('%ds elapsed', 'ai-feedback'),
						elapsedSeconds
					)}
				</p>
			)}
		</div>
	);
}
