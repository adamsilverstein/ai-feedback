/**
 * E2E tests for the AI reply thread under a feedback note.
 *
 * The AI provider is mocked at the REST layer: the latest review, the reply
 * threads, and the reply status endpoint all return canned responses, so the
 * test covers the sidebar's half of the reply-to-AI-reply flow.
 */
const { test, expect } = require('../fixtures');

const AI_NOTE_ID = 300;
const USER_REPLY_ID = 301;
const AI_REPLY_ID = 302;

const USER_REPLY = {
	id: USER_REPLY_ID,
	author: 'admin',
	content: 'It is cited in paragraph three.',
	is_ai: false,
};

const AI_REPLY = {
	id: AI_REPLY_ID,
	author: 'AI Feedback',
	content: 'Thanks, then link that citation here so readers see it.',
	is_ai: true,
};

/**
 * Mock the REST endpoints the reply thread relies on.
 *
 * @param {import('@playwright/test').Page} page  Page.
 * @param {{status: string}}                state Mutable reply status.
 */
async function mockReplyEndpoints(page, state) {
	await page.route(
		/\/ai-feedback\/v1\/notes\/post\/\d+\/latest-review/,
		(route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					has_review: true,
					review: {
						review_id: 'reply-thread-review',
						post_id: 1,
						model: 'test-model',
						notes: [
							{
								id: AI_NOTE_ID,
								parent: 0,
								block_id: 'block-1',
								severity: 'important',
								category: 'content',
								title: 'Add a source',
								feedback:
									'Cite where the 40% growth figure comes from.',
							},
						],
						note_ids: [AI_NOTE_ID],
						block_mapping: {},
						summary: {
							by_severity: {
								critical: 0,
								important: 1,
								suggestion: 0,
							},
							by_category: {
								content: 1,
								tone: 0,
								flow: 0,
								design: 0,
							},
						},
						note_count: 1,
						timestamp: new Date().toISOString(),
						is_persisted: true,
					},
				}),
			})
	);

	await page.route(/\/ai-feedback\/v1\/replies\?/, (route) => {
		const isComplete = state.status === 'complete';
		return route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				post: 1,
				threads: {
					[AI_NOTE_ID]: {
						replies: isComplete
							? [USER_REPLY, AI_REPLY]
							: [USER_REPLY],
						pending_reply_id: isComplete ? null : USER_REPLY_ID,
					},
				},
			}),
		});
	});

	await page.route(
		new RegExp(`/ai-feedback/v1/replies/${USER_REPLY_ID}/status`),
		(route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					reply_id: USER_REPLY_ID,
					status: state.status,
					...(state.status === 'complete'
						? { ai_reply_comment_id: AI_REPLY_ID }
						: {}),
				}),
			})
	);
}

test.describe('Reply thread', () => {
	test('shows the pending AI reply and then the AI response', async ({
		page,
		editor,
		aiFeedback,
	}) => {
		const state = { status: 'pending' };
		await mockReplyEndpoints(page, state);

		await aiFeedback.createAndSavePost(
			{ title: 'Reply thread', content: 'Revenue grew 40% last year.' },
			{ editor }
		);
		await aiFeedback.openSidebar();

		const thread = page.getByRole('list', { name: 'Conversation replies' });
		await expect(thread).toBeVisible({ timeout: 10000 });

		// The user's reply renders, marked as a user reply.
		const userReply = thread.locator('.ai-feedback-reply-user');
		await expect(userReply).toContainText(USER_REPLY.content);

		// While the AI reply is generated, a live region shows progress.
		const pendingRow = thread.locator('.ai-feedback-reply-pending');
		await expect(pendingRow).toHaveAttribute('aria-live', 'polite');
		await expect(pendingRow).toContainText('AI is replying…');

		// The cron job finishes; the next poll reports completion.
		state.status = 'complete';

		const aiReply = thread.locator('.ai-feedback-reply-ai');
		await expect(aiReply).toContainText(AI_REPLY.content, {
			timeout: 10000,
		});
		await expect(aiReply).toContainText('AI Feedback');
		await expect(thread.locator('.ai-feedback-reply-pending')).toHaveCount(
			0
		);
	});

	test('explains when the AI reply fails', async ({
		page,
		editor,
		aiFeedback,
	}) => {
		const state = { status: 'failed' };
		await mockReplyEndpoints(page, state);

		await aiFeedback.createAndSavePost(
			{ title: 'Reply failure', content: 'Revenue grew 40% last year.' },
			{ editor }
		);
		await aiFeedback.openSidebar();

		const pendingRow = page.locator('.ai-feedback-reply-pending');
		await expect(pendingRow).toContainText(
			'The AI reply could not be generated.',
			{ timeout: 10000 }
		);
	});
});
