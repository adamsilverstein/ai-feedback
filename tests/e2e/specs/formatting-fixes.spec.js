/**
 * E2E tests for formatting fixes: a review note that fixes a heading level,
 * turns a paragraph into a list or splits a long paragraph in one click.
 *
 * Runs on the server with the mock AI provider from
 * tests/mu-plugins/ai-feedback-e2e.php, which proposes one fix of each kind
 * for this post and alt text for its image. Mock reviews never show the AI
 * the image, so the alt text is dropped and that note stays plain, the
 * path text-only models take. Suggestion Mode is off in wp-env, and
 * formatting fixes always use Preview and Apply.
 */
const { test, expect } = require('../fixtures');

const CONTENT = [
	'<!-- wp:heading --><h2 class="wp-block-heading">Getting started</h2><!-- /wp:heading -->',
	'<!-- wp:heading {"level":4} --><h4 class="wp-block-heading">Setup</h4><!-- /wp:heading -->',
	'<!-- wp:paragraph --><p>You need <a href="https://nodejs.org">Node</a>, npm, Docker and Git.</p><!-- /wp:paragraph -->',
	'<!-- wp:paragraph --><p>The first idea ends here. The second idea starts here and goes on.</p><!-- /wp:paragraph -->',
	'<!-- wp:image --><figure class="wp-block-image"><img src="http://localhost/chart.png" alt=""/></figure><!-- /wp:image -->',
].join('');

/**
 * Undo the last change in the editor.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function undo(page) {
	await page.evaluate(() => window.wp.data.dispatch('core/editor').undo());
}

/**
 * Names and text of the top-level blocks, in order.
 *
 * @param {Object} editor Editor fixture.
 * @return {Promise<string[]>} One entry per block.
 */
async function outline(editor) {
	const blocks = await editor.getBlocks();
	return blocks.map((block) => {
		const inner = block.innerBlocks
			.map((item) => String(item.attributes.content))
			.join(' | ');
		const level = block.attributes.level
			? ` h${block.attributes.level}`
			: '';
		return `${block.name}${level}: ${inner || String(block.attributes.content ?? '')}`;
	});
}

test.describe('Formatting fixes', () => {
	test('a review fixes a heading level, makes a list and splits a paragraph, each as one undo step', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const post = await requestUtils.createPost({
			title: 'Formatting fixes',
			content: CONTENT,
			status: 'draft',
		});
		await admin.editPost(post.id);
		await expect
			.poll(() => editor.getBlocks().then((blocks) => blocks.length))
			.toBe(5);
		await aiFeedback.openSidebar();

		const response = await aiFeedback.runReview();
		const review = await response.json();
		expect(review.note_ids).toHaveLength(4);
		const original = await outline(editor);

		const headingItem = page.locator('.ai-feedback-item', {
			hasText: 'Fix heading level',
		});
		const listItem = page.locator('.ai-feedback-item', {
			hasText: 'Use a list',
		});
		const splitItem = page.locator('.ai-feedback-item', {
			hasText: 'Split this paragraph',
		});
		const altItem = page.locator('.ai-feedback-item', {
			hasText: 'Add alt text',
		});

		// A text-only review flags the image but proposes no alt text.
		await expect(altItem).toBeVisible();
		await expect(
			altItem.getByRole('button', { name: 'Apply fix' })
		).toHaveCount(0);

		// Heading level.
		await expect(headingItem).toContainText('Change to a level 3 heading');
		await headingItem.getByRole('button', { name: 'Preview' }).click();
		const preview = headingItem.getByRole('region', {
			name: 'Preview of the change',
		});
		await expect(preview).toContainText('H4 Setup');
		await expect(preview).toContainText('H3 Setup');
		await headingItem.getByRole('button', { name: 'Apply fix' }).click();
		await expect
			.poll(() => outline(editor))
			.toEqual([
				original[0],
				'core/heading h3: Setup',
				...original.slice(2),
			]);
		// Applying the fix resolves its note.
		await expect(headingItem).toContainText('Fix applied');
		await undo(page);
		await expect.poll(() => outline(editor)).toEqual(original);

		// Paragraph to list, keeping the link.
		await expect(listItem).toContainText('Turn into a list');
		await listItem.getByRole('button', { name: 'Apply fix' }).click();
		await expect
			.poll(() => outline(editor))
			.toEqual([
				...original.slice(0, 2),
				'core/paragraph: You need',
				'core/list: <a href="https://nodejs.org">Node</a> | npm | Docker | Git',
				...original.slice(3),
			]);
		await undo(page);
		await expect.poll(() => outline(editor)).toEqual(original);

		// Split a long paragraph.
		await expect(splitItem).toContainText(
			'Split the paragraph after “The first idea ends here.”'
		);
		await splitItem.getByRole('button', { name: 'Apply fix' }).click();
		await expect
			.poll(() => outline(editor))
			.toEqual([
				...original.slice(0, 3),
				'core/paragraph: The first idea ends here.',
				'core/paragraph: The second idea starts here and goes on.',
				original[4],
			]);
		await undo(page);
		await expect.poll(() => outline(editor)).toEqual(original);
	});

	test('a split keeps newlines in the HTML as newlines, after a reload too', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const post = await requestUtils.createPost({
			title: 'Formatting fixes with newlines',
			content: CONTENT.replace(
				'The first idea ends here. The second idea starts here and goes on.',
				'The first idea ends here.\nThe second idea\nstarts here and goes on.'
			),
			status: 'draft',
		});
		await admin.editPost(post.id);
		await expect
			.poll(() => editor.getBlocks().then((blocks) => blocks.length))
			.toBe(5);
		await aiFeedback.openSidebar();
		await aiFeedback.runReview();

		const splitItem = page.locator('.ai-feedback-item', {
			hasText: 'Split this paragraph',
		});
		await splitItem.getByRole('button', { name: 'Apply fix' }).click();
		await expect
			.poll(() => editor.getBlocks().then((b) => b.length))
			.toBe(6);

		const second = editor.canvas.locator('p.wp-block-paragraph', {
			hasText: 'The second idea',
		});
		for (const pass of ['applied', 'reloaded']) {
			if (pass === 'reloaded') {
				await editor.saveDraft();
				await page.reload();
			}
			await expect
				.poll(() => outline(editor).then((all) => all.slice(3, 5)))
				.toEqual([
					'core/paragraph: The first idea ends here.',
					'core/paragraph: The second idea\nstarts here and goes on.',
				]);
			await expect(second).toHaveText(
				'The second idea starts here and goes on.'
			);
			await expect(second.locator('br')).toHaveCount(0);
		}
	});

	test('a fix whose block changed shows as outdated', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const post = await requestUtils.createPost({
			title: 'Formatting fixes',
			content: CONTENT,
			status: 'draft',
		});
		await admin.editPost(post.id);
		await expect
			.poll(() => editor.getBlocks().then((blocks) => blocks.length))
			.toBe(5);
		await aiFeedback.openSidebar();
		await aiFeedback.runReview();

		await page.evaluate(() => {
			const { select, dispatch } = window.wp.data;
			const heading = select('core/block-editor')
				.getBlocks()
				.find((block) => block.attributes.level === 4);
			dispatch('core/block-editor').updateBlockAttributes(
				heading.clientId,
				{ level: 5 }
			);
		});

		const headingItem = page.locator('.ai-feedback-item', {
			hasText: 'Fix heading level',
		});
		await expect(headingItem).toContainText(
			'This change is outdated: the block has changed since the review.'
		);
		await expect(
			headingItem.getByRole('button', { name: 'Apply fix' })
		).toHaveAttribute('aria-disabled', 'true');
	});

	test('the summary says which images the AI was not shown, and why', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
	}) => {
		const post = await requestUtils.createPost({
			title: 'Skipped images',
			content: CONTENT,
			status: 'draft',
		});
		await admin.editPost(post.id);
		await expect
			.poll(() => editor.getBlocks().then((blocks) => blocks.length))
			.toBe(5);
		await aiFeedback.openSidebar();

		// Mock reviews never collect images, so the server's list of images
		// left out (covered by ReviewImagesTest) is added to the real
		// response here.
		const imageId = await page.evaluate(
			() =>
				window.wp.data
					.select('core/block-editor')
					.getBlocks()
					.find((block) => block.name === 'core/image').clientId
		);
		await aiFeedback.routeREST('/review', async (route) => {
			const response = await route.fetch();
			const json = await response.json();
			await route.fulfill({
				response,
				json: {
					...json,
					images_skipped: [{ block_id: imageId, reason: 'type' }],
				},
			});
		});
		await aiFeedback.runReview();

		await expect(page.locator('.ai-feedback-images-skipped')).toHaveText(
			'1 image was not shown to the AI: the AI provider does not take its file type, so it has no alt text proposal.'
		);
		await aiFeedback.unrouteREST('/review');
	});
});
