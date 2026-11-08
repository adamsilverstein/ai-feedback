/**
 * Review stages.
 *
 * Each stage maps to a real step in the startReview action. The AI request is
 * a single synchronous REST call, so its duration can't be measured: the
 * `start`/`end` range only bounds how far the bar may creep while waiting.
 */
import { __ } from '@wordpress/i18n';

export const STAGE_PREPARING = 'preparing';
export const STAGE_ANALYZING = 'analyzing';
export const STAGE_ATTACHING = 'attaching';

/**
 * Get the ordered list of review stages.
 *
 * @return {Array<{key: string, label: string, start: number, end: number}>} Review stages.
 */
export function getReviewStages() {
	return [
		{
			key: STAGE_PREPARING,
			label: __('Gathering earlier feedback…', 'ai-feedback'),
			start: 5,
			end: 15,
		},
		{
			key: STAGE_ANALYZING,
			label: __('AI is analyzing your content…', 'ai-feedback'),
			start: 20,
			end: 85,
		},
		{
			key: STAGE_ATTACHING,
			label: __('Attaching notes to blocks…', 'ai-feedback'),
			start: 90,
			end: 100,
		},
	];
}

/**
 * Time constant (ms) for the bar's creep within a stage. After this long the
 * bar has covered ~63% of the stage's range; it never reaches the end.
 */
const CREEP_TIME_CONSTANT = 20000;

/**
 * Calculate the progress bar value for a stage after a given elapsed time.
 *
 * Starts at the stage's `start` and eases toward, but never reaches, its
 * `end`, so a long AI call keeps moving without claiming to be done.
 *
 * @param {Object} stage     Stage from getReviewStages().
 * @param {number} elapsedMs Milliseconds since the stage began.
 * @return {number} Progress value between 0 and 100.
 */
export function getStageProgress(stage, elapsedMs) {
	if (!stage) {
		return 0;
	}

	const elapsed = Math.max(0, elapsedMs);
	const fraction = 1 - Math.exp(-elapsed / CREEP_TIME_CONSTANT);
	const range = stage.end - stage.start;

	// Leave a small gap so the bar never looks finished mid-stage.
	return Math.min(stage.start + range * fraction, stage.end - 1);
}
