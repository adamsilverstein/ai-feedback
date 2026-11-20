/**
 * Check the shape of an edit from an AI reply.
 *
 * Kept apart from reply-edit.js so code that only needs the check does not
 * load rich text.
 */

/**
 * Whether a value is an edit from an AI reply.
 *
 * @param {Object|null|undefined} edit Value to check.
 * @return {boolean} True for a `replace_text` or `rewrite_block` edit.
 */
export function isReplyEdit(edit) {
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
