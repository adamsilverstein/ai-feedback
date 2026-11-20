/**
 * Quick Fix Component
 *
 * Preview and apply an edit to a note's block: a review's quick fix (a plain
 * text find/replace) or an edit an AI reply proposed (see reply-edit.js).
 */
import { Button, Modal } from '@wordpress/components';
import { useDispatch, useRegistry, useSelect } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as blocksStore } from '@wordpress/blocks';
import { store as noticesStore } from '@wordpress/notices';
import { STORE_NAME } from '../store';
import useSuggestionStatus from '../hooks/useSuggestionStatus';
import { hasSuggestionMarkers } from '../utils/suggestions';
import { getRichTextAttributeKey } from '../utils/quick-fix';
import {
	applyEditToContent,
	describeEdit,
	describeFix,
	getEditPreview,
} from '../utils/reply-edit';

export { describeFix };

/**
 * Describe where a quick fix stands as a Gutenberg suggestion.
 *
 * @param {string} status Suggestion status from `_wp_suggestion_status`.
 * @return {string} Status message.
 */
export function describeSuggestionStatus(status) {
	switch (status) {
		case 'applied':
			return __('You accepted this suggested edit.', 'fresh-eyes');
		case 'applied-unsaved':
			return __(
				'You accepted this suggested edit. Save the post to keep it.',
				'fresh-eyes'
			);
		case 'rejected':
			return __('You rejected this suggested edit.', 'fresh-eyes');
		case 'rejected-unsaved':
			return __(
				'You rejected this suggested edit. Save the post to confirm.',
				'fresh-eyes'
			);
		case 'outdated':
			return __(
				'This suggested edit no longer applies because the text changed.',
				'fresh-eyes'
			);
		default:
			return __(
				'Suggested in the text. Accept or reject it from the Notes sidebar.',
				'fresh-eyes'
			);
	}
}

/**
 * Render preview parts as inline text with deletions and insertions marked.
 *
 * @param {Object}                              props       Component props.
 * @param {Array<{type: string, text: string}>} props.parts Preview parts.
 * @return {Element} Diff paragraph.
 */
function EditDiff({ parts }) {
	return (
		<p className="ai-feedback-quick-fix-diff">
			{parts.map((part, index) => {
				if (part.type === 'removed') {
					return <del key={index}>{part.text}</del>;
				}
				if (part.type === 'added') {
					return <ins key={index}>{part.text}</ins>;
				}
				return <span key={index}>{part.text}</span>;
			})}
		</p>
	);
}

/**
 * Quick fix component.
 *
 * Applying resolves the note `item.id`, like dismissing it, and marks it
 * fixed. For an AI reply's edit, `noteId` is the reply, which is the note
 * that becomes the Gutenberg suggestion and is marked applied too.
 *
 * @param {Object} props          Component props.
 * @param {Object} props.item     Feedback note with `id` and `block_id`, and `quick_fix` unless `edit` is given.
 * @param {Object} [props.edit]   Edit to apply. Defaults to the note's quick fix.
 * @param {number} [props.noteId] Note that owns the edit. Defaults to `item.id`.
 * @return {Element|null} Quick fix component.
 */
export default function QuickFix({ item, edit, noteId }) {
	const fix = edit ?? item.quick_fix;
	const ownerId = noteId ?? item.id;
	const isReplyEdit = ownerId !== item.id;
	const [isPreviewOpen, setIsPreviewOpen] = useState(false);
	const suggestionStatus = useSuggestionStatus(
		isReplyEdit ? { id: ownerId } : item
	);

	const { attributeKey, content, isApplied } = useSelect(
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
				isApplied: select(STORE_NAME).isFixApplied(ownerId),
			};
		},
		[item.block_id, ownerId]
	);

	const registry = useRegistry();
	const {
		updateBlockAttributes,
		__unstableMarkLastChangeAsPersistent: markLastChangeAsPersistent,
	} = useDispatch(blockEditorStore);
	const { setNoteResolved, setFixApplied } = useDispatch(STORE_NAME);
	const { createSuccessNotice, createWarningNotice } =
		useDispatch(noticesStore);

	const summary = (
		<p className="ai-feedback-quick-fix-summary">{describeEdit(fix)}</p>
	);

	// Gutenberg's suggestion UI owns fixes that became suggestions.
	if (suggestionStatus) {
		return (
			<div className="ai-feedback-quick-fix">
				{summary}
				<p className="ai-feedback-quick-fix-suggested description">
					{describeSuggestionStatus(suggestionStatus)}
				</p>
			</div>
		);
	}

	// Blocks without rich text content (eg. a list or quote, whose text lives
	// in inner blocks) can't take a quick fix here.
	if (!attributeKey) {
		return null;
	}

	// A reply stays in the thread after its edit is applied.
	if (isReplyEdit && isApplied) {
		return (
			<div className="ai-feedback-quick-fix">
				{summary}
				<p className="ai-feedback-quick-fix-applied description">
					{__('You applied this change.', 'fresh-eyes')}
				</p>
			</div>
		);
	}

	// Marker text holds both the added and the deleted runs, so a fix could
	// match inside a deletion and inherit its format.
	const hasSuggestions = hasSuggestionMarkers(content);
	const preview = hasSuggestions ? null : getEditPreview(content, fix);
	const canApply = !!preview;
	const applyLabel = isReplyEdit
		? __('Apply change', 'fresh-eyes')
		: __('Apply fix', 'fresh-eyes');

	const setApplied = (applied) => {
		setFixApplied(item.id, applied);
		if (isReplyEdit) {
			setFixApplied(ownerId, applied);
		}
	};

	const setResolved = async (resolved) => {
		if (!item.id) {
			return;
		}
		try {
			await setNoteResolved(item.id, resolved);
		} catch {
			// The edit itself succeeded, but the note's state didn't change.
			createWarningNotice(
				resolved
					? __(
							'The fix was applied, but the note could not be resolved.',
							'fresh-eyes'
						)
					: __(
							'The fix was undone, but the note could not be reopened.',
							'fresh-eyes'
						),
				{ type: 'snackbar' }
			);
		}
	};

	const handleApply = () => {
		const updated = applyEditToContent(content, fix);
		if (!updated) {
			return;
		}

		const previous = content;
		// One attribute update in its own undo level: an update to the
		// attribute the author just typed in would otherwise merge with the
		// typing, and their next keystroke would merge with this.
		markLastChangeAsPersistent?.();
		updateBlockAttributes(item.block_id, { [attributeKey]: updated });
		markLastChangeAsPersistent?.();
		setApplied(true);
		setResolved(true);
		setIsPreviewOpen(false);

		createSuccessNotice(
			isReplyEdit
				? __('Change applied.', 'fresh-eyes')
				: __('Fix applied.', 'fresh-eyes'),
			{
				type: 'snackbar',
				id: `ai-feedback-fix-applied-${ownerId}`,
				actions: [
					{
						label: __('Undo', 'fresh-eyes'),
						onClick: () => {
							// Don't overwrite edits made after the fix.
							const current = registry
								.select(blockEditorStore)
								.getBlock(item.block_id)?.attributes[
								attributeKey
							];
							if (String(current) !== String(updated)) {
								createWarningNotice(
									__(
										'The block has changed since the fix was applied. Use the editor’s Undo instead.',
										'fresh-eyes'
									),
									{ type: 'snackbar' }
								);
								return;
							}

							updateBlockAttributes(item.block_id, {
								[attributeKey]: previous,
							});
							setApplied(false);
							setResolved(false);
						},
					},
				],
			}
		);
	};

	return (
		<div className="ai-feedback-quick-fix">
			{summary}
			{hasSuggestions && (
				<p className="ai-feedback-quick-fix-unavailable description">
					{__(
						'This block has a suggested edit. Accept or reject it from the Notes sidebar before applying this fix.',
						'fresh-eyes'
					)}
				</p>
			)}
			{!canApply && !hasSuggestions && (
				<p className="ai-feedback-quick-fix-unavailable description">
					{isReplyEdit
						? __(
								'This change is outdated: the block text has changed since the reply.',
								'fresh-eyes'
							)
						: __(
								'The block text has changed, so this fix can no longer be applied.',
								'fresh-eyes'
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
					{applyLabel}
				</Button>
				<Button
					variant="secondary"
					size="small"
					onClick={() => setIsPreviewOpen(true)}
					disabled={!canApply}
					accessibleWhenDisabled
				>
					{__('Preview', 'fresh-eyes')}
				</Button>
			</div>

			{isPreviewOpen && preview && (
				<Modal
					title={
						isReplyEdit
							? __('Preview change', 'fresh-eyes')
							: __('Preview fix', 'fresh-eyes')
					}
					onRequestClose={() => setIsPreviewOpen(false)}
					className="ai-feedback-quick-fix-modal"
				>
					<EditDiff parts={preview} />
					<div className="ai-feedback-quick-fix-modal-actions">
						<Button
							variant="tertiary"
							onClick={() => setIsPreviewOpen(false)}
						>
							{__('Cancel', 'fresh-eyes')}
						</Button>
						<Button variant="primary" onClick={handleApply}>
							{applyLabel}
						</Button>
					</div>
				</Modal>
			)}
		</div>
	);
}
