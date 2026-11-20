/**
 * E2E tests for structural edits: feedback that removes, moves or adds a
 * block.
 *
 * Runs on the server with the mock AI provider from
 * tests/mu-plugins/ai-feedback-e2e.php: a review of this post proposes
 * moving "The real opening" above "Background", removing "This repeats
 * section two" and adding a heading above "Next steps", and a reply asking
 * to "move it" moves the block above the first nearby block. Suggestion
 * Mode is off in wp-env, so each edit shows as Preview and Apply in the
 * sidebar; the Suggestion Mode path is covered by unit tests.
 */
const { test, expect } = require('../fixtures');

const PARAGRAPHS = [
	'Background on the project.',
	'The real opening of the piece.',
	'This repeats section two.',
	'Next steps follow.',
];

/**
 * Open the fixture post, review it and save the note links.
 *
 * @param {Object} fixtures              Playwright fixtures.
 * @param {Object} fixtures.editor       Editor fixture.
 * @param {Object} fixtures.admin        Admin fixture.
 * @param {Object} fixtures.requestUtils Request utils fixture.
 * @param {Object} fixtures.aiFeedback   AI Feedback fixture.
 * @return {Promise<Object>} Review response.
 */
async function reviewPost({ editor, admin, requestUtils, aiFeedback }) {
	const content = [
		'<!-- wp:heading --><h2 class="wp-block-heading">Why this matters</h2><!-- /wp:heading -->',
		...PARAGRAPHS.map(
			(text) => `<!-- wp:paragraph --><p>${text}</p><!-- /wp:paragraph -->`
		),
	].join('');
	const post = await requestUtils.createPost({
		title: 'Structural edits',
		content,
		status: 'draft',
	});
	await admin.editPost(post.id);
	await expect
		.poll(() => editor.getBlocks().then((blocks) => blocks.length))
		.toBe(5);
	await aiFeedback.openSidebar();

	const response = await aiFeedback.runReview();
	const review = await response.json();
	expect(review.note_ids).toHaveLength(3);

	// A reply reads the blocks from the saved post, found by its note link.
	await editor.saveDraft();

	return review;
}

/**
 * The text of each top-level block, in order.
 *
 * @param {Object} editor Editor fixture.
 * @return {Promise<string[]>} Block text.
 */
async function blockTexts(editor) {
	const blocks = await editor.getBlocks();
	return blocks.map((block) => String(block.attributes.content ?? ''));
}

/**
 * Undo the last change in the editor.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function undo(page) {
	await page.evaluate(() => window.wp.data.dispatch('core/editor').undo());
}

test.describe('Structural edits', () => {
	// Mock mode follows the e2e cookie, not the queued event. Drain any reply a
	// failed test left scheduled, so a later cookie-less cron run does not
	// send it to a real provider.
	test.afterEach(async ({ page, baseURL }) => {
		await page.request
			.get(new URL('/wp-cron.php', baseURL).toString())
			.catch(() => {});
	});

	test('a review can move, remove and add blocks, each as one undo step', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		await reviewPost({ editor, admin, requestUtils, aiFeedback });
		const original = await blockTexts(editor);

		const moveItem = page.locator('.ai-feedback-item', {
			hasText: 'Lead with the real opening',
		});
		const removeItem = page.locator('.ai-feedback-item', {
			hasText: 'Cut the repetition',
		});
		const insertItem = page.locator('.ai-feedback-item', {
			hasText: 'Add a heading',
		});
		await expect(moveItem).toContainText(
			'Move above “Background on the project.”'
		);
		await expect(removeItem).toContainText('Remove this block');
		await expect(insertItem).toContainText(
			'Add a heading above: “How to try it”'
		);

		// Nothing changes until the author applies an edit.
		expect(await blockTexts(editor)).toEqual(original);

		// Preview shows where the block goes.
		await moveItem.getByRole('button', { name: 'Preview' }).click();
		const preview = moveItem.getByRole('region', {
			name: 'Preview of the change',
		});
		await expect(preview.locator('ol').last().locator('li').nth(1)).toHaveText(
			'Paragraph: The real opening of the piece. (moved)'
		);
		await moveItem.getByRole('button', { name: 'Apply change' }).click();
		await expect
			.poll(() => blockTexts(editor))
			.toEqual([
				'Why this matters',
				'The real opening of the piece.',
				'Background on the project.',
				'This repeats section two.',
				'Next steps follow.',
			]);
		await undo(page);
		await expect.poll(() => blockTexts(editor)).toEqual(original);

		await removeItem.getByRole('button', { name: 'Apply change' }).click();
		await expect
			.poll(() => blockTexts(editor))
			.toEqual(original.filter((text) => !text.startsWith('This repeats')));
		await undo(page);
		await expect.poll(() => blockTexts(editor)).toEqual(original);

		await insertItem.getByRole('button', { name: 'Apply change' }).click();
		await expect
			.poll(() => blockTexts(editor))
			.toEqual([...original.slice(0, 4), 'How to try it', original[4]]);
		const blocks = await editor.getBlocks();
		expect(blocks[4]).toMatchObject({
			name: 'core/heading',
			attributes: { level: 3 },
		});
		await undo(page);
		await expect.poll(() => blockTexts(editor)).toEqual(original);
	});

	test('a move whose target block is gone shows as outdated', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		await reviewPost({ editor, admin, requestUtils, aiFeedback });

		await page.evaluate(() => {
			const { select, dispatch } = window.wp.data;
			const target = select('core/block-editor')
				.getBlocks()
				.find((block) =>
					String(block.attributes.content).startsWith('Background')
				);
			dispatch('core/block-editor').removeBlocks([target.clientId]);
		});

		const moveItem = page.locator('.ai-feedback-item', {
			hasText: 'Lead with the real opening',
		});
		await expect(moveItem).toContainText(
			'This change is outdated: the block it moves next to is no longer in the post.'
		);
		await expect(
			moveItem.getByRole('button', { name: 'Apply change' })
		).toHaveAttribute('aria-disabled', 'true');
	});

	test('a reply can ask to move the block', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const review = await reviewPost({
			editor,
			admin,
			requestUtils,
			aiFeedback,
		});
		const removeNote = review.notes.find(
			(note) => note.title === 'Cut the repetition'
		);

		await page.evaluate(
			async ({ parent }) => {
				const { select, dispatch } = window.wp.data;
				await dispatch('core').saveEntityRecord(
					'root',
					'comment',
					{
						post: select('core/editor').getCurrentPostId(),
						content: 'Actually, ok, move it to the top.',
						status: 'hold',
						type: 'note',
						parent,
					},
					{ throwOnError: true }
				);
			},
			{ parent: removeNote.id }
		);

		const removeItem = page.locator('.ai-feedback-item', {
			hasText: 'Cut the repetition',
		});
		const aiReply = removeItem.locator('.ai-feedback-reply-ai');
		await expect(aiReply).toContainText('Moved it to the top.', {
			timeout: 30000,
		});
		await expect(aiReply).toContainText('Move above “Why this matters”');
		await expect(aiReply).toContainText('Revised suggestion (2)');
		// The review's removal is replaced by the reply's move.
		await expect(removeItem).toContainText(
			'Replaced by a revised suggestion.'
		);

		await aiReply.getByRole('button', { name: 'Apply change' }).click();
		await expect
			.poll(() => blockTexts(editor))
			.toEqual([
				'This repeats section two.',
				'Why this matters',
				'Background on the project.',
				'The real opening of the piece.',
				'Next steps follow.',
			]);
		await expect(aiReply).toContainText('You applied this change.');
	});
});
