/**
 * useReplyThreads hook.
 *
 * Loads the reply threads under every AI Feedback note in a post from
 * /ai-feedback/v1/replies. Users reply through the editor's Notes UI, so the
 * hook watches the notes the editor has loaded and refetches when a reply
 * appears there.
 *
 * An AI reply that arrives while the editor is open and proposes an edit is
 * added as a Gutenberg suggestion when Suggestion Mode is on. Replies that
 * were already there when the threads first loaded are left to the sidebar's
 * Preview and Apply, so reopening a post never adds markers by itself.
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { suggestReplyEdit } from '../utils/suggestions';
import { isReplyEdit } from '../utils/is-reply-edit';

/**
 * AI replies whose edit this editor has already tried to suggest, shared by
 * every hook instance so the sidebar and the reply watcher never both add it.
 */
const suggestedReplyIds = new Set();

/**
 * List the AI replies with an edit in a set of threads.
 *
 * @param {Object<string, ReplyThread>} threads Threads keyed by root note ID.
 * @return {Array<{reply: Object, threadId: number}>} Replies with their thread's root note ID.
 */
export function getReplyEdits(threads) {
	return Object.entries(threads || {}).flatMap(([threadId, thread]) =>
		(thread?.replies || [])
			.filter((reply) => reply.is_ai && isReplyEdit(reply.edit))
			.map((reply) => ({ reply, threadId: Number(threadId) }))
	);
}

/**
 * Suggest the edits of AI replies that arrived since the threads first loaded.
 *
 * @param {Object<string, ReplyThread>} threads Latest threads.
 * @param {Set<number>|null}            known   Reply IDs seen so far, or null before the first load.
 * @return {Set<number>} Reply IDs seen, including these threads.
 */
export function suggestNewReplyEdits(threads, known) {
	const seen = new Set(known || []);
	for (const { reply, threadId } of getReplyEdits(threads)) {
		if (seen.has(reply.id)) {
			continue;
		}
		seen.add(reply.id);
		if (known && !suggestedReplyIds.has(reply.id)) {
			suggestedReplyIds.add(reply.id);
			suggestReplyEdit(reply, threadId);
		}
	}
	return seen;
}

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
 * @property {Array<Object>} replies          Replies, oldest first: { id, author, content, is_ai, edit }.
 *                                            `edit` is the change an AI reply proposes, or null.
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
	const knownRepliesRef = useRef(null);
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
					const latest = response?.threads || {};
					knownRepliesRef.current = suggestNewReplyEdits(
						latest,
						knownRepliesRef.current
					);
					setThreads(latest);
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
