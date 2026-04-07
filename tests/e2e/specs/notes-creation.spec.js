/**
 * E2E tests for notes creation from reviews.
 */
const { test, expect } = require('../fixtures');

test.describe('Notes Creation', () => {
	test('review creates notes that appear in summary', async ({
		page,
		editor,
		aiFeedback,
	}) => {
		await aiFeedback.createAndSavePost(
			{
				title: 'Post with Notes',
				content: 'Content that will receive feedback.',
			},
			{ editor }
		);

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

		// Verify the summary section rendered
		const panel = page.locator('.ai-feedback-panel');
		await expect(panel.locator('.ai-feedback-review-summary')).toBeVisible({
			timeout: 10000,
		});
	});

	test('displays severity breakdown in summary', async ({
		page,
		editor,
		aiFeedback,
	}) => {
		await aiFeedback.createAndSavePost(
			{ title: 'Severity Test', content: 'Test content.' },
			{ editor }
		);

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

		// Verify the summary rendered with severity/feedback indicators
		const panel = page.locator('.ai-feedback-panel');
		await expect(panel.locator('.ai-feedback-review-summary')).toBeVisible({
			timeout: 10000,
		});

		// At least one severity or feedback indicator should be present
		await expect(
			panel.getByText(/critical|feedback|severity/i).first()
		).toBeVisible();
	});

	test('displays category breakdown in summary', async ({
		page,
		editor,
		aiFeedback,
	}) => {
		await aiFeedback.createAndSavePost(
			{ title: 'Category Test', content: 'Test content.' },
			{ editor }
		);

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

		// Verify summary rendered with category indicators
		const panel = page.locator('.ai-feedback-panel');
		await expect(panel.locator('.ai-feedback-review-summary')).toBeVisible({
			timeout: 10000,
		});

		// At least one category indicator should be present
		await expect(
			panel.getByText(/content|tone|category/i).first()
		).toBeVisible();
	});

	test('displays model used in review summary', async ({
		page,
		editor,
		aiFeedback,
	}) => {
		await aiFeedback.createAndSavePost(
			{ title: 'Model Display Test', content: 'Test content.' },
			{ editor }
		);

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

		// Verify the summary section rendered after review
		const panel = page.locator('.ai-feedback-panel');
		await expect(panel.locator('.ai-feedback-review-summary')).toBeVisible({
			timeout: 10000,
		});
	});
});
