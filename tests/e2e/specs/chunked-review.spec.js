/**
 * E2E tests for reviewing large documents in several requests.
 */
const { test, expect } = require('../fixtures');

test.describe('Chunked review', () => {
	test('reviews a long document in parts', async ({
		admin,
		page,
		editor,
		aiFeedback,
	}) => {
		await admin.createNewPost({ title: 'Long post' });
		for (let i = 1; i <= 25; i++) {
			await editor.insertBlock({
				name: 'core/paragraph',
				attributes: { content: `Paragraph number ${i}.` },
			});
		}
		await page.getByRole('button', { name: 'Save draft' }).click();
		await page.waitForSelector('.editor-post-saved-state.is-saved');

		await aiFeedback.openSidebar();

		const requests = [];
		// Match both pretty permalinks and the ?rest_route= form, plus any
		// query args apiFetch adds.
		const isReviewRequest = (url) =>
			/\/ai-feedback\/v1\/review(?:$|[?&])/.test(
				decodeURIComponent(url.toString())
			);
		await page.route(isReviewRequest, async (route) => {
			const data = route.request().postDataJSON();
			requests.push(data);
			const isLast = data.chunk_index === data.chunk_total - 1;

			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					review_id: 'chunked-review-id',
					notes: [],
					note_ids: [],
					block_mapping: {},
					summary: {
						total_notes: 0,
						by_category: {},
						by_severity: {},
					},
					summary_text: isLast
						? 'Whole document summary.'
						: `Summary of part ${data.chunk_index + 1}.`,
					note_count: 0,
					timestamp: new Date().toISOString(),
					chunk: { index: data.chunk_index, total: data.chunk_total },
				}),
			});
		});

		await aiFeedback.startReviewAndWait();

		expect(requests).toHaveLength(2);
		expect(requests[0]).toMatchObject({ chunk_index: 0, chunk_total: 2 });
		expect(requests[0].blocks).toHaveLength(20);
		expect(requests[0]).not.toHaveProperty('review_id');
		expect(requests[1]).toMatchObject({
			chunk_index: 1,
			chunk_total: 2,
			review_id: 'chunked-review-id',
			previous_summaries: ['Summary of part 1.'],
		});
		expect(requests[1].blocks).toHaveLength(5);

		await expect(
			page
				.locator('.ai-feedback-panel')
				.getByText('Whole document summary.')
		).toBeVisible();
	});
});
