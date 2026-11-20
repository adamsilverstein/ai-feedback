/**
 * useRevertedFixes hook.
 *
 * Applying a quick fix, or Accept all, marks its note applied and resolves
 * it. The editor's own Undo can take the text back without either, and its
 * Redo can bring it back again. Each fix applied this session remembers its
 * block's content before and after (see recordApplied()), so:
 *
 * - a block that reads as it did before the fix marks it not applied and
 *   reopens the note, as the snackbar's Undo does;
 * - a block that reads as the fix left it, for a fix reopened that way,
 *   marks it applied and resolves the note again.
 *
 * Any other edit leaves the note as it is. Checks reuse the last result for
 * a block whose content hasn't changed.
 */
import { useEffect, useRef } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { STORE_NAME } from '../store';
import { toHtml } from '../utils/quick-fix';
import { saveSuggestionStatuses } from '../utils/suggestions';
import {
	getAppliedState,
	isRecordedSuggestion,
	trackSaves,
	whenSaved,
} from '../utils/proofreading';

/**
 * Find the fixes the editor's Undo or Redo moved.
 *
 * @param {(store: string|Object) => Object} select  Registry select.
 * @param {Object[]}                         items   Feedback notes.
 * @param {Map}                              [cache] HTML by block and attribute, reused while the value is the same.
 * @return {string[]} Entries like `12:revert` or `12:reapply`.
 */
export function selectFixChanges(select, items = [], cache = new Map()) {
	const store = select(STORE_NAME);
	const editor = select(blockEditorStore);
	const getHtml = ({ clientId, key }) => {
		const value = editor.getBlock(clientId)?.attributes[key];
		const cacheKey = `${clientId}:${key}`;
		const cached = cache.get(cacheKey);
		if (cached && cached.value === value) {
			return cached.html;
		}
		const html = toHtml(value);
		cache.set(cacheKey, { value, html });
		return html;
	};

	const changes = [];
	items.forEach((item) => {
		if (!item?.id || !item.quick_fix || item.is_group) {
			return;
		}
		const state = getAppliedState(item.id, getHtml);
		const isApplied = store.isFixApplied(item.id);
		if (state === 'before' && isApplied) {
			changes.push(`${item.id}:revert`);
		} else if (state === 'after' && !isApplied) {
			changes.push(`${item.id}:reapply`);
		}
	});
	return changes;
}

/**
 * @param {Object[]} items Feedback notes.
 */
export default function useRevertedFixes(items) {
	const cache = useRef(new Map());
	// A string, so the selector returns an equal value until the set changes.
	const changes = useSelect(
		(select) => selectFixChanges(select, items, cache.current).join(','),
		[items]
	);
	const { setFixApplied, setNoteResolved } = useDispatch(STORE_NAME);

	useEffect(() => {
		if (!changes) {
			return;
		}
		changes.split(',').forEach(async (entry) => {
			const [id, change] = entry.split(':');
			const noteId = Number(id);
			const applied = change === 'reapply';
			setFixApplied(noteId, applied);
			// Each write waits for the last one on the same note.
			await whenSaved(noteId);
			const saving = isRecordedSuggestion(noteId)
				? saveSuggestionStatuses(
						[noteId],
						applied ? 'applied-unsaved' : 'pending'
					)
				: setNoteResolved(noteId, applied);
			try {
				await trackSaves([noteId], saving);
			} catch {
				// The note keeps its state; its fix can still be applied.
			}
		});
	}, [changes, setFixApplied, setNoteResolved]);
}
