/**
 * Block Action Component
 *
 * Preview and apply a structural edit: removing a note's block, moving it,
 * or inserting a block next to it (see utils/block-action.js). It comes from
 * a review note's `block_action` or an AI reply's `edit`.
 */
import { Button } from '@wordpress/components';
import { useDispatch, useRegistry, useSelect } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as blocksStore } from '@wordpress/blocks';
import { store as noticesStore } from '@wordpress/notices';
import { STORE_NAME } from '../store';
import useSuggestionStatus from '../hooks/useSuggestionStatus';
import {
	applyBlockAction,
	describeBlockAction,
	findNoteBlock,
	getBlockActionOutline,
	getBlockActionState,
} from '../utils/block-action';
import { navigateToBlock } from '../utils/navigate-to-block';

/**
 * Describe where a structural suggestion stands in Gutenberg's Suggestion Mode.
 *
 * @param {string} status Suggestion status from `_wp_suggestion_status`.
 * @return {string} Status message.
 */
export function describeStructuralSuggestionStatus(status) {
	switch (status) {
		case 'applied':
		case 'applied-unsaved':
			return __('You accepted this suggested change.', 'fresh-eyes');
		case 'rejected':
		case 'rejected-unsaved':
			return __('You rejected this suggested change.', 'fresh-eyes');
		case 'outdated':
			return __(
				'This suggested change no longer applies because the post changed.',
				'fresh-eyes'
			);
		default:
			return __(
				'Suggested in the post. Accept or reject it from the Notes sidebar.',
				'fresh-eyes'
			);
	}
}

/**
 * Before or after outline of the blocks a change touches.
 *
 * @param {Object}   props       Component props.
 * @param {string}   props.label Heading for the outline.
 * @param {Object[]} props.lines Lines from getBlockActionOutline().
 * @return {Element} Outline.
 */
function Outline({ label, lines }) {
	const markLabels = {
		removed: __('removed', 'fresh-eyes'),
		moved: __('moved', 'fresh-eyes'),
		added: __('added', 'fresh-eyes'),
	};
	return (
		<div className="ai-feedback-block-action-outline">
			<p className="ai-feedback-block-action-outline-label">{label}</p>
			<ol>
				{lines.map((line) => (
					<li
						key={line.key}
						className={
							'ai-feedback-block-action-line' +
							(line.mark ? ` is-${line.mark}` : '')
						}
					>
						{line.mark === 'removed' ? (
							<del>{line.label}</del>
						) : (
							line.label
						)}
						{line.mark && (
							<span className="ai-feedback-block-action-mark">
								{` (${markLabels[line.mark]})`}
							</span>
						)}
					</li>
				))}
			</ol>
		</div>
	);
}

/**
 * Structural edit with Preview and Apply.
 *
 * Applying a review note's edit resolves the note, like a quick fix. For an
 * AI reply's edit, `noteId` is the reply and the thread stays open. Only the
 * newest edit in a thread can be applied.
 *
 * @param {Object}  props                Component props.
 * @param {Object}  props.item           Feedback note with `id` and `block_id`.
 * @param {Object}  props.action         Structural edit.
 * @param {number}  [props.noteId]       Note that owns the edit. Defaults to `item.id`.
 * @param {boolean} [props.isSuperseded] Whether a newer edit in the thread replaced this one.
 * @return {Element} Block action component.
 */
export default function BlockAction({
	item,
	action,
	noteId,
	isSuperseded = false,
}) {
	const ownerId = noteId ?? item.id;
	const isReplyEdit = ownerId !== item.id;
	const [isPreviewOpen, setIsPreviewOpen] = useState(false);
	const suggestionStatus = useSuggestionStatus(
		isReplyEdit ? { id: ownerId } : item
	);

	const { clientId, state, targetId, isApplied } = useSelect(
		(select) => {
			const selectors = select(blockEditorStore);
			const id = findNoteBlock(selectors, item.block_id, item.id);
			return {
				clientId: id,
				...getBlockActionState(selectors, action, id),
				isApplied: select(STORE_NAME).isFixApplied(ownerId),
			};
		},
		[item.block_id, item.id, action, ownerId]
	);

	const registry = useRegistry();
	const blockEditorActions = useDispatch(blockEditorStore);
	const { setNoteResolved, setFixApplied } = useDispatch(STORE_NAME);
	const { createSuccessNotice, createWarningNotice } =
		useDispatch(noticesStore);

	const canApply = state === 'ready' && !suggestionStatus;

	// Highlight the block and its destination while the preview is open.
	useEffect(() => {
		if (!isPreviewOpen || !canApply) {
			return undefined;
		}
		const highlighted = [clientId, targetId].filter(Boolean);
		const { toggleBlockHighlight } = blockEditorActions;
		highlighted.forEach((id) => toggleBlockHighlight?.(id, true));
		return () =>
			highlighted.forEach((id) => toggleBlockHighlight?.(id, false));
	}, [isPreviewOpen, canApply, clientId, targetId, blockEditorActions]);

	const summary = (
		<p className="ai-feedback-quick-fix-summary">
			{describeBlockAction(action)}
		</p>
	);
	const status = (className, message) => (
		<div className="ai-feedback-quick-fix ai-feedback-block-action">
			{summary}
			<p className={`${className} description`}>{message}</p>
		</div>
	);

	const wasAccepted =
		suggestionStatus === 'applied' ||
		suggestionStatus === 'applied-unsaved';
	// The blocks decide, not the session's applied flag: the editor's own
	// Undo can take a change back without clearing it.
	const wasApplied = state === 'applied';

	if (isSuperseded && !wasAccepted && !wasApplied) {
		return status(
			'ai-feedback-quick-fix-superseded',
			__('Replaced by a revised suggestion.', 'fresh-eyes')
		);
	}

	// Gutenberg's suggestion UI owns edits that became suggestions.
	if (suggestionStatus) {
		return status(
			'ai-feedback-quick-fix-suggested',
			describeStructuralSuggestionStatus(suggestionStatus)
		);
	}

	// A removed block can't be told apart from one deleted by hand.
	if (state === 'missing' && action.type === 'remove_block') {
		return status(
			'ai-feedback-quick-fix-applied',
			isApplied
				? __('You applied this change.', 'fresh-eyes')
				: __('This block is no longer in the post.', 'fresh-eyes')
		);
	}

	if (wasApplied) {
		return status(
			'ai-feedback-quick-fix-applied',
			isApplied
				? __('You applied this change.', 'fresh-eyes')
				: __('The post already has this change.', 'fresh-eyes')
		);
	}

	const selectors = registry.select(blockEditorStore);
	const getTitle = (name) =>
		registry.select(blocksStore).getBlockType(name)?.title;
	const outline =
		isPreviewOpen && canApply
			? getBlockActionOutline(
					selectors,
					getTitle,
					action,
					clientId,
					targetId
				)
			: null;

	const setResolved = async (resolved) => {
		if (!item.id || isReplyEdit) {
			return;
		}
		try {
			await setNoteResolved(item.id, resolved);
		} catch {
			createWarningNotice(
				resolved
					? __(
							'The change was applied, but the note could not be resolved.',
							'fresh-eyes'
						)
					: __(
							'The change was undone, but the note could not be reopened.',
							'fresh-eyes'
						),
				{ type: 'snackbar' }
			);
		}
	};

	const handleApply = () => {
		setIsPreviewOpen(false);
		if (
			!applyBlockAction(selectors, blockEditorActions, action, clientId)
		) {
			return;
		}
		// Any later edit gives the editor a new blocks tree.
		const appliedBlocks = registry.select(blockEditorStore).getBlocks();
		setFixApplied(ownerId, true);
		setResolved(true);

		createSuccessNotice(__('Change applied.', 'fresh-eyes'), {
			type: 'snackbar',
			id: `ai-feedback-block-action-applied-${ownerId}`,
			actions: [
				{
					label: __('Undo', 'fresh-eyes'),
					onClick: () => {
						// Undo only while the change is still the last one.
						if (
							registry.select(blockEditorStore).getBlocks() !==
							appliedBlocks
						) {
							createWarningNotice(
								__(
									'The post has changed since the change was applied. Use the editor’s Undo instead.',
									'fresh-eyes'
								),
								{ type: 'snackbar' }
							);
							return;
						}
						registry.dispatch('core/editor').undo();
						setFixApplied(ownerId, false);
						setResolved(false);
					},
				},
			],
		});
	};

	const handlePreview = () => {
		if (!isPreviewOpen) {
			navigateToBlock(clientId);
		}
		setIsPreviewOpen(!isPreviewOpen);
	};

	return (
		<div className="ai-feedback-quick-fix ai-feedback-block-action">
			{summary}
			{state === 'missing' && (
				<p className="ai-feedback-quick-fix-unavailable description">
					{__(
						'This change is outdated: its block is no longer in the post.',
						'fresh-eyes'
					)}
				</p>
			)}
			{state === 'outdated' && (
				<p className="ai-feedback-quick-fix-unavailable description">
					{__(
						'This change is outdated: the block it moves next to is no longer in the post.',
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
					{__('Apply change', 'fresh-eyes')}
				</Button>
				<Button
					variant="secondary"
					size="small"
					onClick={handlePreview}
					disabled={!canApply}
					accessibleWhenDisabled
					aria-expanded={isPreviewOpen && canApply}
				>
					{isPreviewOpen && canApply
						? __('Hide preview', 'fresh-eyes')
						: __('Preview', 'fresh-eyes')}
				</Button>
			</div>
			{outline && (
				<div
					className="ai-feedback-block-action-preview"
					role="region"
					aria-label={__('Preview of the change', 'fresh-eyes')}
				>
					<Outline
						label={__('Now', 'fresh-eyes')}
						lines={outline.before}
					/>
					<Outline
						label={__('After the change', 'fresh-eyes')}
						lines={outline.after}
					/>
				</div>
			)}
		</div>
	);
}
