/**
 * useRevertedFixes hook.
 *
 * Applying a quick fix, or Accept all, marks its note applied and resolves
 * it. The editor's own Undo can take the text back without either, so a
 * note whose fix text is back in its block is marked not applied and
 * reopened, as the snackbar's Undo does.
 */
import { useEffect } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as blocksStore } from '@wordpress/blocks';
import { STORE_NAME } from '../store';
import { getRichTextAttributeKey } from '../utils/quick-fix';
import { isFixTextBack, whenSaved } from '../utils/proofreading';

/**
 * Find the applied fixes whose text is back in their block.
 *
 * @param {(store: string|Object) => Object} select Registry select.
 * @param {Object[]}                         items  Feedback notes.
 * @return {number[]} Note ids.
 */
export function selectRevertedFixes(select, items = []) {
	const store = select(STORE_NAME);
	return items
		.filter((item) => {
			if (!item?.quick_fix || !item.block_id || item.is_group) {
				return false;
			}
			if (!store.isFixApplied(item.id)) {
				return false;
			}
			const block = select(blockEditorStore).getBlock(item.block_id);
			const key = block
				? getRichTextAttributeKey(
						select(blocksStore).getBlockType(block.name)
					)
				: null;
			return (
				!!key && isFixTextBack(block.attributes[key], item.quick_fix)
			);
		})
		.map((item) => item.id);
}

/**
 * @param {Object[]} items Feedback notes.
 */
export default function useRevertedFixes(items) {
	// A string, so the selector returns an equal value until the set changes.
	const reverted = useSelect(
		(select) => selectRevertedFixes(select, items).join(','),
		[items]
	);
	const { setFixApplied, setNoteResolved } = useDispatch(STORE_NAME);

	useEffect(() => {
		if (!reverted) {
			return;
		}
		reverted
			.split(',')
			.map(Number)
			.forEach(async (id) => {
				setFixApplied(id, false);
				// Reopen only after the save that resolved it.
				await whenSaved(id);
				try {
					await setNoteResolved(id, false);
				} catch {
					// The note stays resolved; its fix can still be applied.
				}
			});
	}, [reverted, setFixApplied, setNoteResolved]);
}
