/**
 * E2E tests for the category and severity badges on AI notes.
 *
 * Accepting or rejecting a Gutenberg suggestion saves the note again through
 * the core comments endpoint with only `status` and `meta`. Suggestion Mode
 * is a Gutenberg experiment that wp-env does not load, so these tests send
 * the same kind of update directly.
 */
const { test, expect } = require('../fixtures');

/**
 * Save a status change on a note the way Suggestion Mode does.
 *
 * @param {import('@playwright/test').Page} page   Page.
 * @param {number}                          noteId Note ID.
 * @param {string}                          status New comment status.
 * @return {Promise<Object>} Saved comment record.
 */
function saveNoteStatus(page, noteId, status) {
	return page.evaluate(
		({ id, newStatus }) =>
			window.wp.data
				.dispatch('core')
				.saveEntityRecord(
					'root',
					'comment',
					{ id, status: newStatus },
					{ throwOnError: true }
				),
		{ id: noteId, newStatus: status }
	);
}

/**
 * Fetch a note from the core comments endpoint.
 *
 * @param {import('@playwright/test').Page} page   Page.
 * @param {number}                          noteId Note ID.
 * @return {Promise<Object>} Comment record.
 */
function fetchNote(page, noteId) {
	return page.evaluate(
		(id) =>
			window.wp.apiFetch({
				path: `/wp/v2/comments/${id}?context=edit`,
			}),
		noteId
	);
}

test.describe('Note badges', () => {
	test('keep their classes after the note status is saved again', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const post = await requestUtils.createPost({
			title: 'Note badges',
			content:
				'<!-- wp:paragraph --><p>Revenue grew 40% last year.</p><!-- /wp:paragraph -->',
			status: 'draft',
		});
		await admin.editPost(post.id);
		await expect
			.poll(() => editor.getBlocks().then((blocks) => blocks.length))
			.toBe(1);
		await aiFeedback.openSidebar();

		const response = await aiFeedback.runReview();
		const review = await response.json();
		const noteId = review.note_ids[0];
		expect(noteId).toBeGreaterThan(0);

		// The mock review gives the first block a content suggestion.
		const badges = [
			'class="ai-feedback-badge ai-feedback-category-content"',
			'class="ai-feedback-badge ai-feedback-severity-suggestion"',
		];

		const before = await fetchNote(page, noteId);
		for (const badge of badges) {
			expect(before.content.rendered).toContain(badge);
		}

		// Accepting a suggestion resolves its note.
		const saved = await saveNoteStatus(page, noteId, 'approved');
		expect(saved.status).toBe('approved');

		const after = await fetchNote(page, noteId);
		for (const badge of badges) {
			expect(after.content.rendered).toContain(badge);
		}
		expect(
			after.content.rendered.match(/ai-feedback-badge /g)
		).toHaveLength(2);

		// A provisional decision or a reopen puts the note back on hold.
		await saveNoteStatus(page, noteId, 'hold');
		const reopened = await fetchNote(page, noteId);
		for (const badge of badges) {
			expect(reopened.content.rendered).toContain(badge);
		}
	});
});
