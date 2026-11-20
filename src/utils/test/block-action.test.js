/**
 * External dependencies
 */
import { describe, expect, it, vi } from 'vitest';

/**
 * Internal dependencies
 */
import {
	applyBlockAction,
	buildInsertedBlock,
	describeBlockAction,
	excerpt,
	findMoveTarget,
	findNoteBlock,
	getBlockActionOutline,
	getBlockActionState,
	getMovePosition,
	isBlockAction,
	matchesTargetText,
} from '../block-action';

vi.mock('@wordpress/blocks', () => ({
	store: 'core/blocks',
	createBlock: (name, attributes = {}, innerBlocks = []) => {
		if (name === 'core/unknown') {
			throw new Error('Unknown block type');
		}
		return { name, attributes, innerBlocks };
	},
	serialize: (block) => {
		const inner = (block.innerBlocks || [])
			.map((child) => `<li>${child.attributes.content}</li>`)
			.join('');
		return `<p>${block.attributes.content ?? ''}</p>${inner}`;
	},
}));

/**
 * Build block editor selectors over a small block tree.
 *
 * @param {Object} order  Child client IDs keyed by parent ('' for the root).
 * @param {Object} blocks Blocks keyed by client ID, as `[name, content, metadata]`.
 * @return {Object} Selectors.
 */
function treeSelectors(order, blocks) {
	const parents = {};
	for (const [parent, ids] of Object.entries(order)) {
		ids.forEach((id) => (parents[id] = parent));
	}
	const all = (parent = '') =>
		(order[parent] || []).flatMap((id) => [id, ...all(id)]);
	return {
		getBlock: (id) =>
			blocks[id]
				? {
						clientId: id,
						name: blocks[id][0],
						attributes: {
							content: blocks[id][1],
							metadata: blocks[id][2],
						},
						innerBlocks: [],
					}
				: null,
		getClientIdsWithDescendants: () => all(),
		getBlockRootClientId: (id) =>
			id in parents ? parents[id] || null : null,
		getBlockIndex: (id) => (order[parents[id] ?? ''] || []).indexOf(id),
		getBlockOrder: (parent = '') => order[parent || ''] || [],
	};
}

const doc = () =>
	treeSelectors(
		{ '': ['h', 'bg', 'open', 'rep', 'group'], group: ['inner'] },
		{
			h: ['core/heading', 'Why this matters'],
			bg: ['core/paragraph', 'Background on the project.'],
			open: ['core/paragraph', 'The real opening.', { noteId: [12] }],
			rep: ['core/paragraph', 'This repeats section two.'],
			group: ['core/group', ''],
			inner: ['core/paragraph', 'Inside the group.'],
		}
	);

// Longer than the 80 characters of target text the server keeps.
const LONG = 'Same start '.repeat(8).trim();

const move = (target, position, extra = {}) => ({
	type: 'move_block',
	position,
	target_block_id: target,
	target_name: 'core/paragraph',
	target_text: '',
	...extra,
});

describe('isBlockAction', () => {
	it.each([
		[{ type: 'remove_block' }, true],
		[move('bg', 'before'), true],
		[move('bg', 'inside'), false],
		[
			{ type: 'move_block', position: 'before', target_block_id: 'x' },
			false,
		],
		[
			{
				type: 'insert_block',
				position: 'after',
				block: { name: 'core/paragraph', content: 'Hi' },
			},
			true,
		],
		[
			{
				type: 'insert_block',
				position: 'after',
				block: { name: 'core/list', items: ['a', 2] },
			},
			false,
		],
		[{ type: 'replace_text', find: 'a', replace: 'b' }, false],
		[null, false],
	])('checks %j', (action, expected) => {
		expect(isBlockAction(action)).toBe(expected);
	});
});

describe('describeBlockAction', () => {
	it('describes each action in one line', () => {
		expect(describeBlockAction({ type: 'remove_block' })).toBe(
			'Remove this block'
		);
		expect(
			describeBlockAction(
				move('', 'before', { target_text: 'Why this matters' })
			)
		).toBe('Move above “Why this matters”');
		expect(
			describeBlockAction(move('', 'after', { target_text: 'Next' }))
		).toBe('Move below “Next”');
		expect(
			describeBlockAction({
				type: 'insert_block',
				position: 'before',
				block: {
					name: 'core/heading',
					content: 'How <em>to</em> try it',
				},
			})
		).toBe('Add a heading above: “How to try it”');
		expect(
			describeBlockAction({
				type: 'insert_block',
				position: 'after',
				block: { name: 'core/list', items: ['One', 'Two'] },
			})
		).toBe('Add a list below: “One Two”');
	});

	it('shortens long text', () => {
		expect(excerpt('word '.repeat(20))).toHaveLength(40);
		expect(excerpt('word '.repeat(20)).endsWith('…')).toBe(true);
	});
});

describe('findNoteBlock', () => {
	it('uses the stored client ID, then the note ID in block metadata', () => {
		const selectors = doc();
		expect(findNoteBlock(selectors, 'rep', 12)).toBe('rep');
		expect(findNoteBlock(selectors, 'stale', 12)).toBe('open');
		expect(findNoteBlock(selectors, 'stale', 99)).toBeNull();
		expect(findNoteBlock(selectors, 'stale')).toBeNull();
	});
});

describe('findMoveTarget', () => {
	it('uses the stored client ID while it names a block of that type', () => {
		expect(findMoveTarget(doc(), move('bg', 'before'), 'open')).toBe('bg');
	});

	it('finds the target by type and text once the client ID is stale', () => {
		expect(
			findMoveTarget(
				doc(),
				move('stale', 'before', {
					target_text: 'Background on the project.',
				}),
				'open'
			)
		).toBe('bg');
		expect(
			findMoveTarget(
				doc(),
				move('', 'before', {
					target_name: 'core/heading',
					target_text: 'Why this matters',
				}),
				'open'
			)
		).toBe('h');
	});

	// The server keeps a target's line break as a space, from a review's
	// text ("\n") or a reply's (Reply_Service::html_text()); reviews from
	// before dropped it.
	it.each([
		['new review or reply', 'Background on the project.'],
		['older review', 'Backgroundon the project.'],
	])(
		'finds a target with a line break, stored by a %s',
		(_label, targetText) => {
			const selectors = treeSelectors(
				{ '': ['bg', 'open'] },
				{
					bg: ['core/paragraph', 'Background<br>on the project.'],
					open: ['core/paragraph', 'The real opening.'],
				}
			);
			expect(
				findMoveTarget(
					selectors,
					move('stale', 'before', { target_text: targetText }),
					'open'
				)
			).toBe('bg');
		}
	);

	it('ignores a client ID that now names another type of block', () => {
		expect(
			findMoveTarget(
				doc(),
				move('h', 'before', { target_text: 'Nothing like it' }),
				'open'
			)
		).toBeNull();
	});

	it('prefers a sibling when several blocks match', () => {
		const selectors = treeSelectors(
			{ '': ['a', 'me', 'group'], group: ['b'] },
			{
				a: ['core/paragraph', `${LONG} here.`],
				me: ['core/paragraph', 'Me.'],
				group: ['core/group', ''],
				b: ['core/paragraph', `${LONG} there.`],
			}
		);
		expect(
			findMoveTarget(
				selectors,
				move('', 'before', { target_text: LONG.slice(0, 80) }),
				'me'
			)
		).toBe('a');
	});

	it('gives up when the target is gone or ambiguous', () => {
		const selectors = treeSelectors(
			{ '': ['a', 'b', 'me'] },
			{
				a: ['core/paragraph', `${LONG} here.`],
				b: ['core/paragraph', `${LONG} there.`],
				me: ['core/paragraph', 'Me.'],
			}
		);
		expect(
			findMoveTarget(
				selectors,
				move('', 'before', { target_text: LONG.slice(0, 80) }),
				'me'
			)
		).toBeNull();
		expect(
			findMoveTarget(
				selectors,
				move('', 'before', { target_text: 'Gone' }),
				'me'
			)
		).toBeNull();
		expect(findMoveTarget(selectors, move('', 'before'), 'me')).toBeNull();
	});
});

describe('matchesTargetText', () => {
	it('needs the whole text when it was short, and the start otherwise', () => {
		expect(matchesTargetText('Step 1 of setup.', 'Step 1')).toBe(false);
		expect(matchesTargetText(' Step  1 ', 'Step 1')).toBe(true);
		// 79 characters, 80 UTF-16 units: still the whole text.
		const emoji = `${'a'.repeat(78)}🙂`;
		expect(matchesTargetText(`${emoji} and more`, emoji)).toBe(false);
		expect(matchesTargetText(emoji, emoji)).toBe(true);
		expect(matchesTargetText(`${LONG} here.`, LONG.slice(0, 80))).toBe(
			true
		);
	});
});

describe('getMovePosition', () => {
	it('counts the index once the block has left a shared parent', () => {
		// Moving `open` (2) after `rep` (3): `rep` sits at 2 once `open` leaves.
		expect(
			getMovePosition(doc(), move('rep', 'after'), 'open', 'rep')
		).toEqual({ fromRootClientId: '', toRootClientId: '', index: 3 });
		// Moving `open` above `h` (0).
		expect(
			getMovePosition(doc(), move('h', 'before'), 'open', 'h')
		).toEqual({ fromRootClientId: '', toRootClientId: '', index: 0 });
	});

	it('moves into another parent', () => {
		expect(
			getMovePosition(doc(), move('inner', 'after'), 'open', 'inner')
		).toEqual({ fromRootClientId: '', toRootClientId: 'group', index: 1 });
	});
});

describe('getBlockActionState', () => {
	it('is missing without the note block', () => {
		expect(
			getBlockActionState(doc(), { type: 'remove_block' }, null).state
		).toBe('missing');
		expect(
			getBlockActionState(doc(), { type: 'remove_block' }, 'gone').state
		).toBe('missing');
	});

	it('is ready, applied or outdated for a move', () => {
		expect(
			getBlockActionState(
				doc(),
				move('h', 'before', { target_name: 'core/heading' }),
				'open'
			)
		).toEqual({ state: 'ready', targetId: 'h' });
		expect(
			getBlockActionState(doc(), move('rep', 'before'), 'open')
		).toEqual({ state: 'applied', targetId: 'rep' });
		expect(getBlockActionState(doc(), move('bg', 'after'), 'open')).toEqual(
			{ state: 'applied', targetId: 'bg' }
		);
		expect(
			getBlockActionState(
				doc(),
				move('gone', 'before', { target_text: 'Gone' }),
				'open'
			)
		).toEqual({ state: 'outdated', targetId: null });
	});

	it('is applied once an inserted block is in place', () => {
		const insert = (content, position = 'before') => ({
			type: 'insert_block',
			position,
			block: { name: 'core/paragraph', content },
		});
		expect(
			getBlockActionState(
				doc(),
				insert('Background on the project.'),
				'open'
			).state
		).toBe('applied');
		expect(
			getBlockActionState(doc(), insert('Something new.'), 'open').state
		).toBe('ready');
		// A heading's level is part of the edit.
		expect(
			getBlockActionState(
				doc(),
				{
					type: 'insert_block',
					position: 'before',
					block: {
						name: 'core/heading',
						content: 'Why this matters',
						level: 3,
					},
				},
				'bg'
			).state
		).toBe('ready');
		expect(
			getBlockActionState(
				doc(),
				{
					type: 'insert_block',
					position: 'before',
					block: {
						name: 'core/heading',
						content: 'Why this matters',
					},
				},
				'bg'
			).state
		).toBe('applied');
		expect(
			getBlockActionState(
				doc(),
				insert('Background on the project.', 'after'),
				'open'
			).state
		).toBe('ready');
	});
});

describe('buildInsertedBlock', () => {
	it('builds paragraphs, headings and lists', () => {
		expect(
			buildInsertedBlock({ name: 'core/paragraph', content: 'Hi' })
		).toEqual({
			name: 'core/paragraph',
			attributes: { content: 'Hi' },
			innerBlocks: [],
		});
		expect(
			buildInsertedBlock({ name: 'core/heading', content: 'Hi' })
				.attributes
		).toEqual({ content: 'Hi', level: 2 });
		expect(
			buildInsertedBlock({
				name: 'core/list',
				items: ['a', 'b'],
				ordered: true,
			})
		).toEqual({
			name: 'core/list',
			attributes: { ordered: true },
			innerBlocks: [
				{
					name: 'core/list-item',
					attributes: { content: 'a' },
					innerBlocks: [],
				},
				{
					name: 'core/list-item',
					attributes: { content: 'b' },
					innerBlocks: [],
				},
			],
		});
	});

	it('returns null for a block type that is not registered', () => {
		expect(
			buildInsertedBlock({ name: 'core/unknown', content: 'Hi' })
		).toBeNull();
	});
});

describe('applyBlockAction', () => {
	const editorActions = () => ({
		removeBlocks: vi.fn(),
		moveBlocksToPosition: vi.fn(),
		insertBlocks: vi.fn(),
		__unstableMarkLastChangeAsPersistent: vi.fn(),
	});

	it('removes the block in its own undo level', () => {
		const order = { '': ['bg', 'rep'] };
		const blocks = {
			bg: ['core/paragraph', 'Background.'],
			rep: ['core/paragraph', 'Repeats.'],
		};
		const actions = editorActions();
		actions.removeBlocks.mockImplementation(([id]) => {
			order[''] = order[''].filter((item) => item !== id);
			delete blocks[id];
		});
		expect(
			applyBlockAction(
				treeSelectors(order, blocks),
				actions,
				{ type: 'remove_block' },
				'rep'
			)
		).toBe(true);
		expect(actions.removeBlocks).toHaveBeenCalledWith(['rep'], false);
		expect(
			actions.__unstableMarkLastChangeAsPersistent
		).toHaveBeenCalledTimes(2);
		const [before] =
			actions.__unstableMarkLastChangeAsPersistent.mock
				.invocationCallOrder;
		expect(before).toBeLessThan(
			actions.removeBlocks.mock.invocationCallOrder[0]
		);
	});

	it('reports a change the editor refused, eg. in a locked template', () => {
		const actions = editorActions();
		expect(
			applyBlockAction(doc(), actions, { type: 'remove_block' }, 'rep')
		).toBe(false);
		expect(actions.removeBlocks).toHaveBeenCalled();
		expect(
			actions.__unstableMarkLastChangeAsPersistent
		).toHaveBeenCalledTimes(1);
	});

	it('moves the block next to its target', () => {
		const actions = editorActions();
		applyBlockAction(
			doc(),
			actions,
			move('h', 'before', { target_name: 'core/heading' }),
			'open',
			'h'
		);
		expect(actions.moveBlocksToPosition).toHaveBeenCalledWith(
			['open'],
			'',
			'',
			0
		);
	});

	it('inserts the new block next to the note block', () => {
		const actions = editorActions();
		applyBlockAction(
			doc(),
			actions,
			{
				type: 'insert_block',
				position: 'after',
				block: { name: 'core/heading', content: 'How to try it' },
			},
			'rep'
		);
		expect(actions.insertBlocks).toHaveBeenCalledWith(
			[
				{
					name: 'core/heading',
					attributes: { content: 'How to try it', level: 2 },
					innerBlocks: [],
				},
			],
			4,
			'',
			false
		);
	});

	it('does nothing for a move without a target or a block it cannot build', () => {
		const actions = editorActions();
		expect(
			applyBlockAction(
				doc(),
				actions,
				move('gone', 'before', { target_text: 'Gone' }),
				'open'
			)
		).toBe(false);
		expect(
			applyBlockAction(
				doc(),
				actions,
				{
					type: 'insert_block',
					position: 'after',
					block: { name: 'core/unknown', content: 'x' },
				},
				'rep'
			)
		).toBe(false);
		expect(
			actions.__unstableMarkLastChangeAsPersistent
		).not.toHaveBeenCalled();
	});
});

describe('getBlockActionOutline', () => {
	const title = (name) => ({ 'core/paragraph': 'Paragraph' })[name];

	it('marks a removed block and leaves it out after', () => {
		const { before, after } = getBlockActionOutline(
			doc(),
			title,
			{ type: 'remove_block' },
			'rep'
		);
		expect(before.map((line) => line.mark)).toEqual([
			undefined,
			undefined,
			'removed',
			undefined,
		]);
		expect(before[0].label).toBe('…');
		expect(before[3].label).toBe('core/group');
		expect(after.map((line) => line.label)).toEqual([
			'…',
			'Paragraph: The real opening.',
			'core/group',
		]);
	});

	it('shows a moved block in its new place', () => {
		const { after } = getBlockActionOutline(
			doc(),
			title,
			move('h', 'before'),
			'open',
			'h'
		);
		expect(after.slice(0, 2)).toEqual([
			expect.objectContaining({
				label: 'Paragraph: The real opening.',
				mark: 'moved',
			}),
			expect.objectContaining({
				label: 'core/heading: Why this matters',
			}),
		]);
	});

	it('shows an inserted block as added', () => {
		const { after } = getBlockActionOutline(
			doc(),
			title,
			{
				type: 'insert_block',
				position: 'before',
				block: { name: 'core/paragraph', content: 'New text.' },
			},
			'h'
		);
		expect(after[0]).toMatchObject({
			label: 'Paragraph: New text.',
			mark: 'added',
		});
	});
});

describe('removing a block that holds other blocks', () => {
	it('counts the blocks that go with it', () => {
		const selectors = doc();
		expect(
			getBlockActionState(selectors, { type: 'remove_block' }, 'group')
		).toEqual({ state: 'ready', targetId: null, inside: 1 });
		expect(describeBlockAction({ type: 'remove_block' }, 1)).toBe(
			'Remove this block and the 1 block inside it'
		);
		expect(describeBlockAction({ type: 'remove_block' }, 6)).toBe(
			'Remove this block and the 6 blocks inside it'
		);
		const { before } = getBlockActionOutline(
			selectors,
			() => undefined,
			{ type: 'remove_block' },
			'group'
		);
		expect(before.find((line) => line.mark === 'removed').label).toBe(
			'core/group, with 1 block inside'
		);
	});

	it('is blocked when a block inside is linked to another note', () => {
		const selectors = treeSelectors(
			{ '': ['group'], group: ['inner'] },
			{
				group: ['core/group', '', { noteId: 5 }],
				inner: ['core/paragraph', 'Noted.', { noteId: [99] }],
			}
		);
		expect(
			getBlockActionState(selectors, { type: 'remove_block' }, 'group')
				.state
		).toBe('blocked');
		const actions = {
			removeBlocks: vi.fn(),
			__unstableMarkLastChangeAsPersistent: vi.fn(),
		};
		expect(
			applyBlockAction(
				selectors,
				actions,
				{ type: 'remove_block' },
				'group'
			)
		).toBe(false);
		expect(actions.removeBlocks).not.toHaveBeenCalled();
	});
});
