/**
 * E2E tests for keeping review notes attached across an unsaved reload.
 *
 * Reviews run against the real REST endpoint with the plugin's mock AI
 * response, which returns one feedback item for each of the first three
 * blocks.
 */
const { test, expect } = require('../fixtures');

test.describe('Notes after reloading an unsaved review', () => {
	test('re-links notes to their blocks after a reload without saving', async ({
		page,
		editor,
		admin,
		aiFeedback,
	}) => {
		await admin.createNewPost({ title: 'Reload Without Saving' });
		await editor.insertBlock({
			name: 'core/heading',
			attributes: { content: 'A heading', level: 2 },
		});
		await editor.insertBlock({
			name: 'core/paragraph',
			attributes: { content: 'The first paragraph.' },
		});
		await editor.insertBlock({
			name: 'core/paragraph',
			attributes: { content: 'The second paragraph.' },
		});
		await editor.saveDraft();
		await aiFeedback.openSidebar();

		const response = await aiFeedback.runReview();
		const review = await response.json();
		expect(review.note_count).toBe(3);

		const linkedNoteIds = async () =>
			(await editor.getBlocks()).map(
				(block) => block.attributes.metadata?.noteId
			);

		const expectedNoteIds = await linkedNoteIds();
		expect(expectedNoteIds).toEqual(
			expect.arrayContaining(Object.values(review.block_mapping))
		);

		// Linking the notes leaves unsaved changes, so leaving asks first.
		expect(
			await page.evaluate(() =>
				window.wp.data.select('core/editor').isEditedPostDirty()
			)
		).toBe(true);

		// Leave anyway, as an author choosing "Leave" in the prompt would.
		page.on('dialog', (dialog) => dialog.accept());
		await page.reload();
		await editor.canvas
			.locator('body > *')
			.first()
			.waitFor({ state: 'attached' });

		await expect.poll(linkedNoteIds).toEqual(expectedNoteIds);
	});

	test('leaves saved links alone and the post clean', async ({
		page,
		editor,
		admin,
		aiFeedback,
	}) => {
		await admin.createNewPost({ title: 'Keep Existing Links' });
		await editor.insertBlock({
			name: 'core/paragraph',
			attributes: { content: 'Same words.' },
		});
		await editor.saveDraft();
		await aiFeedback.openSidebar();

		const response = await aiFeedback.runReview();
		const review = await response.json();
		const [noteId] = review.note_ids;

		// Save the link, then reload; the saved link stays as it was. Wait
		// for the save request, since saveDraft() can return before it ends.
		const saved = page.waitForResponse(
			(saveResponse) =>
				saveResponse.request().method() === 'POST' &&
				/wp(\/|%2F)v2(\/|%2F)posts(\/|%2F)\d+(&|\?|$)/.test(
					saveResponse.url()
				) &&
				saveResponse.ok()
		);
		await editor.saveDraft();
		await saved;
		await page.reload();
		await editor.canvas
			.locator('body > *')
			.first()
			.waitFor({ state: 'attached' });

		await expect
			.poll(async () =>
				(await editor.getBlocks()).map(
					(block) => block.attributes.metadata?.noteId
				)
			)
			.toEqual([noteId]);
		expect(
			await page.evaluate(() =>
				window.wp.data.select('core/editor').isEditedPostDirty()
			)
		).toBe(false);
	});
});
