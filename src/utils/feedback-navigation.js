/**
 * Helpers for stepping through feedback in document order.
 */

/**
 * Collect the block IDs that have feedback, from review notes.
 *
 * @param {Array} notes Review notes (single or grouped).
 * @return {Set<string>} Block client IDs with feedback.
 */
export function getFeedbackBlockIds(notes = []) {
	const ids = new Set();

	notes.forEach((note) => {
		if (note.block_id) {
			ids.add(note.block_id);
		}
		(note.block_ids || []).forEach((id) => ids.add(id));
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
	const candidates = orderedIds.filter((id) => feedbackIds.has(id));

	if (candidates.length === 0) {
		return null;
	}

	const currentIndex = currentId ? orderedIds.indexOf(currentId) : -1;

	if (direction > 0) {
		return (
			candidates.find((id) => orderedIds.indexOf(id) > currentIndex) ??
			candidates[0]
		);
	}

	const before = candidates.filter(
		(id) => currentIndex === -1 || orderedIds.indexOf(id) < currentIndex
	);

	return before.length > 0
		? before[before.length - 1]
		: candidates[candidates.length - 1];
}
