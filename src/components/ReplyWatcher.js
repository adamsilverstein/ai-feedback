/**
 * Reply watcher.
 *
 * Polls for pending AI replies while the sidebar is closed, so a reply written
 * in the Notes panel gets its answer without reopening AI Feedback.
 */
import { useEffect } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import useReplyStatus from '../hooks/useReplyStatus';
import useReplyThreads, { getNotesQuery } from '../hooks/useReplyThreads';

/**
 * Waits for one AI reply.
 *
 * @param {Object}   props            Component props.
 * @param {number}   props.replyId    User reply awaiting an AI response.
 * @param {Function} props.onComplete Called once the AI reply is saved.
 * @return {null} Renders nothing.
 */
function PendingReply({ replyId, onComplete }) {
	const { status } = useReplyStatus(replyId);

	useEffect(() => {
		if (status === 'complete') {
			onComplete();
		}
	}, [status, onComplete]);

	return null;
}

/**
 * Watches the post's threads for pending AI replies.
 *
 * @param {Object} props        Component props.
 * @param {number} props.postId Post ID.
 * @return {Element[]} One watcher per pending reply.
 */
function ThreadWatcher({ postId }) {
	const { threads, onReplyComplete } = useReplyThreads(postId);

	return Object.values(threads)
		.map((thread) => thread.pending_reply_id)
		.filter(Boolean)
		.map((replyId) => (
			<PendingReply
				key={replyId}
				replyId={replyId}
				onComplete={onReplyComplete}
			/>
		));
}

/**
 * Reply watcher component.
 *
 * @return {Element|null} Watcher, or null while the post has no note replies.
 */
export default function ReplyWatcher() {
	const { postId, hasReplies } = useSelect((select) => {
		const id = select('core/editor').getCurrentPostId();
		const notes = id
			? select('core').getEntityRecords(
					'root',
					'comment',
					getNotesQuery(id)
				)
			: null;
		return {
			postId: id,
			hasReplies: (notes || []).some((note) => note.parent > 0),
		};
	}, []);

	return hasReplies ? <ThreadWatcher postId={postId} /> : null;
}
