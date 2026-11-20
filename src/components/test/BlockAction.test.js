/**
 * External dependencies
 */
import { describe, it, expect, vi } from 'vitest';
import { act, fireEvent, render, screen, within } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { createRegistry, RegistryProvider } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import BlockAction from '../BlockAction';

vi.mock('@wordpress/block-editor', () => ({ store: 'core/block-editor' }));
vi.mock('@wordpress/blocks', () => ({
	store: 'core/blocks',
	createBlock: (name, attributes = {}, innerBlocks = []) => ({
		clientId: 'new',
		name,
		attributes,
		innerBlocks,
	}),
	serialize: (block) => `<p>${block.attributes.content ?? ''}</p>`,
}));
vi.mock('../../store', () => ({ STORE_NAME: 'ai-feedback/store' }));
vi.mock('../../utils/navigate-to-block', () => ({ navigateToBlock: vi.fn() }));

const note = { id: 7, block_id: 'rep', is_resolved: false };

/**
 * Render a structural edit against a small post.
 *
 * @param {Object} props   BlockAction props.
 * @param {Object} options Registry state and spies.
 * @return {Object} Spies.
 */
function renderAction(props, options = {}) {
	const spies = {
		removeBlocks: vi.fn(),
		moveBlocksToPosition: vi.fn(),
		insertBlocks: vi.fn(),
		toggleBlockHighlight: vi.fn(),
		setNoteResolved: vi.fn(),
		setFixApplied: vi.fn(),
		undo: vi.fn(),
	};
	let order = options.order ?? ['intro', 'bg', 'rep'];
	const blocks = {
		intro: { name: 'core/heading', content: 'Intro' },
		bg: { name: 'core/paragraph', content: 'Background.' },
		rep: { name: 'core/paragraph', content: 'Repeats.' },
		...options.blocks,
	};
	const getBlock = (id) =>
		order.includes(id) && blocks[id]
			? {
					clientId: id,
					name: blocks[id].name,
					attributes: {
						content: blocks[id].content,
						level: blocks[id].level,
						metadata:
							options.noteIds?.[id] || options.markers?.[id]
								? {
										noteId: options.noteIds?.[id],
										suggestion: options.markers?.[id],
									}
								: undefined,
					},
					innerBlocks: [],
				}
			: null;

	const registry = createRegistry();
	registry.register(noticesStore);
	registry.registerStore('core/editor', {
		reducer: (state = {}) => state,
		actions: {
			undo: () => {
				spies.undo();
				return { type: 'NOOP' };
			},
		},
		selectors: { getCurrentPostId: () => 1 },
	});
	registry.registerStore('core', {
		reducer: (state = options.notes ?? null) => state,
		selectors: { getEntityRecords: (state) => state },
	});
	registry.registerStore('ai-feedback/store', {
		reducer: (state = {}) => state,
		actions: {
			setNoteResolved: (...args) => {
				spies.setNoteResolved(...args);
				return { type: 'NOOP' };
			},
			setFixApplied: (...args) => {
				spies.setFixApplied(...args);
				return { type: 'NOOP' };
			},
		},
		selectors: {
			isFixApplied: (state, id) => !!options.applied?.[id],
		},
	});
	const record =
		(name) =>
		(...args) => {
			spies[name](...args);
			return { type: 'NOOP' };
		};
	// Changes the post the way the block editor would.
	const change =
		(name, update) =>
		(...args) => {
			spies[name](...args);
			update(...args);
			return { type: 'CHANGED', order: [...order] };
		};
	registry.registerStore('core/block-editor', {
		reducer: (state = { order }, action) =>
			action.type === 'CHANGED' ? { order: action.order } : state,
		actions: {
			removeBlocks: change('removeBlocks', (ids) => {
				order = order.filter((id) => !ids.includes(id));
			}),
			moveBlocksToPosition: change(
				'moveBlocksToPosition',
				(ids, from, to, index) => {
					order = order.filter((id) => !ids.includes(id));
					order.splice(index, 0, ...ids);
				}
			),
			insertBlocks: change('insertBlocks', ([block], index) => {
				blocks.new = {
					name: block.name,
					content: block.attributes.content,
					level: block.attributes.level,
				};
				order = [...order];
				order.splice(index, 0, 'new');
			}),
			toggleBlockHighlight: record('toggleBlockHighlight'),
			__unstableMarkLastChangeAsPersistent: () => ({ type: 'NOOP' }),
		},
		selectors: {
			getBlocks: (state) => state,
			getBlock: (state, id) => getBlock(id),
			getBlockAttributes: (state, id) => getBlock(id)?.attributes ?? null,
			getClientIdsWithDescendants: () => order,
			getBlockRootClientId: () => null,
			getBlockIndex: (state, id) => order.indexOf(id),
			getBlockOrder: (state, parent) =>
				parent
					? (options.innerOf?.[parent] ?? [])
					: order.filter(
							(id) =>
								!Object.values(options.innerOf ?? {})
									.flat()
									.includes(id)
						),
		},
	});
	registry.registerStore('core/blocks', {
		reducer: (state = {}) => state,
		selectors: {
			getBlockType: (state, name) => ({
				title: {
					'core/paragraph': 'Paragraph',
					'core/heading': 'Heading',
				}[name],
			}),
		},
	});

	render(
		<RegistryProvider value={registry}>
			<BlockAction item={note} {...props} />
		</RegistryProvider>
	);
	return { spies, registry };
}

const move = {
	type: 'move_block',
	position: 'before',
	target_block_id: 'intro',
	target_name: 'core/heading',
	target_text: 'Intro',
};

describe('BlockAction', () => {
	it('describes a removal and applies it as the review note’s fix', () => {
		const { spies, registry } = renderAction({
			action: { type: 'remove_block' },
		});

		expect(screen.getByText('Remove this block')).toBeInTheDocument();
		fireEvent.click(screen.getByRole('button', { name: 'Apply change' }));

		expect(spies.removeBlocks).toHaveBeenCalledWith(['rep'], false);
		expect(spies.setFixApplied).toHaveBeenCalledWith(7, true);
		expect(spies.setNoteResolved).toHaveBeenCalledWith(7, true);
		const [notice] = registry.select(noticesStore).getNotices();
		expect(notice.content).toBe('Change applied.');

		notice.actions[0].onClick();
		expect(spies.undo).toHaveBeenCalled();
		expect(spies.setFixApplied).toHaveBeenLastCalledWith(7, false);
	});

	it('previews a move with before and after outlines and highlights both blocks', () => {
		const { spies } = renderAction({ action: move });

		expect(screen.getByText('Move above “Intro”')).toBeInTheDocument();
		fireEvent.click(screen.getByRole('button', { name: 'Preview' }));

		const preview = screen.getByRole('region', {
			name: 'Preview of the change',
		});
		const [now, after] = within(preview).getAllByRole('list');
		expect(
			within(now)
				.getAllByRole('listitem')
				.map((item) => item.textContent)
		).toEqual([
			'Heading: Intro',
			'Paragraph: Background.',
			'Paragraph: Repeats. (moved)',
		]);
		expect(
			within(after)
				.getAllByRole('listitem')
				.map((item) => item.textContent)
		).toEqual([
			'Paragraph: Repeats. (moved)',
			'Heading: Intro',
			'Paragraph: Background.',
		]);
		expect(spies.toggleBlockHighlight).toHaveBeenCalledWith('rep', true);
		expect(spies.toggleBlockHighlight).toHaveBeenCalledWith('intro', true);

		fireEvent.click(screen.getByRole('button', { name: 'Hide preview' }));
		expect(spies.toggleBlockHighlight).toHaveBeenCalledWith('rep', false);

		fireEvent.click(screen.getByRole('button', { name: 'Apply change' }));
		expect(spies.moveBlocksToPosition).toHaveBeenCalledWith(
			['rep'],
			'',
			'',
			0
		);
	});

	it('shows a move whose target is gone as outdated', () => {
		renderAction({
			action: { ...move, target_block_id: 'gone', target_text: 'Gone' },
		});

		expect(
			screen.getByText(
				'This change is outdated: the block it moves next to is no longer in the post.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole('button', { name: 'Apply change' })
		).toHaveAttribute('aria-disabled', 'true');
	});

	it('shows an edit whose block is gone as outdated', () => {
		renderAction({ action: move }, { order: ['intro', 'bg'] });

		expect(
			screen.getByText(
				'This change is outdated: its block is no longer in the post.'
			)
		).toBeInTheDocument();
	});

	it('reads a removed block as gone, without offering to apply', () => {
		renderAction(
			{ action: { type: 'remove_block' } },
			{ order: ['intro', 'bg'] }
		);

		expect(
			screen.getByText('This block is no longer in the post.')
		).toBeInTheDocument();
		expect(
			screen.queryByRole('button', { name: 'Apply change' })
		).not.toBeInTheDocument();
	});

	it('reads a move Gutenberg recorded as a suggestion as pending', () => {
		renderAction(
			{ action: move },
			{
				order: ['rep', 'intro', 'bg'],
				// Gutenberg may mark the neighbor of a swapped pair instead.
				markers: { intro: { type: 'pending-move', authorId: 1 } },
			}
		);

		expect(
			screen.getByText(
				'Suggested in the post. Accept or reject it from the Notes sidebar.'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByText('The post already has this change.')
		).not.toBeInTheDocument();
	});

	it('reads a move as applied when the marker is the plugin’s own', () => {
		renderAction(
			{ action: move },
			{
				order: ['rep', 'intro', 'bg'],
				markers: {
					rep: { type: 'pending-move', commentId: 7, authorId: 1 },
				},
			}
		);

		expect(
			screen.getByText('The post already has this change.')
		).toBeInTheDocument();
	});

	it('reads a move already in place as applied', () => {
		renderAction({ action: move }, { order: ['rep', 'intro', 'bg'] });

		expect(
			screen.getByText('The post already has this change.')
		).toBeInTheDocument();
	});

	it('only reports a superseded edit', () => {
		renderAction({ action: { type: 'remove_block' }, isSuperseded: true });

		expect(
			screen.getByText('Replaced by a revised suggestion.')
		).toBeInTheDocument();
		expect(
			screen.queryByRole('button', { name: 'Apply change' })
		).not.toBeInTheDocument();
	});

	it('defers to Gutenberg once the edit is a suggestion', () => {
		renderAction(
			{ action: { type: 'remove_block' } },
			{
				notes: [
					{
						id: 7,
						meta: {
							_wp_suggestion: '{}',
							_wp_suggestion_status: 'pending',
						},
					},
				],
			}
		);

		expect(
			screen.getByText(
				'Suggested in the post. Accept or reject it from the Notes sidebar.'
			)
		).toBeInTheDocument();
	});

	it('leaves the thread open when applying a reply’s edit', () => {
		const { spies } = renderAction({
			action: {
				type: 'insert_block',
				position: 'after',
				block: { name: 'core/heading', content: 'How to try it' },
			},
			noteId: 9,
		});

		expect(
			screen.getByText('Add a heading below: “How to try it”')
		).toBeInTheDocument();
		fireEvent.click(screen.getByRole('button', { name: 'Apply change' }));

		expect(spies.insertBlocks).toHaveBeenCalledWith(
			[expect.objectContaining({ name: 'core/heading' })],
			3,
			'',
			false
		);
		expect(spies.setFixApplied).toHaveBeenCalledWith(9, true);
		expect(spies.setNoteResolved).not.toHaveBeenCalled();
	});
	it('goes by the blocks, not the applied flag, once the editor’s Undo took the change back', () => {
		renderAction({ action: move }, { applied: { 7: true } });

		expect(
			screen.getByRole('button', { name: 'Apply change' })
		).not.toHaveAttribute('aria-disabled');
	});

	it('refuses its Undo once the post has changed again', () => {
		const { spies, registry } = renderAction({
			action: { type: 'remove_block' },
		});
		fireEvent.click(screen.getByRole('button', { name: 'Apply change' }));
		act(() => {
			registry.dispatch('core/block-editor').removeBlocks(['intro']);
		});

		const [notice] = registry.select(noticesStore).getNotices();
		act(() => notice.actions[0].onClick());

		expect(spies.undo).not.toHaveBeenCalled();
		expect(spies.setFixApplied).not.toHaveBeenCalledWith(7, false);
	});

	it('explains why a removal that would take noted blocks with it is not offered', () => {
		renderAction(
			{ action: { type: 'remove_block' } },
			{
				order: ['intro', 'bg', 'rep', 'inner'],
				blocks: {
					inner: { name: 'core/paragraph', content: 'Noted.' },
				},
				innerOf: { rep: ['inner'] },
				noteIds: { inner: 99 },
			}
		);

		expect(
			screen.getByText('Remove this block and the 1 block inside it')
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'This block holds blocks with notes of their own, so it can’t be removed from here.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole('button', { name: 'Apply change' })
		).toHaveAttribute('aria-disabled', 'true');
	});
});
