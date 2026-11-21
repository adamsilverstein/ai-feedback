/**
 * useReplyThreads hook.
 *
 * Loads the reply threads under every AI Feedback note in a post from
 * /ai-feedback/v1/replies. Users reply through the editor's Notes UI, so the
 * hook watches the notes the editor has loaded and refetches when a reply
 * appears there.
 *
 * An AI reply that arrives while the editor is open and proposes an edit is
 * added as a Gutenberg suggestion when Suggestion Mode is on, replacing the
 * thread's earlier suggestion. Replies that were already there when the
 * threads first loaded are left to the sidebar's Preview and Apply, so
 * reopening a post never adds markers by itself.
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import {
	isSuggestionModeAvailable,
	suggestReplyEdit,
	supersedeSuggestions,
} from '../utils/suggestions';
import { getThreadEdits } from '../utils/revisions';
import { mayAwaitAnswer } from '../utils/mentions';

/**
 * AI replies whose edit this editor has already tried to suggest, shared by
 * every hook instance so the sidebar and the reply watcher never both add it.
 */
const suggestedReplyIds = new Set();

/**
 * Pending thread refinements, run one after another.
 */
let queue = Promise.resolve();

/**
 * Retire superseded suggestions in a thread, then suggest its newest edit.
 *
 * The newest edit is suggested on the thread's root note, replacing the
 * root's pending suggestion in one step, so that one is left in place until
 * then. If the newest edit can't be suggested, it is retired like the rest.
 *
 * Runs one thread at a time, so each step sees the block as the last left it.
 *
 * @param {number}      threadId   Root note of the thread.
 * @param {number[]}    superseded Notes whose edits the newest edit replaces.
 * @param {Object|null} reply      AI reply whose edit to suggest, or null.
 * @return {Promise<void>} Resolves once the thread is done.
 */
function refineThread(threadId, superseded, reply) {
	queue = queue
		.then(async () => {
			await supersedeSuggestions(threadId, superseded, {
				keepRoot: !!reply,
			});
			if (reply) {
				await suggestReplyEdit(reply, threadId);
				// A no-op when the root note now holds the newest edit.
				await supersedeSuggestions(threadId, superseded);
			}
		})
		.catch(() => {
			// Each step reports its own failure; keep the queue going.
		});
	return queue;
}

/**
 * Suggest the edits of AI replies that arrived since the threads first
 * loaded, and retire the suggestions they supersede.
 *
 * Only a thread's newest edit is suggested. Earlier suggestions in a thread
 * with a newer edit are retired on every load too, so an older revision can
 * never be accepted, but edits already there on the first load are never
 * suggested.
 *
 * @param {Object<string, ReplyThread>} threads Latest threads.
 * @param {Set<number>|null}            known   Reply IDs seen so far, or null before the first load.
 * @return {Set<number>} Reply IDs seen, including these threads.
 */
export function suggestNewReplyEdits(threads, known) {
	const seen = new Set(known || []);
	for (const [key, thread] of Object.entries(threads || {})) {
		const threadId = Number(key);
		const edits = getThreadEdits(null, thread?.replies);
		const latest = edits[edits.length - 1];
		if (!latest) {
			continue;
		}

		const isNew = !seen.has(latest.ownerId);
		edits.forEach(({ ownerId }) => seen.add(ownerId));

		let reply = null;
		if (known && isNew && !suggestedReplyIds.has(latest.ownerId)) {
			suggestedReplyIds.add(latest.ownerId);
			reply = thread.replies.find((item) => item.id === latest.ownerId);
		}
		if (!isSuggestionModeAvailable()) {
			continue;
		}

		// The root note's quick fix is superseded by any reply edit.
		refineThread(
			threadId,
			[threadId, ...edits.slice(0, -1).map(({ ownerId }) => ownerId)],
			reply
		);
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
 * A reply thread under one AI Feedback note, or a thread a person started
 * in which someone asked Fresh Eyes (`is_mention`).
 *
 * @typedef {Object} ReplyThread
 * @property {Array<Object>} replies          Replies, oldest first: { id, author, content, is_ai, edit }.
 *                                            `edit` is the change an AI reply proposes, or null.
 * @property {number|null}   pending_reply_id User reply awaiting an AI response.
 * @property {boolean}       [is_mention]     Whether a person started the thread and asked Fresh Eyes in it.
 * @property {Object}        [root]           For a mention thread, its opening note: { id, author, content }.
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

	// IDs of the note replies and mentions the editor knows about. This
	// changes when the user replies or asks Fresh Eyes in the Notes UI, which
	// is the cue to refetch.
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
				.filter(mayAwaitAnswer)
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
