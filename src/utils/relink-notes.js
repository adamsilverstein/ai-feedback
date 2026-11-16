/**
 * Re-link AI notes to their blocks after an unsaved reload.
 *
 * A review stores each note's ID in its block's `metadata.noteId`, which only
 * reaches the database when the post is saved. Reloading first leaves the
 * notes orphaned, so they are matched back to blocks by the anchor saved
 * with each note.
 */
import { extractBlockData } from './block-utils';

/**
 * Collect the note IDs already stored on blocks.
 *
 * @param {Array}    blocks Editor blocks.
 * @param {Set}      linked Note IDs found so far.
 * @param {Object[]} free   Blocks without a note, in document order.
 */
function collectLinks(blocks, linked, free) {
	for (const block of blocks) {
		const noteId = block.attributes?.metadata?.noteId;
		if (noteId) {
			linked.add(noteId);
		} else {
			free.push(block);
		}
		collectLinks(block.innerBlocks || [], linked, free);
	}
}

/**
 * Match orphaned notes to blocks with the same anchor.
 *
 * Only top-level notes are linked, and only to blocks that have no note yet.
 * When several blocks share an anchor, notes take them in document order.
 *
 * @param {Array}    blocks Editor blocks.
 * @param {Object[]} notes  AI notes for the post, with `parent` and `block_anchor`.
 * @return {Object} Map of block clientId to note ID.
 */
export function getNoteRelinks(blocks, notes) {
	const linked = new Set();
	const free = [];
	collectLinks(blocks, linked, free);

	const anchors = new Map(
		extractBlockData(blocks).map((block) => [block.clientId, block.anchor])
	);

	const relinks = {};
	const orphans = notes
		.filter(
			(note) => !note.parent && note.block_anchor && !linked.has(note.id)
		)
		.sort((a, b) => a.id - b.id);

	for (const note of orphans) {
		const block = free.find(
			({ clientId }) =>
				!relinks[clientId] &&
				anchors.get(clientId) === note.block_anchor
		);
		if (block) {
			relinks[block.clientId] = note.id;
		}
	}

	return relinks;
}
