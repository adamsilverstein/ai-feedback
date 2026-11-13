/**
 * Select a block and scroll it into view.
 */
import { dispatch, select } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';

/**
 * Finds the block a note belongs to.
 *
 * Client IDs change on every editor load, so the ID stored with a note only
 * matches during the session that ran the review. After that the block is
 * found by the note ID in its metadata, or by a suggestion marker in its text.
 *
 * @param {string}           clientId Block client ID stored with the note.
 * @param {number|undefined} noteId   Note ID.
 * @return {string|null} Client ID of the block in this editor, or null.
 */
export function resolveBlockClientId(clientId, noteId) {
	const { getBlock, getClientIdsWithDescendants } =
		select(blockEditorStore) || {};

	if (!getBlock) {
		return clientId || null;
	}
	if (clientId && getBlock(clientId)) {
		return clientId;
	}
	if (!noteId) {
		return null;
	}

	const marker = `data-suggestion-id="${Number(noteId)}"`;
	return (
		getClientIdsWithDescendants().find((id) => {
			const attributes = getBlock(id)?.attributes || {};
			return (
				Number(attributes.metadata?.noteId) === Number(noteId) ||
				Object.values(attributes).some((value) =>
					// RichTextData and plain strings both stringify to HTML.
					typeof value === 'string' || value?.toHTMLString
						? String(value).includes(marker)
						: false
				)
			);
		}) || null
	);
}

/**
 * Navigate to a specific block in the editor.
 *
 * @param {string} blockId  Block client ID to navigate to.
 * @param {number} [noteId] Note ID, used to find the block after a reload.
 */
export function navigateToBlock(blockId, noteId) {
	const { selectBlock } = dispatch(blockEditorStore);
	const clientId = resolveBlockClientId(blockId, noteId);

	if (!selectBlock || !clientId) {
		return;
	}

	selectBlock(clientId);

	// The block may live in the editor iframe or the main document.
	const iframe = document.querySelector('iframe[name="editor-canvas"]');
	const documents = [iframe?.contentDocument, document].filter(Boolean);

	for (const doc of documents) {
		const blockElement = doc.querySelector(
			`[data-block="${window.CSS?.escape?.(clientId) ?? clientId}"]`
		);
		if (blockElement) {
			blockElement.scrollIntoView({
				behavior: 'smooth',
				block: 'center',
			});
			return;
		}
	}
}
