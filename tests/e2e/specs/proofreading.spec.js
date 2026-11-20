/**
 * E2E tests for Grammar & Spelling: proofreading fixes grouped in one
 * sidebar row, with Accept all.
 *
 * Runs on the server with the mock AI provider from
 * tests/mu-plugins/ai-feedback-e2e.php, which finds seven typos in this
 * post, three of them in the first paragraph. Suggestion Mode is off in
 * wp-env, so the fixes are applied directly; the suggestion path is covered
 * by unit tests.
 */
const { test, expect } = require('../fixtures');

const CONTENT = [
	'<!-- wp:paragraph --><p>Teh <a href="https://example.com">quick brwon</a> fox jumpd over.</p><!-- /wp:paragraph -->',
	'<!-- wp:paragraph --><p>You <strong>recieve</strong> it alot.</p><!-- /wp:paragraph -->',
	'<!-- wp:paragraph --><p>A wierd day.</p><!-- /wp:paragraph -->',
	'<!-- wp:paragraph --><p>Thier plan works.</p><!-- /wp:paragraph -->',
].join('');

/**
 * Content of each top-level block, in order.
 *
 * @param {Object} editor Editor fixture.
 * @return {Promise<string[]>} One entry per block.
 */
async function texts(editor) {
	const blocks = await editor.getBlocks();
	return blocks.map((block) => String(block.attributes.content));
}

test.describe('Proofreading', () => {
	test('Accept all applies every fix that still matches as one undo step', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const post = await requestUtils.createPost({
			title: 'Proofreading',
			content: CONTENT,
			status: 'draft',
		});
		await admin.editPost(post.id);
		await expect
			.poll(() => editor.getBlocks().then((blocks) => blocks.length))
			.toBe(4);
		await aiFeedback.openSidebar();

		const response = await aiFeedback.runReview();
		const review = await response.json();
		expect(review.note_ids).toHaveLength(7);
		// Proofreading stays out of the severity counts.
		expect(review.summary.by_category.grammar).toBe(7);
		expect(review.summary.by_severity).toEqual({
			suggestion: 0,
			important: 0,
			critical: 0,
		});
		const original = await texts(editor);

		const group = page.locator('.ai-feedback-proofreading');
		await expect(group).toContainText('7 proofreading fixes');
		await expect(group).toContainText('Teh → The');
		await expect(group).toContainText('… 4 more');
		// The fixes are not listed again as separate feedback.
		await expect(
			page.locator('.ai-feedback-list > [role="listitem"]')
		).toHaveCount(1);

		// Each fix can still be previewed and rejected on its own.
		await group.getByRole('button', { name: /proofreading fixes/ }).click();
		const brwon = group.locator('.ai-feedback-item', {
			hasText: 'brwon → brown',
		});
		await brwon.getByRole('button', { name: 'Preview' }).click();
		const preview = page.getByRole('dialog', { name: 'Preview fix' });
		await expect(preview.locator('del')).toHaveText('brwon');
		// Other fixes on the same block are not repeated as its replies.
		await expect(
			group.locator('.ai-feedback-item', { hasText: 'Teh → The' })
		).not.toContainText('brwon');
		await expect(preview.locator('ins')).toHaveText('brown');
		await preview.getByRole('button', { name: 'Cancel' }).click();

		const thier = group.locator('.ai-feedback-item', {
			hasText: 'Thier → Their',
		});
		await thier.getByRole('button', { name: /Dismiss feedback/ }).click();
		await expect(group).toContainText('6 proofreading fixes');

		// The author rewrites one sentence before accepting the rest.
		await page.evaluate(() => {
			const { select, dispatch } = window.wp.data;
			const block = select('core/block-editor')
				.getBlocks()
				.find((item) =>
					String(item.attributes.content).startsWith('A wierd')
				);
			dispatch('core/block-editor').updateBlockAttributes(
				block.clientId,
				{ content: 'A strange day.' }
			);
		});
		const beforeAccept = await texts(editor);

		await group.getByRole('button', { name: 'Accept all' }).click();
		await expect(
			page.getByTestId('snackbar').filter({
				hasText: '5 fixes applied. 1 no longer applies.',
			})
		).toBeVisible();
		await expect
			.poll(() => texts(editor))
			.toEqual([
				'The <a href="https://example.com">quick brown</a> fox jumped over.',
				'You <strong>receive</strong> it a lot.',
				'A strange day.',
				original[3],
			]);
		await expect(group).toContainText('1 proofreading fix');
		const afterAccept = await texts(editor);

		// One editor Undo takes back every fix, in every block.
		await page.evaluate(() =>
			window.wp.data.dispatch('core/editor').undo()
		);
		await expect.poll(() => texts(editor)).toEqual(beforeAccept);
		// The typos are back, so their fixes are open again.
		await expect(group).toContainText('6 proofreading fixes');
		await expect(
			group.locator('.ai-feedback-item', { hasText: 'Teh → The' })
		).toContainText('Apply fix');

		// Redo puts the fixes back, and they read as applied again.
		await page.evaluate(() =>
			window.wp.data.dispatch('core/editor').redo()
		);
		await expect.poll(() => texts(editor)).toEqual(afterAccept);
		await expect(group).toContainText('1 proofreading fix');
	});

	test('a fix across a line break applies and keeps the break', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const post = await requestUtils.createPost({
			title: 'Proofreading a line break',
			content:
				'<!-- wp:paragraph --><p>Revenue grew 40%<br>last yaer.</p><!-- /wp:paragraph -->',
			status: 'draft',
		});
		await admin.editPost(post.id);
		await expect
			.poll(() => editor.getBlocks().then((blocks) => blocks.length))
			.toBe(1);
		await aiFeedback.openSidebar();

		// The review keeps the break as a newline, so the mock finds the typo.
		const response = await aiFeedback.runReview();
		const review = await response.json();
		expect(review.note_ids).toHaveLength(1);

		const group = page.locator('.ai-feedback-proofreading');
		await expect(group).toContainText('1 proofreading fix');
		await group.getByRole('button', { name: 'Accept all' }).click();

		await expect
			.poll(() => texts(editor))
			.toEqual(['Revenue grew 40%<br>last year.']);
	});

	test('a fix in a paragraph with a newline in its HTML adds no line break', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const post = await requestUtils.createPost({
			title: 'Proofreading a source newline',
			content:
				'<!-- wp:paragraph --><p>Revenue grew 40%\nlast yaer.</p><!-- /wp:paragraph -->',
			status: 'draft',
		});
		await admin.editPost(post.id);
		await expect
			.poll(() => editor.getBlocks().then((blocks) => blocks.length))
			.toBe(1);
		await aiFeedback.openSidebar();

		// The review reads the newline as the editor's rich text does, so
		// the mock's fix spans it.
		const response = await aiFeedback.runReview();
		const review = await response.json();
		expect(review.note_ids).toHaveLength(1);

		const group = page.locator('.ai-feedback-proofreading');
		await expect(group).toContainText('1 proofreading fix');
		await group.getByRole('button', { name: 'Accept all' }).click();

		await expect
			.poll(() => texts(editor))
			.toEqual(['Revenue grew 40%\nlast year.']);
		const paragraph = editor.canvas.locator('p.wp-block-paragraph');
		await expect(paragraph).toHaveText('Revenue grew 40% last year.');
		await expect(paragraph.locator('br')).toHaveCount(0);
	});
});
