/**
 * Check the shape of an edit from an AI reply.
 *
 * Kept apart from reply-edit.js so code that only needs the check does not
 * load rich text.
 */

/**
 * Whether a value is a well-formed structural edit (see block-action.js).
 *
 * @param {Object|null|undefined} action Value to check.
 * @return {boolean} True for a `remove_block`, `move_block` or `insert_block` edit.
 */
export function isBlockAction(action) {
	if (action?.type === 'remove_block') {
		return true;
	}
	if (!['before', 'after'].includes(action?.position)) {
		return false;
	}
	if (action.type === 'move_block') {
		return (
			typeof action.target_block_id === 'string' &&
			typeof action.target_name === 'string' &&
			typeof action.target_text === 'string'
		);
	}
	if (action.type === 'insert_block') {
		const block = action.block;
		return (
			typeof block?.name === 'string' &&
			(typeof block.content === 'string' ||
				(Array.isArray(block.items) &&
					block.items.length > 0 &&
					block.items.every((item) => typeof item === 'string')))
		);
	}
	return false;
}

/**
 * Whether a value is an edit from an AI reply.
 *
 * @param {Object|null|undefined} edit Value to check.
 * @return {boolean} True for a `replace_text` or `rewrite_block` edit, or a structural one.
 */
export function isReplyEdit(edit) {
	if (isBlockAction(edit)) {
		return true;
	}
	if (edit?.type === 'replace_text') {
		return (
			typeof edit.find === 'string' && typeof edit.replace === 'string'
		);
	}
	if (edit?.type === 'rewrite_block') {
		return (
			typeof edit.content === 'string' &&
			typeof edit.original === 'string'
		);
	}
	return false;
}
