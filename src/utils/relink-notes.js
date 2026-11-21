/**
 * Re-link AI notes to their blocks after an unsaved reload.
 *
 * A review stores each note's ID in its block's `metadata.noteId`, which only
 * reaches the database when the post is saved. Reloading first leaves the
 * notes orphaned, so they are matched back to blocks by the anchor and block
 * name saved with each note.
 */
import {
	extractBlockData,
	extractTextContent,
	getBlockAnchor,
} from './block-utils';

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
 * Key notes and blocks by block name and anchor.
 *
 * @param {string} name   Block name.
 * @param {string} anchor Block anchor.
 * @return {string} Match key.
 */
const matchKey = (name, anchor) => `${name}\n${anchor}`;

/**
 * Match orphaned notes to blocks with the same block name and anchor.
 *
 * Only top-level notes are linked, and only to blocks that have no note yet.
 *
 * A review creates one top-level note per block, so several notes from one
 * review with the same key belong to different blocks with identical text.
 * Notes from an older review of the same text were replaced by the newer
 * review, so only the latest review's notes are linked for each key. When a
 * key matches more free blocks than notes, the right block is unknown and
 * the notes stay unlinked, since a wrong link is worse than none.
 *
 * @param {Array}    blocks Editor blocks.
 * @param {Object[]} notes  AI notes for the post, with `parent`, `block_name`,
 *                          `block_anchor` and `review_id`.
 * @return {Object} Map of block clientId to note ID.
 */
export function getNoteRelinks(blocks, notes) {
	const linked = new Set();
	const free = [];
	collectLinks(blocks, linked, free);

	// Each block's key from its current text, plus one from the markup it
	// was loaded with, which differs for a block migrated from a deprecated
	// format: notes from before the migration were anchored to that text.
	const keys = new Map();
	const addKey = (clientId, key) => {
		if (!keys.has(clientId)) {
			keys.set(clientId, new Set());
		}
		keys.get(clientId).add(key);
	};
	for (const block of extractBlockData(blocks)) {
		addKey(block.clientId, matchKey(block.name, block.anchor));
	}
	for (const block of free) {
		const loaded = extractTextContent(block.originalContent).trim();
		if (loaded) {
			addKey(
				block.clientId,
				matchKey(block.name, getBlockAnchor(block.name, loaded))
			);
		}
	}

	const anchored = notes
		.filter((note) => !note.parent && note.block_anchor && note.block_name)
		.sort((a, b) => a.id - b.id);

	// The newest note for a key, linked or not, belongs to the latest review.
	const latestReview = new Map();
	for (const note of anchored) {
		latestReview.set(
			matchKey(note.block_name, note.block_anchor),
			note.review_id
		);
	}

	const orphansByKey = new Map();
	for (const note of anchored) {
		const key = matchKey(note.block_name, note.block_anchor);
		if (linked.has(note.id) || note.review_id !== latestReview.get(key)) {
			continue;
		}
		if (!orphansByKey.has(key)) {
			orphansByKey.set(key, []);
		}
		orphansByKey.get(key).push(note);
	}

	const relinks = {};
	for (const [key, orphans] of orphansByKey) {
		const candidates = free.filter(
			({ clientId }) =>
				keys.get(clientId)?.has(key) && !(clientId in relinks)
		);
		if (candidates.length > orphans.length) {
			continue;
		}
		candidates.forEach(({ clientId }, index) => {
			relinks[clientId] = orphans[index].id;
		});
	}

	return relinks;
}
