/**
 * useReplyThreads hook.
 *
 * Loads the reply threads under every AI Feedback note in a post from
 * /ai-feedback/v1/replies. Users reply through the editor's Notes UI, so the
 * hook watches the notes the editor has loaded and refetches when a reply
 * appears there.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

/**
 * Query the editor's Notes UI uses to load a post's notes.
 *
 * @param {number} postId Post ID.
 * @return {Object} Entity query args.
 */
export function getNotesQuery(postId) {
	return { post: postId, type: 'note', status: 'all', per_page: -1 };
}

/**
 * A reply thread under one AI Feedback note.
 *
 * @typedef {Object} ReplyThread
 * @property {Array<Object>} replies          Replies, oldest first: { id, author, content, is_ai }.
 * @property {number|null}   pending_reply_id User reply awaiting an AI response.
 */

/**
 * @param {number|null} postId Post ID, or null when the post is unsaved.
 * @return {{threads: Object<string, ReplyThread>, onReplyComplete: Function}}
 *         Threads keyed by AI note ID, and a callback to run once an AI reply is saved.
 */
export default function useReplyThreads(postId) {
	const [threads, setThreads] = useState({});
	const [version, setVersion] = useState(0);
	const { invalidateResolution } = useDispatch('core');

	// IDs of the note replies the editor knows about. This changes when the
	// user replies in the Notes UI, which is the cue to refetch.
	const replySignature = useSelect(
		(select) => {
			if (!postId) {
				return '';
			}
			const notes = select('core').getEntityRecords(
				'root',
				'comment',
				getNotesQuery(postId)
			);
			return (notes || [])
				.filter((note) => note.parent > 0)
				.map((note) => note.id)
				.join(',');
		},
		[postId]
	);

	useEffect(() => {
		if (!postId) {
			setThreads({});
			return undefined;
		}

		let cancelled = false;
		apiFetch({ path: `/ai-feedback/v1/replies?post=${postId}` })
			.then((response) => {
				if (!cancelled) {
					setThreads(response?.threads || {});
				}
			})
			.catch(() => {
				// Keep the threads already shown; the next change retries.
			});

		return () => {
			cancelled = true;
		};
	}, [postId, replySignature, version]);

	const onReplyComplete = useCallback(() => {
		setVersion((current) => current + 1);
		// Let the Notes UI pick up the new AI reply too.
		invalidateResolution?.('getEntityRecords', [
			'root',
			'comment',
			getNotesQuery(postId),
		]);
	}, [postId, invalidateResolution]);

	return { threads, onReplyComplete };
}
