/**
 * E2E tests for saving a reviewed post whose meta changes, as adding a
 * footnote does. The review history meta must never block the save: not in
 * the session that ran the review, where the editor still holds the meta it
 * loaded, and not after a reload.
 */
const { test, expect } = require('../fixtures');

const CONTENT = [
	'<!-- wp:paragraph --><p>A first paragraph worth a footnote.</p><!-- /wp:paragraph -->',
	'<!-- wp:paragraph --><p>A second paragraph.</p><!-- /wp:paragraph -->',
].join('');

/**
 * Change the post's meta the way the Footnotes format does, save, and
 * return the error notice, if any.
 *
 * @param {import('@playwright/test').Page} page      Page.
 * @param {string}                          footnotes Footnotes meta to save. One equal to the saved value changes no meta.
 * @return {Promise<string>} Save error message, or an empty string.
 */
async function saveWithMetaEdit(page, footnotes) {
	return page.evaluate(async (value) => {
		const { dispatch, select } = window.wp.data;
		dispatch('core/editor').editPost({ meta: { footnotes: value } });
		await dispatch('core/editor').savePost();
		const error = select('core').getLastEntitySaveError(
			'postType',
			'post',
			select('core/editor').getCurrentPostId()
		);
		return error ? `${error.code}: ${error.message}` : '';
	}, footnotes);
}

test.describe('Saving a reviewed post', () => {
	test('saves meta changes after a review, before and after a reload', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const post = await requestUtils.createPost({
			title: 'Reviewed post with a footnote',
			content: CONTENT,
			status: 'draft',
		});
		await admin.editPost(post.id);
		await expect
			.poll(() => editor.getBlocks().then((blocks) => blocks.length))
			.toBe(2);
		await aiFeedback.openSidebar();
		await aiFeedback.runReview();

		// In the session that ran the review.
		expect(await saveWithMetaEdit(page, '[]')).toBe('');

		// And after a reload, once the post's history holds the review.
		await page.reload();
		await expect
			.poll(() => editor.getBlocks().then((blocks) => blocks.length))
			.toBe(2);
		expect(
			await saveWithMetaEdit(
				page,
				'[{"id":"after-reload","content":"Added after the reload."}]'
			)
		).toBe('');
	});
});
