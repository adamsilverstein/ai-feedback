/**
 * Feedback Item Actions Component
 *
 * One-click actions shown on each feedback item in the sidebar.
 */
import { Button } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { arrowRight } from '@wordpress/icons';
import { store as noticesStore } from '@wordpress/notices';
import { STORE_NAME } from '../store';
import useSuggestionStatus from '../hooks/useSuggestionStatus';
import useRequestReply from '../hooks/useRequestReply';
import { hasReplyEdit } from '../utils/revisions';
import { getFeedbackContent } from '../utils/feedback-content';
import QuickFix from './QuickFix';
import BlockAction from './BlockAction';
import BlockChange from './BlockChange';
import { isBlockAction } from '../utils/is-reply-edit';
import { isBlockChange } from '../utils/block-change';

/**
 * Whether a note can offer "Make this change".
 *
 * Notes with a suggestion but no quick fix, structural edit or formatting
 * fix qualify, while nothing in their
 * thread already answers it: no reply is waiting on the AI and no AI reply
 * has proposed an edit.
 *
 * @param {Object} item     Feedback item.
 * @param {Object} [thread] Reply thread for the item's note.
 * @return {boolean} True when the button should show.
 */
export function canRequestChange(item, thread) {
	return (
		!!item?.id &&
		!!item.block_id &&
		!item.is_group &&
		!item.is_resolved &&
		!item.quick_fix &&
		!item.block_action &&
		!item.block_change &&
		!!getFeedbackContent(item).suggestion &&
		!thread?.pending_reply_id &&
		!(thread?.replies || []).some((reply) => reply.is_ai && reply.edit)
	);
}

/**
 * Feedback item actions.
 *
 * @param {Object}    props            Component props.
 * @param {Object}    props.item       Feedback item.
 * @param {string}    props.title      Display title, used in accessible labels.
 * @param {Function=} props.onNavigate Navigate to a block. Omit to hide "Go to block".
 * @param {Object=}   props.thread     Reply thread for the item's note.
 * @return {Element} Actions component.
 */
export default function FeedbackItemActions({
	item,
	title,
	onNavigate,
	thread,
}) {
	const { setNoteResolved } = useDispatch(STORE_NAME);
	const { createSuccessNotice, createErrorNotice } =
		useDispatch(noticesStore);
	const [isSaving, setIsSaving] = useState(false);
	const { requestReply, isRequesting, canRequest } = useRequestReply(item);

	const suggestionStatus = useSuggestionStatus(item);

	const isDismissed = !!item.is_resolved;
	// Dismissing would leave a pending suggestion live in the text; it has to
	// be rejected from the Notes sidebar instead.
	const canDismiss =
		!!item.id && (isDismissed || suggestionStatus !== 'pending');

	const updateDismissed = async (dismissed) => {
		setIsSaving(true);
		try {
			await setNoteResolved(item.id, dismissed);
			return true;
		} catch (error) {
			createErrorNotice(
				error?.message ||
					__('Could not update the feedback item.', 'fresh-eyes'),
				{ type: 'snackbar' }
			);
			return false;
		} finally {
			setIsSaving(false);
		}
	};

	const handleDismiss = async () => {
		if (!(await updateDismissed(true))) {
			return;
		}
		createSuccessNotice(__('Feedback dismissed.', 'fresh-eyes'), {
			type: 'snackbar',
			id: `ai-feedback-dismissed-${item.id}`,
			actions: [
				{
					label: __('Undo', 'fresh-eyes'),
					onClick: () => updateDismissed(false),
				},
			],
		});
	};

	const handleRestore = () => updateDismissed(false);

	const handleRequestChange = () =>
		requestReply(__('Please make this change.', 'fresh-eyes'));

	const showQuickFix =
		!!item.quick_fix && !item.is_group && !isDismissed && !!item.block_id;
	const showBlockAction =
		isBlockAction(item.block_action) &&
		!item.is_group &&
		!isDismissed &&
		!!item.block_id;
	// A note carries one change: the parser keeps a structural edit over a
	// formatting fix, and either over a quick fix.
	const showBlockChange =
		!showBlockAction &&
		isBlockChange(item.block_change) &&
		!item.is_group &&
		!isDismissed &&
		!!item.block_id;

	return (
		<>
			{showBlockAction && (
				<BlockAction
					item={item}
					action={item.block_action}
					// Any reply edit in the thread revises it.
					isSuperseded={hasReplyEdit(thread?.replies)}
				/>
			)}
			{showBlockChange && (
				<BlockChange
					item={item}
					isSuperseded={hasReplyEdit(thread?.replies)}
				/>
			)}
			{!showBlockAction && !showBlockChange && showQuickFix && (
				<QuickFix
					item={item}
					// Any reply edit in the thread revises the review's quick
					// fix, including a secondary note's.
					isSuperseded={hasReplyEdit(thread?.replies)}
				/>
			)}
			<div className="ai-feedback-item-actions">
				{onNavigate && item.block_id && (
					<Button
						variant="secondary"
						size="small"
						icon={arrowRight}
						iconPosition="right"
						onClick={() => onNavigate(item.block_id)}
						className="go-to-block-button"
					>
						{__('Go to block', 'fresh-eyes')}
					</Button>
				)}
				{canRequest && canRequestChange(item, thread) && (
					<Button
						variant="secondary"
						size="small"
						onClick={handleRequestChange}
						isBusy={isRequesting}
						disabled={isRequesting}
						accessibleWhenDisabled
						className="ai-feedback-request-change"
					>
						{__('Make this change', 'fresh-eyes')}
					</Button>
				)}
				{canDismiss && (
					<Button
						variant="tertiary"
						size="small"
						onClick={isDismissed ? handleRestore : handleDismiss}
						isBusy={isSaving}
						disabled={isSaving}
						accessibleWhenDisabled
						aria-label={
							isDismissed
								? sprintf(
										/* translators: %s: feedback title */
										__(
											'Restore feedback: %s',
											'fresh-eyes'
										),
										title
									)
								: sprintf(
										/* translators: %s: feedback title */
										__(
											'Dismiss feedback: %s',
											'fresh-eyes'
										),
										title
									)
						}
					>
						{isDismissed
							? __('Restore', 'fresh-eyes')
							: __('Dismiss', 'fresh-eyes')}
					</Button>
				)}
			</div>
		</>
	);
}
