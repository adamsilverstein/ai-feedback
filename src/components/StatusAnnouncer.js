/**
 * Status Announcer Component
 *
 * Announces status changes to screen reader users using WordPress a11y API.
 */
import { useEffect, useRef } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { sprintf, __ } from '@wordpress/i18n';
import { describeProgress } from '../utils/progress';

/**
 * Wait before announcing progress, so the dismissal notice is read first and
 * quick changes, such as a dismissal undone, are announced once.
 */
export const PROGRESS_ANNOUNCE_DELAY = 1000;

/**
 * Status Announcer component.
 *
 * Announces status changes to screen reader users using wp.a11y.speak().
 *
 * @param {Object}      props             Component props.
 * @param {boolean}     props.isReviewing Whether a review is in progress.
 * @param {Object|null} props.lastReview  Last review object with notes.
 * @param {Object|null} props.error       Error object if review failed.
 * @param {Object|null} [props.progress]  `{ addressed, total }` for the last review's notes.
 * @return {null} This component renders nothing.
 */
export default function StatusAnnouncer({
	isReviewing,
	lastReview,
	error,
	progress = null,
}) {
	const previousStateRef = useRef({
		isReviewing: false,
		hasError: false,
		reviewCompleted: false,
	});

	useEffect(() => {
		const prevState = previousStateRef.current;

		// Errors are announced by the error notice. Announcing them here as
		// well meant the notice's speak() overwrote this one.
		if (error && !prevState.hasError) {
			prevState.hasError = true;
			prevState.isReviewing = false;
			prevState.reviewCompleted = false;
		}
		// Announce review started
		else if (isReviewing && !prevState.isReviewing) {
			speak(__('Review in progress. Please wait.', 'fresh-eyes'));
			prevState.isReviewing = true;
			prevState.hasError = false;
			prevState.reviewCompleted = false;
		}
		// Announce review completed. A partial review means a part failed,
		// which the error notice announces instead.
		else if (
			!isReviewing &&
			prevState.isReviewing &&
			!prevState.reviewCompleted &&
			lastReview &&
			!lastReview.is_in_progress &&
			!lastReview.is_partial
		) {
			const noteCount = lastReview.notes?.length || 0;
			speak(
				sprintf(
					/* translators: %d: number of notes created */
					__('Review complete. %d notes created.', 'fresh-eyes'),
					noteCount
				)
			);
			prevState.isReviewing = false;
			prevState.reviewCompleted = true;
			prevState.hasError = false;
		}
		// Clear error state when error is cleared
		else if (!error && prevState.hasError) {
			prevState.hasError = false;
		}
	}, [isReviewing, lastReview, error]);

	useProgressAnnouncement({ isReviewing, lastReview, progress });

	// This component doesn't render anything
	return null;
}

/**
 * Announce when notes of the last review are addressed or restored.
 *
 * The first counts seen for a review are only noted, so loading the editor
 * or finishing a review announces nothing here. A change in the total alone,
 * such as a note deleted in the Notes panel, is noted silently too.
 *
 * @param {Object}      args             Arguments.
 * @param {boolean}     args.isReviewing Whether a review is in progress.
 * @param {Object|null} args.lastReview  Last review.
 * @param {Object|null} args.progress    `{ addressed, total }`.
 */
function useProgressAnnouncement({ isReviewing, lastReview, progress }) {
	const announcedRef = useRef(null);
	const reviewId = lastReview?.review_id;
	const isFinished = !!reviewId && !isReviewing && !lastReview.is_in_progress;
	const addressed = progress?.addressed;
	const total = progress?.total;

	useEffect(() => {
		if (!isFinished || !total) {
			return;
		}

		const announced = announcedRef.current;
		if (
			!announced ||
			announced.reviewId !== reviewId ||
			announced.addressed === addressed
		) {
			announcedRef.current = { reviewId, addressed };
			return;
		}

		const timer = setTimeout(() => {
			announcedRef.current = { reviewId, addressed };
			speak(describeProgress({ addressed, total }));
		}, PROGRESS_ANNOUNCE_DELAY);
		return () => clearTimeout(timer);
	}, [isFinished, reviewId, addressed, total]);
}
