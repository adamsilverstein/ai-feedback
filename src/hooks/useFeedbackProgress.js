/**
 * useFeedbackProgress hook.
 *
 * Counts how many of a review's notes are addressed. The note records the
 * editor loads hold the live thread and suggestion status, so resolving a
 * thread or accepting a suggestion in the Notes panel counts right away.
 */
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { getNotesQuery } from './useReplyThreads';
import { getFeedbackProgress } from '../utils/progress';

/**
 * @param {Object|null} review Review with `notes`.
 * @return {{ addressed: number, total: number }} Progress counts.
 */
export default function useFeedbackProgress(review) {
	const notes = review?.notes;

	return useSelect(
		(select) => {
			const postId = select(editorStore).getCurrentPostId();
			if (!postId || !notes?.length) {
				return getFeedbackProgress(notes);
			}

			const args = ['root', 'comment', getNotesQuery(postId)];
			const records = select('core').getEntityRecords(...args);
			// A list that is being refetched may be partial, such as just
			// the notes a review added, so it can't show a deletion. Its
			// statuses still count, or a reply would reset the progress
			// until the refetch ends.
			const isSettled =
				select('core').hasFinishedResolution(
					'getEntityRecords',
					args
				) && !select('core').isResolving('getEntityRecords', args);

			return getFeedbackProgress(
				notes,
				Array.isArray(records) ? records : null,
				isSettled
			);
		},
		[notes]
	);
}
