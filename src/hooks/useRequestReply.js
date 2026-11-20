/**
 * useRequestReply hook.
 *
 * Posts a reply in a note's thread for the author, the same way the Notes UI
 * does, so the AI answers it like any other reply: it shows in the thread,
 * waits on the AI with the usual pending state, and keeps the reply limits
 * and retries.
 */
import { useDispatch, useSelect } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * @param {Object} note Note to reply to, with `id` and, for a secondary note, `parent`.
 * @return {{requestReply: (content: string) => Promise<boolean>, isRequesting: boolean, canRequest: boolean}}
 *         Post a reply with the given text, whether one is being posted, and
 *         whether the post is saved so a reply can be posted.
 */
export default function useRequestReply(note) {
	const { saveEntityRecord } = useDispatch('core');
	const { createErrorNotice } = useDispatch(noticesStore);
	const [isRequesting, setIsRequesting] = useState(false);
	const postId = useSelect(
		(select) => select('core/editor').getCurrentPostId(),
		[]
	);

	const requestReply = async (content) => {
		setIsRequesting(true);
		try {
			await saveEntityRecord(
				'root',
				'comment',
				{
					post: postId,
					// A secondary note is itself a reply; answer in its thread.
					parent: note.parent || note.id,
					type: 'note',
					status: 'hold',
					content,
				},
				{ throwOnError: true }
			);
			return true;
		} catch (error) {
			createErrorNotice(
				error?.message || __('Could not send the reply.', 'fresh-eyes'),
				{ type: 'snackbar' }
			);
			return false;
		} finally {
			setIsRequesting(false);
		}
	};

	return { requestReply, isRequesting, canRequest: !!postId && !!note?.id };
}
