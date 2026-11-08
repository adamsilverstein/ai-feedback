/**
 * Select a block and scroll it into view.
 */
import { dispatch } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';

/**
 * Navigate to a specific block in the editor.
 *
 * @param {string} clientId Block client ID to navigate to.
 */
export function navigateToBlock(clientId) {
	const { selectBlock } = dispatch(blockEditorStore);

	if (!selectBlock) {
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
