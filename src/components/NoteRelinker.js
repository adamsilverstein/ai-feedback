/**
 * Note relinker.
 *
 * Re-links AI notes to their blocks when the editor loads, in case the post
 * was left without saving after a review.
 */
import { useEffect } from '@wordpress/element';
import { useRegistry, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { _n, sprintf } from '@wordpress/i18n';
import { getNoteRelinks } from '../utils/relink-notes';
import { debugLog } from '../utils/debug';

/**
 * Note relinker component.
 *
 * @return {null} Renders nothing.
 */
export default function NoteRelinker() {
	const registry = useRegistry();
	const { postId, isReady } = useSelect((select) => {
		const editor = select(editorStore);
		return {
			postId: editor.getCurrentPostId(),
			// Unstable, but core uses it too. Waiting avoids matching against
			// the empty block list the editor starts with.
			isReady:
				editor.__unstableIsEditorReady() && !editor.isEditedPostNew(),
		};
	}, []);

	useEffect(() => {
		if (!postId || !isReady) {
			return;
		}

		let isCancelled = false;

		apiFetch({
			path: addQueryArgs(`/ai-feedback/v1/notes/post/${postId}`, {
				ai_only: true,
			}),
		})
			.then(({ notes = [] }) => {
				if (isCancelled) {
					return;
				}

				const blockEditor = registry.select(blockEditorStore);
				const relinks = getNoteRelinks(blockEditor.getBlocks(), notes);
				const clientIds = Object.keys(relinks);
				if (clientIds.length === 0) {
					return;
				}

				const attributes = {};
				for (const clientId of clientIds) {
					attributes[clientId] = {
						metadata: {
							...blockEditor.getBlockAttributes(clientId)
								?.metadata,
							noteId: relinks[clientId],
						},
					};
				}
				const blockEditorActions = registry.dispatch(blockEditorStore);
				// Keep the re-link out of undo history, so Ctrl+Z can't drop it.
				blockEditorActions.__unstableMarkNextChangeAsNotPersistent();
				blockEditorActions.updateBlockAttributes(
					clientIds,
					attributes,
					{
						uniqueByBlock: true,
					}
				);

				registry.dispatch(noticesStore).createInfoNotice(
					sprintf(
						/* translators: %d: number of notes. */
						_n(
							'Reattached %d AI note to its block. Save the post to keep it attached.',
							'Reattached %d AI notes to their blocks. Save the post to keep them attached.',
							clientIds.length,
							'copydesk-ai'
						),
						clientIds.length
					),
					{ type: 'snackbar', id: 'ai-feedback-notes-relinked' }
				);
			})
			.catch((error) => {
				// Notes stay unlinked; the Notes panel still lists them.
				debugLog('Could not re-link notes:', error);
			});

		return () => {
			isCancelled = true;
		};
	}, [postId, isReady, registry]);

	return null;
}
