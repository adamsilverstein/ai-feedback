/**
 * Proofreading Group Component
 *
 * One sidebar row for a review's Grammar & Spelling fixes, with Accept all.
 * Expanded, it lists each fix as its own feedback item, which can still be
 * previewed, applied or dismissed on its own.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useMemo, useState } from '@wordpress/element';
import { Button, Card, CardBody, CardHeader } from '@wordpress/components';
import { Icon, chevronUp, chevronDown } from '@wordpress/icons';
import { useDispatch, useRegistry, useSelect } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { STORE_NAME } from '../store';
import FeedbackItem from './FeedbackItem';
import {
	selectHeldSuggestionStatus,
	selectSuggestionStatus,
} from '../hooks/useSuggestionStatus';
import { saveSuggestionStatuses } from '../utils/suggestions';
import {
	acceptAllFixes,
	isOpenFix,
	trackSaves,
	undoAcceptAll,
} from '../utils/proofreading';

/**
 * Number of fixes previewed while the group is collapsed.
 */
const PREVIEW_COUNT = 3;

/**
 * Describe what Accept all did.
 *
 * @param {number} done    Fixes applied or accepted.
 * @param {number} skipped Fixes that no longer apply.
 * @return {string} Message.
 */
export function describeAcceptAll(done, skipped) {
	const parts = [
		done > 0
			? sprintf(
					/* translators: %d: number of fixes applied */
					_n(
						'%d fix applied.',
						'%d fixes applied.',
						done,
						'fresh-eyes'
					),
					done
				)
			: __('No fixes could be applied.', 'fresh-eyes'),
	];
	if (skipped > 0) {
		parts.push(
			sprintf(
				/* translators: %d: number of fixes skipped */
				_n(
					'%d no longer applies.',
					'%d no longer apply.',
					skipped,
					'fresh-eyes'
				),
				skipped
			)
		);
	}
	return parts.join(' ');
}

/**
 * A fix as "find → replace", or "find → (delete)".
 *
 * @param {Object} fix Quick fix.
 * @return {string} Short description.
 */
function describeShortFix(fix) {
	return sprintf(
		/* translators: 1: text to replace, 2: replacement text */
		__('%1$s → %2$s', 'fresh-eyes'),
		fix.find,
		fix.replace || __('(delete)', 'fresh-eyes')
	);
}

/**
 * The reply thread to show with a fix.
 *
 * A block's second and later notes are replies in its first note's thread,
 * so the thread is shown only with that first note, without the other
 * fixes listed in this group.
 *
 * @param {Object}      threads Reply threads keyed by root note id.
 * @param {Object}      item    Proofreading note.
 * @param {Set<number>} ids     IDs of the notes in the group.
 * @return {Object|undefined} Thread, if the note has one.
 */
export function getOwnThread(threads, item, ids) {
	const thread = item.parent ? undefined : threads[item.id];
	if (!thread) {
		return undefined;
	}
	return {
		...thread,
		replies: (thread.replies || []).filter((reply) => !ids.has(reply.id)),
	};
}

/**
 * Proofreading group.
 *
 * @param {Object}     props                   Component props.
 * @param {Object[]}   props.items             Proofreading notes.
 * @param {Object}     [props.threads]         Reply threads keyed by root note id.
 * @param {() => void} [props.onReplyComplete] Called once a pending AI reply is saved.
 * @return {Element|null} Group component.
 */
export default function ProofreadingGroup({
	items,
	threads = {},
	onReplyComplete,
}) {
	const [expanded, setExpanded] = useState(false);
	const [isBusy, setIsBusy] = useState(false);
	const registry = useRegistry();

	// A string, so the selector returns an equal value until a status changes.
	const stateKey = useSelect(
		(select) =>
			items
				.map((item) => {
					const status = selectSuggestionStatus(select, item);
					// A note holding an AI reply's edit: that edit replaced
					// its own fix.
					const isReplaced =
						!status && !!selectHeldSuggestionStatus(select, item);
					return `${status ?? ''}:${
						select(STORE_NAME).isFixApplied(item.id) ? 1 : 0
					}:${isReplaced ? 1 : 0}`;
				})
				.join(','),
		[items]
	);

	const { openItems, statuses } = useMemo(() => {
		const states = stateKey.split(',');
		const found = {};
		const open = items.filter((item, index) => {
			const [status, applied, replaced] = states[index].split(':');
			found[item.id] = status || null;
			return (
				replaced !== '1' &&
				isOpenFix(item, {
					isApplied: applied === '1',
					suggestionStatus: status || null,
				})
			);
		});
		return { openItems: open, statuses: found };
	}, [items, stateKey]);

	const { setNoteResolved, setFixApplied } = useDispatch(STORE_NAME);
	const { createSuccessNotice, createWarningNotice } =
		useDispatch(noticesStore);

	if (!items || items.length === 0) {
		return null;
	}

	/**
	 * Resolve or reopen notes, warning once if any fail.
	 *
	 * @param {number[]} ids      Note ids.
	 * @param {boolean}  resolved Whether to resolve them.
	 */
	const setResolved = async (ids, resolved) => {
		const results = await Promise.allSettled(
			ids.map((id) => setNoteResolved(id, resolved))
		);
		if (results.some((result) => result.status === 'rejected')) {
			createWarningNotice(
				resolved
					? __(
							'The fixes were applied, but some notes could not be resolved.',
							'fresh-eyes'
						)
					: __(
							'The fixes were undone, but some notes could not be reopened.',
							'fresh-eyes'
						),
				{ type: 'snackbar' }
			);
		}
	};

	/**
	 * Save suggestion statuses, offering a retry for any that fail.
	 *
	 * @param {number[]} noteIds Suggestion (note) ids.
	 * @param {string}   status  Status to save.
	 * @return {Promise<void>}
	 */
	const saveStatuses = async (noteIds, status) => {
		if (noteIds.length === 0) {
			return;
		}
		const failed = await saveSuggestionStatuses(noteIds, status);
		if (failed.length > 0) {
			createWarningNotice(
				__(
					'Some suggested edits could not be updated in their notes.',
					'fresh-eyes'
				),
				{
					type: 'snackbar',
					id: 'ai-feedback-proofreading-status-failed',
					actions: [
						{
							label: __('Retry', 'fresh-eyes'),
							onClick: () => saveStatuses(failed, status),
						},
					],
				}
			);
		}
	};

	const handleAcceptAll = async () => {
		setIsBusy(true);
		try {
			const plan = acceptAllFixes(registry, openItems, statuses);
			const done = [...plan.applied, ...plan.accepted];
			done.forEach((id) => setFixApplied(id, true));

			// Undo waits on these, so its writes never land before them.
			plan.saving = trackSaves(
				[...plan.applied, ...plan.accepted],
				Promise.all([
					setResolved(plan.applied, true),
					saveStatuses(plan.accepted, 'applied-unsaved'),
				])
			);

			createSuccessNotice(
				describeAcceptAll(done.length, plan.skipped.length),
				{
					type: 'snackbar',
					id: 'ai-feedback-proofreading-accept-all',
					actions:
						done.length > 0
							? [
									{
										label: __('Undo', 'fresh-eyes'),
										onClick: () => handleUndo(plan),
									},
								]
							: [],
				}
			);

			await plan.saving;
		} finally {
			setIsBusy(false);
		}
	};

	const handleUndo = async (plan) => {
		if (!undoAcceptAll(registry, plan)) {
			createWarningNotice(
				__(
					'The text has changed since the fixes were applied. Use the editor’s Undo instead.',
					'fresh-eyes'
				),
				{ type: 'snackbar' }
			);
			return;
		}
		[...plan.applied, ...plan.accepted].forEach((id) =>
			setFixApplied(id, false)
		);
		await plan.saving;
		await Promise.all([
			setResolved(plan.applied, false),
			saveStatuses(plan.accepted, 'pending'),
		]);
	};

	const ids = new Set(items.map((item) => item.id));
	const openCount = openItems.length;
	const title =
		openCount > 0
			? sprintf(
					/* translators: %d: number of proofreading fixes */
					_n(
						'%d proofreading fix',
						'%d proofreading fixes',
						openCount,
						'fresh-eyes'
					),
					openCount
				)
			: __('Proofreading fixes: all done', 'fresh-eyes');
	const preview = openItems.slice(0, PREVIEW_COUNT);
	const more = openCount - preview.length;

	return (
		<Card
			className="ai-feedback-group ai-feedback-proofreading"
			size="small"
		>
			<CardHeader>
				<div className="ai-feedback-group-header ai-feedback-proofreading-header">
					<Button
						variant="link"
						onClick={() => setExpanded(!expanded)}
						aria-expanded={expanded}
						className="expand-group-button"
					>
						<span className="group-title">{title}</span>
						<Icon
							icon={expanded ? chevronUp : chevronDown}
							size={20}
						/>
					</Button>
					<Button
						variant="primary"
						size="small"
						onClick={handleAcceptAll}
						disabled={openCount === 0 || isBusy}
						accessibleWhenDisabled
						isBusy={isBusy}
					>
						{__('Accept all', 'fresh-eyes')}
					</Button>
				</div>
			</CardHeader>

			{!expanded && preview.length > 0 && (
				<CardBody>
					<ul className="ai-feedback-proofreading-preview">
						{preview.map((item) => (
							<li
								key={item.id}
								className="ai-feedback-proofreading-preview-item"
							>
								{describeShortFix(item.quick_fix)}
							</li>
						))}
						{more > 0 && (
							<li className="ai-feedback-proofreading-preview-item">
								{sprintf(
									/* translators: %d: number of further fixes */
									__('… %d more', 'fresh-eyes'),
									more
								)}
							</li>
						)}
					</ul>
				</CardBody>
			)}

			{expanded && (
				<CardBody className="ai-feedback-proofreading-items">
					<div
						role="list"
						aria-label={__('Proofreading fixes', 'fresh-eyes')}
					>
						{items.map((item) => (
							<div key={item.id} role="listitem">
								<FeedbackItem
									item={item}
									thread={getOwnThread(threads, item, ids)}
									onReplyComplete={onReplyComplete}
								/>
							</div>
						))}
					</div>
				</CardBody>
			)}
		</Card>
	);
}
