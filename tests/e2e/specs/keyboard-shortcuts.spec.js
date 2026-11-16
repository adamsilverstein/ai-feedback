/**
 * E2E tests for keyboard shortcuts.
 */
const { test, expect } = require('../fixtures');

test.describe('Keyboard shortcuts', () => {
	test('toggles the AI Feedback sidebar', async ({
		admin,
		page,
		pageUtils,
		aiFeedback,
	}) => {
		await admin.createNewPost();

		// Opening once through the UI also dismisses the welcome modal.
		await aiFeedback.openSidebar();
		const panel = page.locator('.ai-feedback-panel');

		await pageUtils.pressKeys('access+f');
		await expect(panel).toBeHidden();

		await pageUtils.pressKeys('access+f');
		await expect(panel).toBeVisible();
	});

	test('goes to the next block with feedback after a reload', async ({
		admin,
		editor,
		page,
		pageUtils,
		aiFeedback,
	}) => {
		await admin.createNewPost({ title: 'Shortcut Navigation' });
		await editor.insertBlock({
			name: 'core/paragraph',
			attributes: { content: 'First block.' },
		});
		await editor.insertBlock({
			name: 'core/paragraph',
			attributes: { content: 'Second block.' },
		});
		await editor.saveDraft();
		await aiFeedback.openSidebar();
		await aiFeedback.runReview();

		// The note IDs in the block metadata are what survive a reload.
		const expectNoteIds = () =>
			expect
				.poll(async () =>
					(await editor.getBlocks({ full: true })).map(
						(block) => block.attributes.metadata?.noteId > 0
					)
				)
				.toEqual([true, true]);
		await expectNoteIds();
		await editor.saveDraft();
		await page.reload();
		await aiFeedback.openSidebar();
		await expect(page.locator('.ai-feedback-review-summary')).toBeVisible();
		await expectNoteIds();

		const blocks = await editor.getBlocks({ full: true });
		await page.evaluate(() =>
			window.wp.data.dispatch('core/block-editor').clearSelectedBlock()
		);
		const selectedBlock = () =>
			page.evaluate(() =>
				window.wp.data
					.select('core/block-editor')
					.getSelectedBlockClientId()
			);

		await pageUtils.pressKeys('access+j');
		await expect.poll(selectedBlock).toBe(blocks[0].clientId);

		// From the first block, "previous" wraps around to the last one.
		await pageUtils.pressKeys('access+k');
		await expect.poll(selectedBlock).toBe(blocks[1].clientId);
	});

	test('lists the shortcuts in the keyboard shortcuts help', async ({
		admin,
		page,
		pageUtils,
	}) => {
		await admin.createNewPost();

		await pageUtils.pressKeys('access+h');

		const dialog = page.getByRole('dialog', {
			name: 'Keyboard shortcuts',
		});
		await expect(
			dialog.getByText('Show or hide the Copydesk AI sidebar')
		).toBeVisible();
		await expect(
			dialog.getByText('Review the document with AI')
		).toBeVisible();
	});
});
