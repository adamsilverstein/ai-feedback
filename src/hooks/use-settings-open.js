/**
 * WordPress dependencies
 */
import { useEffect, useRef, useState } from '@wordpress/element';

/**
 * Track whether the Review Settings panel is open.
 *
 * Settings start open until the post has a review, collapse while a
 * review runs, and reopen when a first review fails so the user can
 * adjust them and retry.
 *
 * @param {Object|null} lastReview  The latest review, if any.
 * @param {boolean}     isReviewing Whether a review is running.
 * @return {[boolean, (isOpen: boolean) => void]} Open state and its setter.
 */
export default function useSettingsOpen(lastReview, isReviewing) {
	const [isOpen, setIsOpen] = useState(!lastReview);

	// A saved review loads after the sidebar renders, so collapse the
	// settings when one arrives. If the user opened them while it was
	// still loading, they collapse under them; that window is short.
	const hadReview = useRef(!!lastReview);
	useEffect(() => {
		if (lastReview && !hadReview.current) {
			setIsOpen(false);
		}
		hadReview.current = !!lastReview;
	}, [lastReview]);

	const wasReviewing = useRef(isReviewing);
	useEffect(() => {
		if (wasReviewing.current === isReviewing) {
			return;
		}
		wasReviewing.current = isReviewing;

		// Settings can't change a running review, so collapse them to
		// keep the progress near the top of the sidebar.
		if (isReviewing) {
			setIsOpen(false);
		} else if (!lastReview) {
			// A failed first review returns to the empty state.
			setIsOpen(true);
		}
	}, [isReviewing, lastReview]);

	return [isOpen, setIsOpen];
}
