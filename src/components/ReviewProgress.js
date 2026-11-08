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

	const [elapsedMs, setElapsedMs] = useState(0);

	useEffect(() => {
		const startedAt = Date.now();
		setElapsedMs(0);

		const interval = setInterval(() => {
			setElapsedMs(Date.now() - startedAt);
		}, 1000);

		return () => clearInterval(interval);
	}, [stageKey]);

	if (!stage) {
		return null;
	}

	const progress = Math.round(getStageProgress(stage, elapsedMs));
	const elapsedSeconds = Math.floor(elapsedMs / 1000);

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
				aria-valuetext={`${stepText}: ${stage.label}`}
			/>
			<p className="ai-feedback-review-progress-label">
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
