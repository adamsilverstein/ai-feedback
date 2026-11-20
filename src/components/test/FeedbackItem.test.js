/**
 * External dependencies
 */
import { describe, it, expect, vi } from 'vitest';
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { createRegistry, RegistryProvider } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import FeedbackItem from '../FeedbackItem';

vi.mock('@wordpress/block-editor', () => ({ store: 'core/block-editor' }));
vi.mock('@wordpress/blocks', () => ({ store: 'core/blocks' }));
vi.mock('../../store', () => ({ STORE_NAME: 'ai-feedback/store' }));

const item = {
	id: 7,
	block_id: 'block-1',
	severity: 'important',
	title: 'Passive voice',
	feedback: 'This sentence is passive.',
	suggestion: 'Make it active.',
	is_resolved: false,
};

function renderItem(
	props,
	setNoteResolved = vi.fn(),
	{
		blocks = {},
		appliedFixes = {},
		updateBlockAttributes = vi.fn(),
		blockType = { attributes: { content: { type: 'rich-text' } } },
		notes = null,
		thread = undefined,
		saveEntityRecord = vi.fn(),
		setFixApplied = vi.fn(),
	} = {}
) {
	const registry = createRegistry();
	registry.register(noticesStore);
	registry.registerStore('core/editor', {
		reducer: (state = {}) => state,
		selectors: { getCurrentPostId: () => 1 },
	});
	registry.registerStore('core', {
		reducer: (state = notes) => state,
		actions: {
			saveEntityRecord: (...args) => {
				saveEntityRecord(...args);
				return { type: 'NOOP' };
			},
		},
		selectors: { getEntityRecords: (state) => state },
	});
	registry.registerStore('ai-feedback/store', {
		reducer: (state = {}) => state,
		actions: {
			setNoteResolved: (...args) => {
				setNoteResolved(...args);
				return { type: 'NOOP' };
			},
			setFixApplied: (...args) => {
				setFixApplied(...args);
				return { type: 'NOOP' };
			},
		},
		selectors: {
			isFixApplied: (state, noteId) => !!appliedFixes[noteId],
		},
	});
	registry.registerStore('core/block-editor', {
		reducer: (state = blocks, action) => {
			if (action.type !== 'UPDATE_BLOCK_ATTRIBUTES') {
				return state;
			}
			const block = state[action.clientId];
			return {
				...state,
				[action.clientId]: {
					...block,
					attributes: { ...block.attributes, ...action.attributes },
				},
			};
		},
		actions: {
			selectBlock: () => ({ type: 'NOOP' }),
			updateBlockAttributes: (clientId, attributes) => {
				updateBlockAttributes(clientId, attributes);
				return {
					type: 'UPDATE_BLOCK_ATTRIBUTES',
					clientId,
					attributes,
				};
			},
		},
		selectors: {
			getBlock: (state, clientId) =>
				state[clientId] ? { clientId, ...state[clientId] } : null,
			getClientIdsWithDescendants: (state) => Object.keys(state),
			getBlockRootClientId: () => null,
			getBlockIndex: (state, clientId) =>
				Object.keys(state).indexOf(clientId),
			getBlockOrder: (state, parent) =>
				parent ? [] : Object.keys(state),
		},
	});
	registry.registerStore('core/blocks', {
		reducer: (state = {}) => state,
		selectors: {
			getBlockType: () => blockType,
		},
	});

	render(
		<RegistryProvider value={registry}>
			<FeedbackItem item={{ ...item, ...props }} thread={thread} />
		</RegistryProvider>
	);

	return registry;
}

describe('FeedbackItem quick actions', () => {
	it('shows the feedback text and actions', () => {
		renderItem();

		expect(screen.getByText('Passive voice')).toBeInTheDocument();
		expect(screen.getByText('This sentence is passive.')).toBeVisible();
		expect(
			screen.getByRole('button', { name: 'Go to block' })
		).toBeInTheDocument();
		expect(
			screen.getByRole('button', {
				name: 'Dismiss feedback: Passive voice',
			})
		).toBeInTheDocument();
	});

	it('dismisses the item and offers undo', async () => {
		const setNoteResolved = vi.fn();
		const registry = renderItem({}, setNoteResolved);

		await act(async () => {
			fireEvent.click(
				screen.getByRole('button', {
					name: 'Dismiss feedback: Passive voice',
				})
			);
		});

		expect(setNoteResolved).toHaveBeenCalledWith(7, true);

		await waitFor(() =>
			expect(registry.select(noticesStore).getNotices()).toHaveLength(1)
		);
		const [notice] = registry.select(noticesStore).getNotices();
		expect(notice.content).toBe('Feedback dismissed.');

		await act(async () => {
			notice.actions[0].onClick();
		});
		expect(setNoteResolved).toHaveBeenLastCalledWith(7, false);
	});

	it('shows dismissed items collapsed with a restore action', async () => {
		const setNoteResolved = vi.fn();
		renderItem({ is_resolved: true }, setNoteResolved);

		expect(screen.getByText('Dismissed')).toBeInTheDocument();
		expect(
			screen.queryByText('This sentence is passive.')
		).not.toBeInTheDocument();

		await act(async () => {
			fireEvent.click(
				screen.getByRole('button', {
					name: 'Restore feedback: Passive voice',
				})
			);
		});
		expect(setNoteResolved).toHaveBeenCalledWith(7, false);
	});

	it('hides dismiss for items without a note ID', () => {
		renderItem({ id: undefined });

		expect(
			screen.queryByRole('button', { name: /Dismiss/ })
		).not.toBeInTheDocument();
	});
});

describe('FeedbackItem quick fixes', () => {
	const fixItem = {
		...item,
		quick_fix: { find: 'is passive', replace: 'reads actively' },
	};
	const blocks = {
		'block-1': {
			name: 'core/paragraph',
			attributes: { content: 'The sentence <em>is passive</em> here.' },
		},
	};

	it('describes the fix and applies it with undo', async () => {
		const updateBlockAttributes = vi.fn();
		const setNoteResolved = vi.fn();
		const registry = renderItem(fixItem, setNoteResolved, {
			blocks,
			updateBlockAttributes,
		});

		expect(
			screen.getByText('Replace “is passive” with “reads actively”')
		).toBeInTheDocument();

		await act(async () => {
			fireEvent.click(screen.getByRole('button', { name: 'Apply fix' }));
		});

		expect(updateBlockAttributes).toHaveBeenCalledWith('block-1', {
			content: 'The sentence <em>reads actively</em> here.',
		});
		expect(setNoteResolved).toHaveBeenCalledWith(7, true);

		const [notice] = registry.select(noticesStore).getNotices();
		expect(notice.content).toBe('Fix applied.');

		await act(async () => {
			notice.actions[0].onClick();
		});
		expect(updateBlockAttributes).toHaveBeenLastCalledWith('block-1', {
			content: 'The sentence <em>is passive</em> here.',
		});
		expect(setNoteResolved).toHaveBeenLastCalledWith(7, false);
	});

	it('applies a fix that deletes all of the text', async () => {
		const updateBlockAttributes = vi.fn();
		renderItem(
			{ quick_fix: { find: 'Delete me.', replace: '' } },
			vi.fn(),
			{
				blocks: {
					'block-1': {
						name: 'core/paragraph',
						attributes: { content: 'Delete me.' },
					},
				},
				updateBlockAttributes,
			}
		);

		await act(async () => {
			fireEvent.click(screen.getByRole('button', { name: 'Apply fix' }));
		});

		expect(updateBlockAttributes).toHaveBeenCalledWith('block-1', {
			content: '',
		});
	});

	it('refuses to undo over later edits', async () => {
		const updateBlockAttributes = vi.fn();
		const registry = renderItem(fixItem, vi.fn(), {
			blocks,
			updateBlockAttributes,
		});

		await act(async () => {
			fireEvent.click(screen.getByRole('button', { name: 'Apply fix' }));
		});

		// The user keeps typing in the block.
		await act(async () => {
			registry
				.dispatch('core/block-editor')
				.updateBlockAttributes('block-1', { content: 'Edited later.' });
		});

		const notice = registry
			.select(noticesStore)
			.getNotices()
			.find(({ content }) => content === 'Fix applied.');
		await act(async () => {
			notice.actions[0].onClick();
		});

		expect(updateBlockAttributes).toHaveBeenLastCalledWith('block-1', {
			content: 'Edited later.',
		});
		expect(
			registry
				.select(noticesStore)
				.getNotices()
				.some(({ content }) => content.includes('has changed'))
		).toBe(true);
	});

	it('hides the fix for blocks without rich text content', () => {
		renderItem(fixItem, vi.fn(), {
			blocks: {
				'block-1': { name: 'core/image', attributes: { url: 'x' } },
			},
			blockType: { attributes: { url: { type: 'string' } } },
		});

		expect(
			screen.queryByRole('button', { name: 'Apply fix' })
		).not.toBeInTheDocument();
	});

	it('shows a preview before applying', async () => {
		renderItem(fixItem, vi.fn(), { blocks });

		fireEvent.click(screen.getByRole('button', { name: 'Preview' }));

		const dialog = screen.getByRole('dialog', { name: 'Preview fix' });
		expect(dialog.querySelector('del')).toHaveTextContent('is passive');
		expect(dialog.querySelector('ins')).toHaveTextContent('reads actively');
	});

	it('disables the fix when the block text has changed', () => {
		renderItem(fixItem, vi.fn(), {
			blocks: {
				'block-1': {
					name: 'core/paragraph',
					attributes: { content: 'Rewritten already.' },
				},
			},
		});

		expect(
			screen.getByRole('button', { name: 'Apply fix' })
		).toHaveAttribute('aria-disabled', 'true');
		expect(
			screen.getByText(/this fix can no longer be applied/)
		).toBeInTheDocument();
	});

	it('disables the fix while the block holds a suggestion', () => {
		renderItem(fixItem, vi.fn(), {
			blocks: {
				'block-1': {
					name: 'core/paragraph',
					attributes: {
						content:
							'The <mark class="wp-suggestion-add" data-suggestion-id="9">short</mark><mark class="wp-suggestion-del" data-suggestion-id="9">long</mark> sentence is passive here.',
					},
				},
			},
		});

		expect(
			screen.getByRole('button', { name: 'Apply fix' })
		).toHaveAttribute('aria-disabled', 'true');
		expect(screen.getByRole('button', { name: 'Preview' })).toHaveAttribute(
			'aria-disabled',
			'true'
		);
		expect(
			screen.getByText(/This block has a suggested edit/)
		).toBeInTheDocument();
	});

	it('labels applied fixes', () => {
		renderItem({ ...fixItem, is_resolved: true }, vi.fn(), {
			blocks,
			appliedFixes: { 7: true },
		});

		expect(screen.getByText('Fix applied')).toBeInTheDocument();
		expect(
			screen.queryByRole('button', { name: 'Apply fix' })
		).not.toBeInTheDocument();
	});

	it('points to the Notes sidebar once the fix is a suggestion', () => {
		renderItem({ ...fixItem, suggestion_status: 'pending' }, vi.fn(), {
			blocks,
		});

		expect(
			screen.getByText('Replace “is passive” with “reads actively”')
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'Suggested in the text. Accept or reject it from the Notes sidebar.'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByRole('button', { name: 'Apply fix' })
		).not.toBeInTheDocument();
	});

	it('hides dismiss while the suggestion is pending', () => {
		renderItem({ ...fixItem, suggestion_status: 'pending' }, vi.fn(), {
			blocks,
		});

		expect(
			screen.queryByRole('button', { name: /^Dismiss feedback/ })
		).not.toBeInTheDocument();
	});

	it('offers dismiss again once the suggestion is decided', () => {
		renderItem({ ...fixItem, suggestion_status: 'pending' }, vi.fn(), {
			blocks,
			notes: [
				{
					id: 7,
					meta: {
						_wp_suggestion: '{"schemaVersion":2}',
						_wp_suggestion_status: 'rejected',
					},
				},
			],
		});

		expect(
			screen.getByRole('button', { name: /^Dismiss feedback/ })
		).toBeInTheDocument();
	});

	it('reads the status Gutenberg wrote to the note', () => {
		renderItem({ ...fixItem, suggestion_status: 'pending' }, vi.fn(), {
			blocks,
			notes: [
				{
					id: 7,
					meta: {
						_wp_suggestion: '{"schemaVersion":2}',
						_wp_suggestion_status: 'applied-unsaved',
					},
				},
			],
		});

		expect(
			screen.getByText(
				'You accepted this suggested edit. Save the post to keep it.'
			)
		).toBeInTheDocument();
	});

	it('offers the fix again once the note is no longer a suggestion', () => {
		renderItem({ ...fixItem, suggestion_status: 'pending' }, vi.fn(), {
			blocks,
			notes: [{ id: 7, meta: { _wp_suggestion: '' } }],
		});

		expect(
			screen.getByRole('button', { name: 'Apply fix' })
		).toBeInTheDocument();
	});

	it('shows a decided suggestion', () => {
		renderItem({ ...fixItem, suggestion_status: 'applied' }, vi.fn(), {
			blocks,
		});

		expect(
			screen.getByText('You accepted this suggested edit.')
		).toBeInTheDocument();
	});
});

describe('FeedbackItem Make this change', () => {
	it('replies for the author on a note with a suggestion', async () => {
		const saveEntityRecord = vi.fn();
		renderItem({}, vi.fn(), { saveEntityRecord });

		await act(async () => {
			fireEvent.click(
				screen.getByRole('button', { name: 'Make this change' })
			);
		});

		expect(saveEntityRecord).toHaveBeenCalledWith(
			'root',
			'comment',
			{
				post: 1,
				parent: 7,
				type: 'note',
				status: 'hold',
				content: 'Please make this change.',
			},
			{ throwOnError: true }
		);
	});

	it('replies in the thread of a secondary feedback item', async () => {
		const saveEntityRecord = vi.fn();
		renderItem({ id: 12, parent: 7 }, vi.fn(), { saveEntityRecord });

		await act(async () => {
			fireEvent.click(
				screen.getByRole('button', { name: 'Make this change' })
			);
		});

		expect(saveEntityRecord).toHaveBeenCalledWith(
			'root',
			'comment',
			expect.objectContaining({ parent: 7 }),
			{ throwOnError: true }
		);
	});

	it.each([
		[
			'the note has a quick fix',
			{ quick_fix: { find: 'a', replace: 'b' } },
			undefined,
		],
		[
			'the note has a structural edit',
			{ block_action: { type: 'remove_block' } },
			undefined,
		],
		['the note has no suggestion', { suggestion: '' }, undefined],
		['the note is dismissed', { is_resolved: true }, undefined],
		[
			'a reply is waiting on the AI',
			{},
			{ replies: [], pending_reply_id: 9 },
		],
		[
			'an AI reply already proposed an edit',
			{},
			{
				replies: [
					{
						id: 9,
						is_ai: true,
						content: 'Done.',
						edit: { type: 'replace_text', find: 'a', replace: 'b' },
					},
				],
				pending_reply_id: null,
			},
		],
	])('is hidden when %s', (_label, props, thread) => {
		renderItem(props, vi.fn(), { thread });

		expect(
			screen.queryByRole('button', { name: 'Make this change' })
		).not.toBeInTheDocument();
	});
});

describe('FeedbackItem reply edits', () => {
	const blocks = {
		'block-1': {
			name: 'core/paragraph',
			attributes: { content: 'Revenue grew 40% last year.' },
		},
	};
	const replyWith = (edit) => ({
		replies: [
			{ id: 8, is_ai: false, author: 'Jane', content: 'Do it.' },
			{
				id: 9,
				is_ai: true,
				author: 'Fresh Eyes',
				content: 'Done.',
				edit,
			},
		],
		pending_reply_id: null,
	});
	const textEdit = {
		type: 'replace_text',
		find: 'last year',
		replace: 'in 2025',
	};
	const rewrite = {
		type: 'rewrite_block',
		content: 'Revenue grew <strong>40%</strong> in 2025.',
		original: 'Revenue grew 40% last year.',
	};

	it('applies a text edit from a reply in one update', async () => {
		const updateBlockAttributes = vi.fn();
		const setNoteResolved = vi.fn();
		const setFixApplied = vi.fn();
		const registry = renderItem({}, setNoteResolved, {
			blocks,
			updateBlockAttributes,
			setFixApplied,
			thread: replyWith(textEdit),
		});

		expect(
			screen.getByText('Replace “last year” with “in 2025”')
		).toBeInTheDocument();

		await act(async () => {
			fireEvent.click(
				screen.getByRole('button', { name: 'Apply change' })
			);
		});

		expect(updateBlockAttributes).toHaveBeenCalledTimes(1);
		expect(updateBlockAttributes).toHaveBeenCalledWith('block-1', {
			content: 'Revenue grew 40% in 2025.',
		});
		// The thread stays open so the author can ask for a tweak.
		expect(setNoteResolved).not.toHaveBeenCalled();
		expect(setFixApplied).toHaveBeenCalledTimes(1);
		expect(setFixApplied).toHaveBeenCalledWith(9, true);
		expect(
			registry
				.select(noticesStore)
				.getNotices()
				.some(({ content }) => content === 'Change applied.')
		).toBe(true);
	});

	it('previews a rewrite as a word diff and applies it', async () => {
		const updateBlockAttributes = vi.fn();
		renderItem({}, vi.fn(), {
			blocks,
			updateBlockAttributes,
			thread: replyWith(rewrite),
		});

		expect(screen.getByText('Rewrite this block')).toBeInTheDocument();
		fireEvent.click(screen.getByRole('button', { name: 'Preview' }));

		const dialog = screen.getByRole('dialog', { name: 'Preview change' });
		const removed = Array.from(dialog.querySelectorAll('del')).map(
			(node) => node.textContent
		);
		const added = Array.from(dialog.querySelectorAll('ins')).map(
			(node) => node.textContent
		);
		// Bolding "40%" changes it too; changes split by a space read as one.
		expect(removed).toEqual(['40% last year']);
		expect(added).toEqual(['40% in 2025']);
		expect(
			dialog.querySelector('.ai-feedback-quick-fix-diff')
		).toHaveTextContent('Revenue grew 40% last year40% in 2025.');

		await act(async () => {
			fireEvent.click(
				within(dialog).getByRole('button', { name: 'Apply change' })
			);
		});
		expect(updateBlockAttributes).toHaveBeenCalledWith('block-1', {
			content: rewrite.content,
		});
	});

	it('marks a rewrite outdated once the block text changed', () => {
		renderItem({}, vi.fn(), {
			blocks: {
				'block-1': {
					name: 'core/paragraph',
					attributes: { content: 'Revenue grew 45% last year.' },
				},
			},
			thread: replyWith(rewrite),
		});

		expect(
			screen.getByRole('button', { name: 'Apply change' })
		).toHaveAttribute('aria-disabled', 'true');
		expect(screen.getByText(/This change is outdated/)).toBeInTheDocument();
	});

	it('shows an applied reply edit as applied', () => {
		renderItem({}, vi.fn(), {
			blocks,
			appliedFixes: { 9: true },
			thread: replyWith(textEdit),
		});

		expect(
			screen.getByText('You applied this change.')
		).toBeInTheDocument();
		expect(
			screen.queryByRole('button', { name: 'Apply change' })
		).not.toBeInTheDocument();
	});

	it('shows an edit already in the block as applied after a reload', () => {
		renderItem({}, vi.fn(), {
			blocks: {
				'block-1': {
					name: 'core/paragraph',
					attributes: { content: 'Revenue grew 40% in 2025.' },
				},
			},
			thread: replyWith(textEdit),
		});

		expect(
			screen.getByText('The block already has this change.')
		).toBeInTheDocument();
		expect(
			screen.queryByText(/This change is outdated/)
		).not.toBeInTheDocument();
	});

	it('defers to Gutenberg once the reply edit is a suggestion', () => {
		renderItem({}, vi.fn(), {
			blocks,
			thread: replyWith(textEdit),
			notes: [
				{
					id: 9,
					meta: {
						_wp_suggestion: '{"schemaVersion":2}',
						_wp_suggestion_status: 'pending',
					},
				},
			],
		});

		expect(
			screen.getByText(
				'Suggested in the text. Accept or reject it from the Notes sidebar.'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByRole('button', { name: 'Apply change' })
		).not.toBeInTheDocument();
	});
});

describe('FeedbackItem refinement', () => {
	const blocks = {
		'block-1': {
			name: 'core/paragraph',
			attributes: { content: 'Revenue grew 40% last year.' },
		},
	};
	const quickFix = { find: 'last year', replace: 'in 2025' };
	const firstEdit = {
		type: 'replace_text',
		find: 'last year',
		replace: 'in fiscal 2025',
	};
	const revisedEdit = {
		type: 'replace_text',
		find: 'last year',
		replace: 'in FY25',
	};
	const thread = {
		replies: [
			{ id: 8, is_ai: false, author: 'Jane', content: 'Shorter.' },
			{
				id: 9,
				is_ai: true,
				author: 'Fresh Eyes',
				content: 'Done.',
				edit: firstEdit,
				revision: 2,
			},
			{ id: 10, is_ai: false, author: 'Jane', content: 'Shorter.' },
			{
				id: 11,
				is_ai: true,
				author: 'Fresh Eyes',
				content: 'Shorter now.',
				edit: revisedEdit,
				revision: 3,
			},
		],
		pending_reply_id: null,
	};

	it('only lets the newest revision be applied', async () => {
		const updateBlockAttributes = vi.fn();
		renderItem({ quick_fix: quickFix }, vi.fn(), {
			blocks,
			updateBlockAttributes,
			thread,
		});

		// The review's quick fix and the first revision are replaced.
		expect(
			screen.getAllByText('Replaced by a revised suggestion.')
		).toHaveLength(2);
		expect(
			screen.queryByRole('button', { name: 'Apply fix' })
		).not.toBeInTheDocument();
		expect(screen.getByText('Revised suggestion (2)')).toBeInTheDocument();
		expect(screen.getByText('Revised suggestion (3)')).toBeInTheDocument();

		const apply = screen.getAllByRole('button', { name: 'Apply change' });
		expect(apply).toHaveLength(1);
		await act(async () => {
			fireEvent.click(apply[0]);
		});

		expect(updateBlockAttributes).toHaveBeenCalledWith('block-1', {
			content: 'Revenue grew 40% in FY25.',
		});
	});

	it('keeps an applied revision applied once a newer one arrives', () => {
		renderItem({}, vi.fn(), {
			blocks,
			thread,
			appliedFixes: { 9: true },
		});

		expect(
			screen.getByText('You applied this change.')
		).toBeInTheDocument();
		expect(
			screen.queryByText('Replaced by a revised suggestion.')
		).not.toBeInTheDocument();
	});

	it('offers to refine a pending edit with quick replies', async () => {
		const saveEntityRecord = vi.fn();
		renderItem({}, vi.fn(), { blocks, thread, saveEntityRecord });

		const group = screen.getByRole('group', { name: 'Quick replies' });
		expect(
			within(group)
				.getAllByRole('button')
				.map((button) => button.textContent)
		).toEqual(['Shorter', 'Try another', 'Why?']);

		await act(async () => {
			fireEvent.click(
				within(group).getByRole('button', { name: 'Shorter' })
			);
		});

		expect(saveEntityRecord).toHaveBeenCalledWith(
			'root',
			'comment',
			{
				post: 1,
				parent: 7,
				type: 'note',
				status: 'hold',
				content: 'Make it shorter.',
			},
			{ throwOnError: true }
		);
		// Hidden until the thread shows the reply, so it is sent once.
		expect(
			screen.queryByRole('group', { name: 'Quick replies' })
		).not.toBeInTheDocument();
	});

	it('offers a review quick fix the same quick replies', () => {
		renderItem({ quick_fix: quickFix }, vi.fn(), { blocks });

		expect(
			within(screen.getByRole('group', { name: 'Quick replies' }))
				.getAllByRole('button')
				.map((button) => button.textContent)
		).toEqual(['Shorter', 'Try another', 'Why?']);
	});

	it.each([
		['applied', { appliedFixes: { 11: true } }],
		[
			'rejected',
			{
				notes: [
					{
						id: 11,
						meta: {
							_wp_suggestion: '{}',
							_wp_suggestion_status: 'rejected',
						},
					},
				],
			},
		],
	])(
		'offers to make a change once the newest edit is %s',
		(_label, options) => {
			renderItem({}, vi.fn(), { blocks, thread, ...options });

			expect(
				within(screen.getByRole('group', { name: 'Quick replies' }))
					.getAllByRole('button')
					.map((button) => button.textContent)
			).toEqual(['Make that change', 'Show me an example', 'Why?']);
		}
	);

	it('offers to make a change once the newest edit is in the block after a reload', () => {
		renderItem({}, vi.fn(), {
			blocks: {
				'block-1': {
					name: 'core/paragraph',
					attributes: { content: 'Revenue grew 40% in FY25.' },
				},
			},
			thread,
		});

		expect(
			within(screen.getByRole('group', { name: 'Quick replies' }))
				.getAllByRole('button')
				.map((button) => button.textContent)
		).toEqual(['Make that change', 'Show me an example', 'Why?']);
	});

	it('locks a secondary note’s quick fix once a reply proposes an edit', () => {
		renderItem({ id: 12, parent: 7, quick_fix: quickFix }, vi.fn(), {
			blocks,
			thread,
		});

		expect(
			screen.queryByRole('button', { name: 'Apply fix' })
		).not.toBeInTheDocument();
		expect(
			screen.getAllByRole('button', { name: 'Apply change' })
		).toHaveLength(1);
	});

	it('does not offer to reapply an applied review fix that was superseded', () => {
		renderItem({ quick_fix: quickFix }, vi.fn(), {
			blocks,
			thread,
			appliedFixes: { 7: true },
		});

		expect(screen.getByText('You applied this fix.')).toBeInTheDocument();
		expect(
			screen.queryByRole('button', { name: 'Apply fix' })
		).not.toBeInTheDocument();
	});

	it('keeps the quick replies after a failed send', async () => {
		renderItem({}, vi.fn(), {
			blocks,
			thread,
			saveEntityRecord: () => {
				throw new Error('Offline');
			},
		});

		await act(async () => {
			fireEvent.click(screen.getByRole('button', { name: 'Why?' }));
		});

		expect(
			screen.getByRole('group', { name: 'Quick replies' })
		).toBeInTheDocument();
	});
});

describe('FeedbackItem structural edits', () => {
	it('offers the review note’s structural edit instead of a quick fix', () => {
		renderItem(
			{
				block_action: { type: 'remove_block' },
				quick_fix: { find: 'Revenue', replace: 'Income' },
			},
			vi.fn(),
			{
				blocks: {
					'block-1': {
						name: 'core/paragraph',
						attributes: { content: 'Revenue grew 40% last year.' },
					},
				},
			}
		);

		expect(screen.getByText('Remove this block')).toBeInTheDocument();
		expect(
			screen.getByRole('button', { name: 'Apply change' })
		).toBeInTheDocument();
		expect(
			screen.queryByRole('button', { name: 'Apply fix' })
		).not.toBeInTheDocument();
	});
});
