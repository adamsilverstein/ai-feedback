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
import QuickFix from './QuickFix';

/**
 * Feedback item actions.
 *
 * @param {Object}    props            Component props.
 * @param {Object}    props.item       Feedback item.
 * @param {string}    props.title      Display title, used in accessible labels.
 * @param {Function=} props.onNavigate Navigate to a block. Omit to hide "Go to block".
 * @return {Element} Actions component.
 */
export default function FeedbackItemActions({ item, title, onNavigate }) {
	const { setNoteResolved } = useDispatch(STORE_NAME);
	const { createSuccessNotice, createErrorNotice } =
		useDispatch(noticesStore);
	const [isSaving, setIsSaving] = useState(false);

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
					__('Could not update the feedback item.', 'ai-feedback'),
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
		createSuccessNotice(__('Feedback dismissed.', 'ai-feedback'), {
			type: 'snackbar',
			id: `ai-feedback-dismissed-${item.id}`,
			actions: [
				{
					label: __('Undo', 'ai-feedback'),
					onClick: () => updateDismissed(false),
				},
			],
		});
	};

	const handleRestore = () => updateDismissed(false);

	const showQuickFix =
		!!item.quick_fix && !item.is_group && !isDismissed && !!item.block_id;

	return (
		<>
			{showQuickFix && <QuickFix item={item} />}
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
						{__('Go to block', 'ai-feedback')}
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
											'ai-feedback'
										),
										title
									)
								: sprintf(
										/* translators: %s: feedback title */
										__(
											'Dismiss feedback: %s',
											'ai-feedback'
										),
										title
									)
						}
					>
						{isDismissed
							? __('Restore', 'ai-feedback')
							: __('Dismiss', 'ai-feedback')}
					</Button>
				)}
			</div>
		</>
	);
}
