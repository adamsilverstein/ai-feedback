/**
 * Quick Fix Component
 *
 * Preview and apply an AI quick fix (a plain text find/replace) to a block.
 */
import { Button, Modal } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as blocksStore } from '@wordpress/blocks';
import { store as noticesStore } from '@wordpress/notices';
import { STORE_NAME } from '../store';
import {
	applyFixToContent,
	getFixPreview,
	getRichTextAttributeKey,
} from '../utils/quick-fix';

/**
 * Describe a quick fix in one short sentence.
 *
 * @param {Object} fix Quick fix with `find` and `replace`.
 * @return {string} Description.
 */
export function describeFix(fix) {
	if (!fix.replace) {
		return sprintf(
			/* translators: %s: text to delete */
			__('Delete “%s”', 'ai-feedback'),
			fix.find
		);
	}
	return sprintf(
		/* translators: 1: text to replace, 2: replacement text */
		__('Replace “%1$s” with “%2$s”', 'ai-feedback'),
		fix.find,
		fix.replace
	);
}

/**
 * Quick fix component.
 *
 * @param {Object} props      Component props.
 * @param {Object} props.item Feedback item with `quick_fix` and `block_id`.
 * @return {Element} Quick fix component.
 */
export default function QuickFix({ item }) {
	const fix = item.quick_fix;
	const [isPreviewOpen, setIsPreviewOpen] = useState(false);

	const { attributeKey, content } = useSelect(
		(select) => {
			const block = select(blockEditorStore).getBlock(item.block_id);
			const key = block
				? getRichTextAttributeKey(
						select(blocksStore).getBlockType(block.name)
					)
				: null;
			return {
				attributeKey: key,
				content: key ? block.attributes[key] : undefined,
			};
		},
		[item.block_id]
	);

	const { updateBlockAttributes } = useDispatch(blockEditorStore);
	const { setNoteResolved, setFixApplied } = useDispatch(STORE_NAME);
	const { createSuccessNotice } = useDispatch(noticesStore);

	const preview = attributeKey ? getFixPreview(content, fix) : null;
	const canApply = !!preview;

	const setResolved = async (resolved) => {
		if (!item.id) {
			return;
		}
		try {
			await setNoteResolved(item.id, resolved);
		} catch (error) {
			// The edit itself succeeded; leave the note's state as is.
		}
	};

	const handleApply = () => {
		const updated = applyFixToContent(content, fix);
		if (!updated) {
			return;
		}

		const previous = content;
		updateBlockAttributes(item.block_id, { [attributeKey]: updated });
		setFixApplied(item.id, true);
		setResolved(true);
		setIsPreviewOpen(false);

		createSuccessNotice(__('Fix applied.', 'ai-feedback'), {
			type: 'snackbar',
			id: `ai-feedback-fix-applied-${item.id}`,
			actions: [
				{
					label: __('Undo', 'ai-feedback'),
					onClick: () => {
						updateBlockAttributes(item.block_id, {
							[attributeKey]: previous,
						});
						setFixApplied(item.id, false);
						setResolved(false);
					},
				},
			],
		});
	};

	return (
		<div className="ai-feedback-quick-fix">
			<p className="ai-feedback-quick-fix-summary">{describeFix(fix)}</p>
			{!canApply && (
				<p className="ai-feedback-quick-fix-unavailable description">
					{__(
						'The block text has changed, so this fix can no longer be applied.',
						'ai-feedback'
					)}
				</p>
			)}
			<div className="ai-feedback-item-actions">
				<Button
					variant="primary"
					size="small"
					onClick={handleApply}
					disabled={!canApply}
					accessibleWhenDisabled
				>
					{__('Apply fix', 'ai-feedback')}
				</Button>
				<Button
					variant="secondary"
					size="small"
					onClick={() => setIsPreviewOpen(true)}
					disabled={!canApply}
					accessibleWhenDisabled
				>
					{__('Preview', 'ai-feedback')}
				</Button>
			</div>

			{isPreviewOpen && preview && (
				<Modal
					title={__('Preview fix', 'ai-feedback')}
					onRequestClose={() => setIsPreviewOpen(false)}
					className="ai-feedback-quick-fix-modal"
				>
					<p className="ai-feedback-quick-fix-diff">
						{preview.before}
						<del>{preview.removed}</del>
						{preview.added && <ins>{preview.added}</ins>}
						{preview.after}
					</p>
					<div className="ai-feedback-quick-fix-modal-actions">
						<Button
							variant="tertiary"
							onClick={() => setIsPreviewOpen(false)}
						>
							{__('Cancel', 'ai-feedback')}
						</Button>
						<Button variant="primary" onClick={handleApply}>
							{__('Apply fix', 'ai-feedback')}
						</Button>
					</div>
				</Modal>
			)}
		</div>
	);
}
