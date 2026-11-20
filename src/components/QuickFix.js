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
import { getRichTextAttributeKey, isFixInPlace } from '../utils/quick-fix';
import { trackSaves } from '../utils/proofreading';
import {
	applyEditToContent,
	describeEdit,
	isEditApplied,
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
 * Say why an edit can't be applied.
 *
 * @param {boolean}       isReplyEdit Whether the edit came from an AI reply.
 * @param {string|Object} content     Block content, or rich text data.
 * @param {Object}        fix         Edit or quick fix.
 * @return {string} Message.
 */
function getUnavailableMessage(isReplyEdit, content, fix) {
	if (isReplyEdit) {
		return __(
			'This change is outdated: the block text has changed since the reply.',
			'fresh-eyes'
		);
	}
	if (isFixInPlace(content, fix)) {
		return __('The block already has this change.', 'fresh-eyes');
	}
	return __(
		'The block text has changed, so this fix can no longer be applied.',
		'fresh-eyes'
	);
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
 * Applying a quick fix resolves the note `item.id`, like dismissing it, and
 * marks it fixed. For an AI reply's edit, `noteId` is the reply, which is
 * the note that becomes the Gutenberg suggestion and is marked applied. The
 * thread stays open, so the author can keep refining the change.
 *
 * A superseded edit, one a newer edit in the thread replaced, can no longer
 * be applied, so the newest revision is always the one acted on.
 *
 * @param {Object}  props                Component props.
 * @param {Object}  props.item           Feedback note with `id` and `block_id`, and `quick_fix` unless `edit` is given.
 * @param {Object}  [props.edit]         Edit to apply. Defaults to the note's quick fix.
 * @param {number}  [props.noteId]       Note that owns the edit. Defaults to `item.id`.
 * @param {boolean} [props.isSuperseded] Whether a newer edit in the thread replaced this one.
 * @return {Element|null} Quick fix component.
 */
export default function QuickFix({ item, edit, noteId, isSuperseded = false }) {
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

	const wasAccepted =
		suggestionStatus === 'applied' ||
		suggestionStatus === 'applied-unsaved';
	const wasApplied =
		isApplied ||
		(isReplyEdit && !!attributeKey && isEditApplied(content, fix));

	// A replaced edit keeps its summary so the history reads clearly, but
	// only the newest revision can be applied or accepted. An applied reply
	// edit says so below; an applied review fix (on a restored note) says so
	// here, without offering to apply it again.
	if (isSuperseded && !wasAccepted && !(isReplyEdit && wasApplied)) {
		return (
			<div className="ai-feedback-quick-fix is-superseded">
				{summary}
				<p className="ai-feedback-quick-fix-superseded description">
					{wasApplied
						? __('You applied this fix.', 'fresh-eyes')
						: __('Replaced by a revised suggestion.', 'fresh-eyes')}
				</p>
			</div>
		);
	}

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

	// A reply stays in the thread after its edit is applied. The applied
	// flag only lasts the session; after a reload the text tells.
	if (isReplyEdit && wasApplied) {
		return (
			<div className="ai-feedback-quick-fix">
				{summary}
				<p className="ai-feedback-quick-fix-applied description">
					{isApplied
						? __('You applied this change.', 'fresh-eyes')
						: __(
								'The block already has this change.',
								'fresh-eyes'
							)}
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
		setFixApplied(ownerId, applied);
	};

	const setResolved = async (resolved) => {
		if (!item.id || isReplyEdit) {
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
		// Only null means the edit no longer applies: deleting all of the
		// text leaves an empty string.
		if (updated === null) {
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
		trackSaves([item.id], setResolved(true));
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
					{getUnavailableMessage(isReplyEdit, content, fix)}
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
