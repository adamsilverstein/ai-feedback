/**
 * QuickReplies component.
 *
 * One-click replies under the latest AI message in a thread, such as
 * "Shorter" or "Why?". Each posts its prefilled text as a reply through the
 * normal reply path, so it shows in the thread and the AI answers it like a
 * typed reply.
 */
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as blocksStore } from '@wordpress/blocks';
import { STORE_NAME } from '../store';
import { getRichTextAttributeKey } from '../utils/quick-fix';
import { isEditApplied } from '../utils/reply-edit';
import { isBlockAction } from '../utils/is-reply-edit';
import { findNoteBlock, getBlockActionState } from '../utils/block-action';
import useRequestReply from '../hooks/useRequestReply';
import useSuggestionStatus from '../hooks/useSuggestionStatus';
import {
	getLatestEdit,
	getQuickReplies,
	isEditPending,
} from '../utils/revisions';

/**
 * @param {Object}   props         Component props.
 * @param {Object}   props.note    The thread's note, with `id` and `block_id`.
 * @param {Object[]} props.replies Replies in the thread, oldest first.
 * @return {Element|null} Quick replies, or null when there are none to offer.
 */
export default function QuickReplies({ note, replies }) {
	const { requestReply, isRequesting, canRequest } = useRequestReply(note);
	// Reply count when a quick reply was sent; hides the chips until the
	// thread shows it, so it can't be sent twice.
	const [sentAt, setSentAt] = useState(null);

	const latest = getLatestEdit(note, replies);
	const latestOwnerId = latest?.ownerId ?? null;
	const status = useSuggestionStatus(
		latestOwnerId === note.id ? note : { id: latestOwnerId }
	);
	const latestEdit = latest?.edit ?? null;
	const isApplied = useSelect(
		(select) => {
			if (latestOwnerId === null) {
				return false;
			}
			// A structural edit is judged by the blocks, as in BlockAction.
			if (isBlockAction(latestEdit)) {
				const selectors = select(blockEditorStore);
				const { state } = getBlockActionState(
					selectors,
					latestEdit,
					findNoteBlock(selectors, note.block_id, note.id)
				);
				return (
					state === 'applied' ||
					(state === 'missing' && latestEdit.type === 'remove_block')
				);
			}
			if (select(STORE_NAME).isFixApplied(latestOwnerId)) {
				return true;
			}
			// The applied flag only lasts the session; after a reload the
			// block text tells, as in QuickFix.
			const block = select(blockEditorStore).getBlock(note.block_id);
			const key = block
				? getRichTextAttributeKey(
						select(blocksStore).getBlockType(block.name)
					)
				: null;
			return (
				!!key &&
				latestOwnerId !== note.id &&
				isEditApplied(block.attributes[key], latestEdit)
			);
		},
		[latestOwnerId, latestEdit, note.id, note.block_id]
	);

	if (!canRequest || sentAt === replies.length) {
		return null;
	}

	const quickReplies = getQuickReplies(
		isEditPending(latest, { status, isApplied }),
		isBlockAction(latestEdit)
	);

	const send = async (message) => {
		setSentAt(replies.length);
		if (!(await requestReply(message))) {
			setSentAt(null);
		}
	};

	return (
		<div
			className="ai-feedback-quick-replies"
			role="group"
			aria-label={__('Quick replies', 'fresh-eyes')}
		>
			{quickReplies.map(({ key, label, message }) => (
				<Button
					key={key}
					variant="secondary"
					size="small"
					className="ai-feedback-quick-reply"
					onClick={() => send(message)}
					disabled={isRequesting}
					accessibleWhenDisabled
				>
					{label}
				</Button>
			))}
		</div>
	);
}
