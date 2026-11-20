/**
 * Block Change Component
 *
 * Preview and apply a review note's formatting fix: a heading level, a list
 * type, alt text, a paragraph turned into a list or heading, or a paragraph
 * split in two (see utils/block-change.js).
 *
 * Formatting fixes stay with Preview and Apply when Gutenberg's Suggestion
 * Mode is on: its attribute suggestions are still changing shape, and
 * transforms and splits have no suggestion form.
 */
import { Button } from '@wordpress/components';
import { useDispatch, useRegistry, useSelect } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as noticesStore } from '@wordpress/notices';
import { STORE_NAME } from '../store';
import { findNoteBlock } from '../utils/block-action';
import {
	applyBlockChange,
	describeBlockChange,
	getBlockChangePreview,
	getBlockChangeState,
} from '../utils/block-change';
import { navigateToBlock } from '../utils/navigate-to-block';

/**
 * The post's unsaved edits other than its blocks, eg. its title.
 *
 * @param {Object} registry Data registry.
 * @return {Object} Edits by post field.
 */
function getOtherPostEdits(registry) {
	const edits = registry.select('core/editor').getPostEdits?.() || {};

	const { blocks, content, selection, ...rest } = edits;
	return rest;
}

/**
 * Whether two sets of post edits hold the same values.
 *
 * @param {Object} a Edits.
 * @param {Object} b Edits.
 * @return {boolean} True when nothing changed between them.
 */
function isSameEdits(a, b) {
	const keys = Object.keys(a);
	return (
		keys.length === Object.keys(b).length &&
		keys.every((key) => a[key] === b[key])
	);
}

/**
 * Formatting fix with Preview and Apply.
 *
 * Applying it resolves the note, like a quick fix.
 *
 * @param {Object}  props                Component props.
 * @param {Object}  props.item           Feedback note with `id`, `block_id` and `block_change`.
 * @param {boolean} [props.isSuperseded] Whether a reply's edit replaced this one.
 * @return {Element} Block change component.
 */
export default function BlockChange({ item, isSuperseded = false }) {
	const change = item.block_change;
	const [isPreviewOpen, setIsPreviewOpen] = useState(false);

	const { clientId, block, state, isApplied } = useSelect(
		(select) => {
			const selectors = select(blockEditorStore);
			const id = findNoteBlock(selectors, item.block_id, item.id);
			return {
				clientId: id,
				block: id ? selectors.getBlock(id) : null,
				...getBlockChangeState(selectors, change, id),
				isApplied: select(STORE_NAME).isFixApplied(item.id),
			};
		},
		[item.block_id, item.id, change]
	);

	const registry = useRegistry();
	const blockEditorActions = useDispatch(blockEditorStore);
	const { setNoteResolved, setFixApplied } = useDispatch(STORE_NAME);
	const { createSuccessNotice, createWarningNotice } =
		useDispatch(noticesStore);

	const canApply = state === 'ready';
	// The preview, and its highlight, only show while the fix can be applied.
	const isPreviewShown = isPreviewOpen && canApply && !isSuperseded;

	// Highlight the block while the preview is open.
	useEffect(() => {
		if (!isPreviewShown || !clientId) {
			return undefined;
		}
		const { toggleBlockHighlight } = blockEditorActions;
		toggleBlockHighlight?.(clientId, true);
		return () => toggleBlockHighlight?.(clientId, false);
	}, [isPreviewShown, clientId, blockEditorActions]);

	const summary = (
		<p className="ai-feedback-quick-fix-summary">
			{describeBlockChange(change)}
		</p>
	);
	const status = (className, message) => (
		<div className="ai-feedback-quick-fix ai-feedback-block-change">
			{summary}
			<p className={`${className} description`}>{message}</p>
		</div>
	);

	if (isSuperseded && state !== 'applied') {
		return status(
			'ai-feedback-quick-fix-superseded',
			__('Replaced by a revised suggestion.', 'fresh-eyes')
		);
	}

	// The blocks decide, not the session's applied flag: the editor's own
	// Undo can take a change back without clearing it.
	if (state === 'applied') {
		return status(
			'ai-feedback-quick-fix-applied',
			isApplied
				? __('You applied this change.', 'fresh-eyes')
				: __('The post already has this change.', 'fresh-eyes')
		);
	}

	const setResolved = async (resolved) => {
		if (!item.id) {
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
		const selectors = registry.select(blockEditorStore);
		if (
			!applyBlockChange(selectors, blockEditorActions, change, clientId)
		) {
			createWarningNotice(
				__(
					'The change could not be applied. The block may be locked or may have changed.',
					'fresh-eyes'
				),
				{ type: 'snackbar' }
			);
			return;
		}
		// Any later edit gives the editor a new blocks tree, or changes the
		// post's other edits, such as its title.
		const appliedBlocks = registry.select(blockEditorStore).getBlocks();
		const appliedEdits = getOtherPostEdits(registry);
		setFixApplied(item.id, true);
		// Undo waits for this, so the note ends up as open as the block.
		const resolving = setResolved(true);

		createSuccessNotice(__('Change applied.', 'fresh-eyes'), {
			type: 'snackbar',
			id: `ai-feedback-block-change-applied-${item.id}`,
			actions: [
				{
					label: __('Undo', 'fresh-eyes'),
					onClick: async () => {
						// Undo only while the change is still the last one.
						if (
							registry.select(blockEditorStore).getBlocks() !==
								appliedBlocks ||
							!isSameEdits(
								getOtherPostEdits(registry),
								appliedEdits
							)
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
						setFixApplied(item.id, false);
						await resolving;
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

	const preview =
		isPreviewShown && block ? getBlockChangePreview(block, change) : null;

	return (
		<div className="ai-feedback-quick-fix ai-feedback-block-change">
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
						'This change is outdated: the block has changed since the review.',
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
					{__('Apply fix', 'fresh-eyes')}
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
			{preview && (
				<div
					className="ai-feedback-block-action-preview"
					role="region"
					aria-label={__('Preview of the change', 'fresh-eyes')}
				>
					<div className="ai-feedback-block-action-outline">
						<p className="ai-feedback-block-action-outline-label">
							{__('Now', 'fresh-eyes')}
						</p>
						<ul>
							{preview.before.map((line, index) => (
								<li key={`before-${index}`}>{line}</li>
							))}
						</ul>
					</div>
					<div className="ai-feedback-block-action-outline">
						<p className="ai-feedback-block-action-outline-label">
							{__('After the change', 'fresh-eyes')}
						</p>
						<ul>
							{preview.after.map((line, index) => (
								<li key={`after-${index}`}>{line}</li>
							))}
						</ul>
					</div>
				</div>
			)}
		</div>
	);
}
