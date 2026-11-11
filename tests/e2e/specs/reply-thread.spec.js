/**
 * E2E tests for the AI reply thread under a feedback note.
 *
 * The "Reply thread" tests mock the plugin's REST endpoints to pin down the
 * sidebar states. The "Reply loop" test runs the whole flow on the server with
 * the mock AI provider from tests/mu-plugins/ai-feedback-e2e.php.
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
 * Match a plugin REST route in both pretty and `?rest_route=` URLs.
 *
 * @param {RegExp} pattern Pattern for the route, such as `/ai-feedback/v1/replies`.
 * @return {Function} URL matcher for page.route().
 */
function restMatch(pattern) {
	return (url) => {
		const route =
			url.searchParams.get('rest_route') ??
			url.pathname.replace(/^.*\/wp-json/, '');
		return pattern.test(route);
	};
}

/**
 * Mock the REST endpoints the reply thread relies on.
 *
 * @param {import('@playwright/test').Page} page  Page.
 * @param {{status: string}}                state Mutable reply status.
 */
async function mockReplyEndpoints(page, state) {
	await page.route(
		restMatch(/^\/ai-feedback\/v1\/notes\/post\/\d+\/latest-review$/),
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

	await page.route(restMatch(/^\/ai-feedback\/v1\/replies$/), (route) => {
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
		restMatch(
			new RegExp(`^/ai-feedback/v1/replies/${USER_REPLY_ID}/status$`)
		),
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
			timeout: 20000,
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

/**
 * Mock reply from Reply_Service::MOCK_REPLY.
 */
const MOCK_AI_REPLY =
	'This is a mock AI reply for testing purposes. The conversation loop is working correctly.';

/**
 * Reply to a note the way the editor's Notes UI does.
 *
 * @param {import('@playwright/test').Page} page    Page.
 * @param {number}                          parent  Note to reply to.
 * @param {string}                          content Reply text.
 */
async function replyToNote(page, parent, content) {
	await page.evaluate(
		async ({ parentId, text }) => {
			const { select, dispatch } = window.wp.data;
			await dispatch('core').saveEntityRecord(
				'root',
				'comment',
				{
					post: select('core/editor').getCurrentPostId(),
					content: text,
					status: 'hold',
					type: 'note',
					parent: parentId,
				},
				{ throwOnError: true }
			);
		},
		{ parentId: parent, text: content }
	);
}

/**
 * Run due WP-Cron events. The e2e cookie keeps the AI provider mocked.
 *
 * @param {import('@playwright/test').Page} page    Page.
 * @param {string}                          baseURL Site URL.
 */
async function runCron(page, baseURL) {
	const response = await page.request.get(
		new URL('/wp-cron.php', baseURL).toString()
	);
	expect(response.ok()).toBe(true);
}

test.describe('Reply loop', () => {
	test('replies to a note get an AI reply through WP-Cron', async ({
		page,
		editor,
		admin,
		requestUtils,
		aiFeedback,
		baseURL,
	}) => {
		// Create the post over REST: content added in a freshly loaded editor
		// is sometimes lost before the save, which leaves nothing to review.
		const post = await requestUtils.createPost({
			title: 'Reply loop',
			content:
				'<!-- wp:paragraph --><p>Revenue grew 40% last year.</p><!-- /wp:paragraph -->',
			status: 'draft',
		});
		await admin.editPost(post.id);
		await expect
			.poll(() => editor.getBlocks().then((blocks) => blocks.length))
			.toBe(1);
		await aiFeedback.openSidebar();

		const response = await aiFeedback.runReview();
		const review = await response.json();
		const noteId = review.note_ids[0];
		expect(noteId).toBeGreaterThan(0);

		// First round: the user answers the AI note.
		await replyToNote(page, noteId, 'It is cited in paragraph three.');

		const thread = page.getByRole('list', { name: 'Conversation replies' });
		await expect(thread.locator('.ai-feedback-reply-user')).toContainText(
			'It is cited in paragraph three.',
			{ timeout: 10000 }
		);
		const pendingRow = thread.locator('.ai-feedback-reply-pending');
		await expect(pendingRow).toContainText('AI is replying…');

		await runCron(page, baseURL);

		const aiReplies = thread.locator('.ai-feedback-reply-ai');
		await expect(aiReplies).toHaveCount(1, { timeout: 30000 });
		await expect(aiReplies.first()).toContainText(MOCK_AI_REPLY);
		await expect(pendingRow).toHaveCount(0);
		await expect(page.locator('#a11y-speak-polite')).toContainText(
			'AI Feedback replied'
		);

		// Second round: the user answers the AI's reply in the same thread.
		await replyToNote(page, noteId, 'Linked it. Anything else?');

		await expect(thread.locator('.ai-feedback-reply-user')).toHaveCount(2, {
			timeout: 10000,
		});
		await expect(
			thread.locator('.ai-feedback-reply-pending')
		).toContainText('AI is replying…');

		await runCron(page, baseURL);

		await expect(aiReplies).toHaveCount(2, { timeout: 30000 });
		await expect(thread.locator('.ai-feedback-reply-pending')).toHaveCount(
			0
		);

		// Replies render oldest first, alternating user and AI.
		await expect(thread.locator('.ai-feedback-reply')).toHaveClass([
			/ai-feedback-reply-user/,
			/ai-feedback-reply-ai/,
			/ai-feedback-reply-user/,
			/ai-feedback-reply-ai/,
		]);
	});
});
