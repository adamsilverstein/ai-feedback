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
	} = {}
) {
	const registry = createRegistry();
	registry.register(noticesStore);
	registry.registerStore('ai-feedback/store', {
		reducer: (state = {}) => state,
		actions: {
			setNoteResolved: (...args) => {
				setNoteResolved(...args);
				return { type: 'NOOP' };
			},
			setFixApplied: () => ({ type: 'NOOP' }),
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
			getBlock: (state, clientId) => state[clientId] ?? null,
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
			<FeedbackItem item={{ ...item, ...props }} />
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
});
