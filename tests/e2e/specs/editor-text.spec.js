/**
 * E2E check that the shared editor text fixture matches the editor.
 *
 * tests/fixtures/editor-text.json lists block HTML with the text the
 * editor's rich text reads from it, and the formats core registers. The
 * server (Editor_Text) and the JS unit tests read with those formats, so
 * this checks both lists against a live editor.
 */
const { test, expect } = require('../fixtures');
const EDITOR_TEXT = require('../../fixtures/editor-text.json');

test.describe('Editor text fixture', () => {
	test.beforeEach(async ({ admin, editor }) => {
		await admin.createNewPost();
		await expect.poll(() => editor.getBlocks()).toEqual([]);
	});

	test('lists the formats the editor reads with', async ({ page }) => {
		const live = await page.evaluate(() =>
			window.wp.data
				.select('core/rich-text')
				.getFormatTypes()
				.map((format) => ({
					name: format.name,
					tagName: format.tagName,
					className: format.className,
					attributes: Object.values(format.attributes || {}),
					contentEditable: format.contentEditable !== false,
					// Rich text reads these as plain text.
					asText:
						!!format.__experimentalCreatePrepareEditableTree &&
						!format.__experimentalCreateOnChangeEditableValue,
				}))
		);

		// Other formats may come and go; the ones listed must match.
		expect(
			EDITOR_TEXT.formats.map(({ name }) =>
				live.find((format) => format.name === name)
			)
		).toEqual(EDITOR_TEXT.formats);
	});

	test('reads each case as the fixture says', async ({ page }) => {
		const read = await page.evaluate(
			(cases) =>
				cases.map(({ name, html }) => ({
					name,
					text: window.wp.richText.create({ html }).text,
				})),
			EDITOR_TEXT.cases
		);

		expect(read).toEqual(
			EDITOR_TEXT.cases.map(({ name, text }) => ({ name, text }))
		);
	});
});
