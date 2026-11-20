/**
 * Checks the Suggestion Mode environment itself, so a setup problem fails
 * here with a clear message instead of in every other spec.
 */
const { test, expect } = require('../../fixtures');

test('the editor has Suggestion Mode, with structural suggestions', async ({
	page,
	admin,
}) => {
	await admin.createNewPost();
	const flags = await page.evaluate(() => ({
		editor: !!window.__experimentalSuggestionMode,
		suggestions: !!window.aiFeedbackData?.suggestions,
		structural: !!window.aiFeedbackData?.structuralSuggestions,
	}));
	expect(
		flags,
		'Gutenberg and the plugin should both report Suggestion Mode as on. Check the gutenberg-experiments option and the pinned build.'
	).toEqual({ editor: true, suggestions: true, structural: true });
});
