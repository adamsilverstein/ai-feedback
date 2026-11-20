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
import { STORE_NAME } from '../store';
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
	const isApplied = useSelect(
		(select) =>
			latestOwnerId !== null &&
			!!select(STORE_NAME).isFixApplied(latestOwnerId),
		[latestOwnerId]
	);

	if (!canRequest || sentAt === replies.length) {
		return null;
	}

	const quickReplies = getQuickReplies(
		isEditPending(latest, { status, isApplied })
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
