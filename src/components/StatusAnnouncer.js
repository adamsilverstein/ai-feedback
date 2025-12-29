/**
 * Status Announcer Component
 *
 * Provides ARIA live region announcements for screen reader users
 * to inform them of review progress and results.
 */
import { useMemo } from '@wordpress/element';
import { sprintf, __ } from '@wordpress/i18n';

/**
 * Status Announcer component.
 *
 * Announces status changes to screen reader users using ARIA live regions.
 *
 * @param {Object}      props             Component props.
 * @param {boolean}     props.isReviewing Whether a review is in progress.
 * @param {Object|null} props.lastReview  Last review object with notes.
 * @param {Object|null} props.error       Error object if review failed.
 * @return {JSX.Element} Status announcer component.
 */
export default function StatusAnnouncer({ isReviewing, lastReview, error }) {
	const announcement = useMemo(() => {
		if (error) {
			/* translators: %s: error message */
			return sprintf(__('Error: %s', 'ai-feedback'), error.message);
		}
		if (isReviewing) {
			return __('Review in progress. Please wait.', 'ai-feedback');
		}
		if (lastReview) {
			const noteCount = lastReview.notes?.length || 0;
			return sprintf(
				/* translators: %d: number of notes created */
				__('Review complete. %d notes created.', 'ai-feedback'),
				noteCount
			);
		}
		return '';
	}, [isReviewing, lastReview, error]);

	return (
		<div
			role="status"
			aria-live="polite"
			aria-atomic="true"
			className="screen-reader-text"
		>
			{announcement}
		</div>
	);
}
