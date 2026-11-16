/**
 * Helpers for stepping through feedback in document order.
 */

/**
 * Collect the block IDs that have feedback, from review notes.
 *
 * Pass `resolveBlockId` to map stored client IDs to blocks in the current
 * editor, since client IDs change on every load. Only the first block of a
 * group carries the note, so the other blocks are resolved without a note ID.
 *
 * @param {Array}                                              notes            Review notes (single or grouped).
 * @param {(clientId: string, noteId?: number) => string|null} [resolveBlockId] Maps a stored client ID to one in this editor.
 * @return {Set<string>} Block client IDs with feedback.
 */
export function getFeedbackBlockIds(notes = [], resolveBlockId = (id) => id) {
	const ids = new Set();
	const add = (clientId, noteId) => {
		const id = resolveBlockId(clientId, noteId);
		if (id) {
			ids.add(id);
		}
	};

	notes.forEach((note) => {
		if (note.block_id || note.id) {
			add(note.block_id, note.id);
		}
		(note.block_ids || [])
			.filter((id) => id !== note.block_id)
			.forEach((id) => add(id));
	});

	return ids;
}

/**
 * Find the next or previous block with feedback, relative to a block.
 *
 * Wraps around at either end. With no current block, "next" starts at the top
 * and "previous" starts at the bottom.
 *
 * @param {string[]}    orderedIds  All block client IDs in document order.
 * @param {Set<string>} feedbackIds Block client IDs that have feedback.
 * @param {string|null} currentId   Currently selected block client ID.
 * @param {number}      direction   1 for next, -1 for previous.
 * @return {string|null} Target block client ID, or null if none.
 */
export function getAdjacentFeedbackBlock(
	orderedIds,
	feedbackIds,
	currentId,
	direction
) {
	const positions = new Map(orderedIds.map((id, index) => [id, index]));
	const candidates = orderedIds.filter((id) => feedbackIds.has(id));

	if (candidates.length === 0) {
		return null;
	}

	const currentIndex = positions.get(currentId) ?? -1;

	if (direction > 0) {
		return (
			candidates.find((id) => positions.get(id) > currentIndex) ??
			candidates[0]
		);
	}

	const before = candidates.filter(
		(id) => currentIndex === -1 || positions.get(id) < currentIndex
	);

	return before.length > 0
		? before[before.length - 1]
		: candidates[candidates.length - 1];
}
