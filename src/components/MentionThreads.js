/**
 * MentionThreads component.
 *
 * Lists the threads a person started in which someone asked Fresh Eyes by
 * typing `@fresh-eyes` in a note, with Fresh Eyes' answers. An answer that
 * proposes a change shows it with Preview and Apply, like a reply to
 * feedback, and is only applied when the author chooses to.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Button, PanelBody } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as editorStore } from '@wordpress/editor';
import FeedbackReplies from './FeedbackReplies';
import useReplyThreads from '../hooks/useReplyThreads';
import { findNoteBlock } from '../utils/block-action';
import { navigateToBlock } from '../utils/navigate-to-block';
import { MENTION_HANDLE } from '../utils/mentions';

/**
 * Get the mention threads, oldest thread first.
 *
 * @param {Object} threads Reply threads keyed by root note ID.
 * @return {Array<[number, Object]>} Root note ID and thread pairs.
 */
export function getMentionThreads(threads) {
	return Object.entries(threads || {})
		.filter(([, thread]) => thread?.is_mention)
		.map(([id, thread]) => [Number(id), thread])
		.sort(([a], [b]) => a - b);
}

/**
 * One conversation with Fresh Eyes.
 *
 * @param {Object}     props                 Component props.
 * @param {number}     props.id              Root note ID.
 * @param {Object}     props.thread          Thread from /replies.
 * @param {() => void} props.onReplyComplete Called once a pending answer is saved.
 * @return {Element} Thread.
 */
function MentionThread({ id, thread, onReplyComplete }) {
	// The note's block, found through its `metadata.noteId` link.
	const blockId = useSelect(
		(select) => findNoteBlock(select(blockEditorStore), null, id),
		[id]
	);

	return (
		<li className="ai-feedback-mention-thread">
			<p className="ai-feedback-mention-question">
				<span className="ai-feedback-reply-author">
					{thread.root?.author}
				</span>
				<span className="ai-feedback-reply-content">
					{thread.root?.content}
				</span>
			</p>
			{blockId && (
				<Button
					variant="link"
					className="ai-feedback-mention-go-to"
					onClick={() => navigateToBlock(blockId, id)}
				>
					{__('Go to block', 'fresh-eyes')}
				</Button>
			)}
			<FeedbackReplies
				replies={thread.replies || []}
				pendingReplyId={thread.pending_reply_id || null}
				onReplyComplete={onReplyComplete}
				note={{ id, block_id: blockId, is_mention: true }}
			/>
		</li>
	);
}

/**
 * Mention threads panel.
 *
 * @return {Element} Panel listing the post's conversations with Fresh Eyes.
 */
export default function MentionThreads() {
	const postId = useSelect(
		(select) => select(editorStore).getCurrentPostId(),
		[]
	);
	const { threads, onReplyComplete } = useReplyThreads(postId);
	const mentionThreads = getMentionThreads(threads);

	return (
		<PanelBody
			title={__('Ask Fresh Eyes', 'fresh-eyes')}
			// Opens once there is a conversation, then stays as the user left it.
			initialOpen={mentionThreads.length > 0}
			key={mentionThreads.length > 0 ? 'threads' : 'empty'}
		>
			<p className="ai-feedback-mention-hint">
				{sprintf(
					/* translators: %s: the handle to type, @fresh-eyes. */
					__(
						'Select text or blocks, add a note and type %s with your request, such as "simplify this" or "adjust the tone". The answer appears in the note thread, and any change is offered for you to review.',
						'fresh-eyes'
					),
					MENTION_HANDLE
				)}
			</p>
			{mentionThreads.length > 0 && (
				<ul
					className="ai-feedback-mention-threads"
					aria-label={__('Questions for Fresh Eyes', 'fresh-eyes')}
				>
					{mentionThreads.map(([id, thread]) => (
						<MentionThread
							key={id}
							id={id}
							thread={thread}
							onReplyComplete={onReplyComplete}
						/>
					))}
				</ul>
			)}
		</PanelBody>
	);
}
