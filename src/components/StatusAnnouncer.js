/**
 * Status Announcer Component
 *
 * Announces status changes to screen reader users using WordPress a11y API.
 */
import { useEffect, useRef } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { sprintf, __ } from '@wordpress/i18n';

/**
 * Status Announcer component.
 *
 * Announces status changes to screen reader users using wp.a11y.speak().
 *
 * @param {Object}      props             Component props.
 * @param {boolean}     props.isReviewing Whether a review is in progress.
 * @param {Object|null} props.lastReview  Last review object with notes.
 * @param {Object|null} props.error       Error object if review failed.
 * @return {null} This component renders nothing.
 */
export default function StatusAnnouncer({ isReviewing, lastReview, error }) {
	const previousStateRef = useRef({
		isReviewing: false,
		hasError: false,
		reviewCompleted: false,
	});

	useEffect(() => {
		const prevState = previousStateRef.current;

		// Announce error
		if (error && !prevState.hasError) {
			/* translators: %s: error message */
			speak(sprintf(__('Error: %s', 'ai-feedback'), error.message));
			prevState.hasError = true;
			prevState.isReviewing = false;
			prevState.reviewCompleted = false;
		}
		// Announce review started
		else if (isReviewing && !prevState.isReviewing) {
			speak(__('Review in progress. Please wait.', 'ai-feedback'));
			prevState.isReviewing = true;
			prevState.hasError = false;
			prevState.reviewCompleted = false;
		}
		// Announce review completed
		else if (
			!isReviewing &&
			prevState.isReviewing &&
			!prevState.reviewCompleted &&
			lastReview
		) {
			const noteCount = lastReview.notes?.length || 0;
			speak(
				sprintf(
					/* translators: %d: number of notes created */
					__('Review complete. %d notes created.', 'ai-feedback'),
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

	// This component doesn't render anything
	return null;
}
