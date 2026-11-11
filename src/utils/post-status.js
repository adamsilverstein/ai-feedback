/**
 * Whether the post has been saved at least once.
 *
 * New posts get an auto-draft ID as soon as the editor opens, so an ID alone
 * does not mean the post was saved.
 *
 * @param {number|undefined} postId     Current post ID.
 * @param {string|undefined} postStatus Current post status.
 * @return {boolean} True when the post has been saved.
 */
export function isPostSaved(postId, postStatus) {
	return !!postId && postStatus !== 'auto-draft';
}
