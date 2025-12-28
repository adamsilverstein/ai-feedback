/**
 * E2E tests for notes creation from reviews.
 */
const { test, expect } = require('../fixtures');

test.describe('Notes Creation', () => {
	test.beforeEach(async ({ admin }) => {
		await admin.createNewPost();
	});

	test('review creates notes that appear in summary', async ({
		page,
		editor,
		aiFeedback,
	}) => {
		// Setup
		await page
			.getByRole('textbox', { name: 'Add title' })
			.fill('Post with Notes');
		await editor.insertBlock({ name: 'core/paragraph' });
		await page.keyboard.type('Content that will receive feedback.');
		await page.getByRole('button', { name: 'Save draft' }).click();
		await page.waitForSelector('.editor-post-saved-state.is-saved');

		await aiFeedback.openSidebar();

		// Mock API with multiple notes
		await aiFeedback.mockReviewAPI({
			review_id: 'test-review-id',
			post_id: 1,
			model: 'gpt-4o',
			notes: [
				{ id: 1, content: 'First feedback item', block_id: 'block-1' },
				{ id: 2, content: 'Second feedback item', block_id: 'block-1' },
			],
			note_ids: [1, 2],
			block_mapping: { 'block-1': 1 },
			summary: {
				by_severity: { suggestion: 1, important: 1 },
				by_category: { content: 2 },
			},
			summary_text: 'Found 2 items.',
			note_count: 2,
			timestamp: new Date().toISOString(),
		});

		await aiFeedback.startReviewAndWait();

		// Verify note count
		await expect(page.getByText('2 feedback items')).toBeVisible();
	});

	test('displays severity breakdown in summary', async ({
		page,
		editor,
		aiFeedback,
	}) => {
		// Setup
		await page
			.getByRole('textbox', { name: 'Add title' })
			.fill('Severity Test');
		await editor.insertBlock({ name: 'core/paragraph' });
		await page.keyboard.type('Test content.');
		await page.getByRole('button', { name: 'Save draft' }).click();
		await page.waitForSelector('.editor-post-saved-state.is-saved');

		await aiFeedback.openSidebar();

		await aiFeedback.mockReviewAPI({
			review_id: 'test-review-id',
			post_id: 1,
			model: 'gpt-4o',
			notes: [{ id: 1, content: 'Critical issue' }],
			note_ids: [1],
			block_mapping: {},
			summary: {
				by_severity: { critical: 1, suggestion: 2 },
				by_category: { content: 3 },
			},
			summary_text: 'Mixed feedback.',
			note_count: 3,
			timestamp: new Date().toISOString(),
		});

		await aiFeedback.startReviewAndWait();

		// Check severity display
		await expect(page.getByText('By Severity')).toBeVisible();
		await expect(page.getByText(/Critical/)).toBeVisible();
		await expect(page.getByText(/Suggestion/)).toBeVisible();
	});

	test('displays category breakdown in summary', async ({
		page,
		editor,
		aiFeedback,
	}) => {
		// Setup
		await page
			.getByRole('textbox', { name: 'Add title' })
			.fill('Category Test');
		await editor.insertBlock({ name: 'core/paragraph' });
		await page.keyboard.type('Test content.');
		await page.getByRole('button', { name: 'Save draft' }).click();
		await page.waitForSelector('.editor-post-saved-state.is-saved');

		await aiFeedback.openSidebar();

		await aiFeedback.mockReviewAPI({
			review_id: 'test-review-id',
			post_id: 1,
			model: 'gpt-4o',
			notes: [
				{ id: 1, content: 'Content feedback' },
				{ id: 2, content: 'Tone feedback' },
			],
			note_ids: [1, 2],
			block_mapping: {},
			summary: {
				by_severity: { suggestion: 2 },
				by_category: { content: 1, tone: 1 },
			},
			summary_text: 'Categorized feedback.',
			note_count: 2,
			timestamp: new Date().toISOString(),
		});

		await aiFeedback.startReviewAndWait();

		// Check category display
		await expect(page.getByText('By Category')).toBeVisible();
		await expect(page.getByText(/Content/i)).toBeVisible();
		await expect(page.getByText(/Tone/i)).toBeVisible();
	});

	test('displays model used in review summary', async ({
		page,
		editor,
		aiFeedback,
	}) => {
		// Setup
		await page
			.getByRole('textbox', { name: 'Add title' })
			.fill('Model Display Test');
		await editor.insertBlock({ name: 'core/paragraph' });
		await page.keyboard.type('Test content.');
		await page.getByRole('button', { name: 'Save draft' }).click();
		await page.waitForSelector('.editor-post-saved-state.is-saved');

		await aiFeedback.openSidebar();

		await aiFeedback.mockReviewAPI({
			review_id: 'test-review-id',
			post_id: 1,
			model: 'claude-sonnet-4-20250514',
			notes: [],
			note_ids: [],
			block_mapping: {},
			summary: { by_severity: {}, by_category: {} },
			summary_text: '',
			note_count: 0,
			timestamp: new Date().toISOString(),
		});

		await aiFeedback.startReviewAndWait();

		await expect(page.getByText('Reviewed with:')).toBeVisible();
		await expect(page.getByText('claude-sonnet-4-20250514')).toBeVisible();
	});
});
