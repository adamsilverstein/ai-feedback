/**
 * Formatting fixes against the real block editor store and core blocks.
 *
 * External dependencies
 */
import { beforeAll, describe, expect, it, vi } from 'vitest';

/**
 * WordPress dependencies
 */
import { createRegistry } from '@wordpress/data';
import {
	createBlock,
	getBlockType,
	getBlockTypes,
	registerBlockType,
	store as blocksStore,
	unregisterBlockType,
} from '@wordpress/blocks';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { registerCoreBlocks } from '@wordpress/block-library';

/**
 * Internal dependencies
 */
import {
	applyBlockChange,
	describeBlockChange,
	findSplit,
	getBlockChangePreview,
	getBlockChangeState,
	isBlockChange,
	matchListItems,
	normalizeWithMap,
} from '../block-change';
import { findNoteBlock } from '../block-action';

beforeAll(() => {
	if (!getBlockTypes().some((type) => type.name === 'core/list')) {
		registerCoreBlocks();
	}
	// The editor adds `metadata`, which holds a note's link, `anchor`,
	// `className` and the color and typography attributes to blocks through
	// hooks. Packages here each bundle their own hooks, so they are added by
	// hand.
	for (const name of ['core/paragraph', 'core/heading', 'core/list']) {
		const settings = getBlockType(name);
		if (!settings.attributes.metadata) {
			unregisterBlockType(name);
			registerBlockType(name, {
				...settings,
				attributes: {
					...settings.attributes,
					metadata: { type: 'object' },
					anchor: { type: 'string' },
					className: { type: 'string' },
					style: { type: 'object' },
					fontSize: { type: 'string' },
					textColor: { type: 'string' },
					backgroundColor: { type: 'string' },
					gradient: { type: 'string' },
				},
			});
		}
	}
});

/**
 * A registry holding the given blocks.
 *
 * @param {Object[]} blocks   Blocks.
 * @param {Object}   settings Block editor settings.
 * @return {{registry: Object, selectors: Object, actions: Object, calls: string[]}} Registry, its block editor selectors, counted actions and the actions called.
 */
function setUp(blocks, settings = {}) {
	const registry = createRegistry();
	registry.register(blocksStore);
	registry.register(blockEditorStore);
	registry.dispatch(blockEditorStore).updateSettings(settings);
	registry.dispatch(blockEditorStore).resetBlocks(blocks);
	const real = registry.dispatch(blockEditorStore);
	const calls = [];
	// Record each change made, to check a fix is one dispatch.
	const actions = new Proxy(real, {
		get(target, name) {
			const action = target[name];
			if (typeof action !== 'function' || name.startsWith('__')) {
				return action;
			}
			return (...args) => {
				calls.push(name);
				return action(...args);
			};
		},
	});
	return {
		registry,
		selectors: registry.select(blockEditorStore),
		actions,
		calls,
	};
}

const NOTE = { noteId: 42 };

describe('text matching', () => {
	it('maps normalized text back to the raw text', () => {
		const { text, map } = normalizeWithMap('  One,\n  two ');
		expect(text).toBe('One, two');
		expect(map[0]).toBe(2);
		expect(map[4]).toBe(6);
		expect(map[5]).toBe(9);
		expect(map[text.length]).toBe(12);
	});

	it('matches list items as the server does', () => {
		expect(
			matchListItems('You need Node, npm, Docker and Git.', [
				'Node',
				'npm',
				'Docker',
				'Git',
			])
		).toEqual({
			lead: [0, 8],
			items: [
				[9, 13],
				[15, 18],
				[20, 26],
				[31, 34],
			],
		});
		expect(matchListItems('Node, then npm', ['Node', 'npm'])).toBeNull();
		expect(
			matchListItems('Node and npm are needed.', ['Node', 'npm'])
		).toBeNull();
		expect(matchListItems('Nodes, npm', ['odes', 'npm'])).toBeNull();
		// An introduction ending in a separator left items out of the list.
		expect(
			matchListItems('You need Node, npm, Docker and Git.', [
				'Docker',
				'Git',
			])
		).toBeNull();
		expect(matchListItems('需要：书，笔，纸', ['笔', '纸'])).toBeNull();
	});

	it('finds a split at a sentence that appears once', () => {
		expect(findSplit('One. Two.', 'One.')).toBe(4);
		expect(findSplit('One. Two.', 'Two.')).toBe(-1);
		expect(findSplit('Yes. Yes. No.', 'Yes.')).toBe(-1);
	});

	it('recognizes well-formed changes only', () => {
		expect(
			isBlockChange({
				type: 'set_attributes',
				attributes: { level: 3 },
				from: { level: 4 },
			})
		).toBe(true);
		expect(
			isBlockChange({ type: 'set_attributes', attributes: { level: 3 } })
		).toBe(false);
		expect(isBlockChange({ type: 'transform', to: 'core/list' })).toBe(
			false
		);
		expect(isBlockChange({ type: 'set_style' })).toBe(false);
		expect(isBlockChange(null)).toBe(false);
	});
});

describe('heading level', () => {
	const change = {
		type: 'set_attributes',
		attributes: { level: 3 },
		from: { level: 4 },
	};

	it('fixes a skipped level as one change', () => {
		const heading = createBlock('core/heading', {
			level: 4,
			content: 'Setup',
		});
		const { selectors, actions, calls } = setUp([heading]);

		expect(
			getBlockChangeState(selectors, change, heading.clientId)
		).toEqual({ state: 'ready' });
		expect(getBlockChangePreview(heading, change)).toEqual({
			before: ['H4 Setup'],
			after: ['H3 Setup'],
		});
		expect(
			applyBlockChange(selectors, actions, change, heading.clientId)
		).toBe(heading.clientId);
		expect(calls).toEqual(['updateBlockAttributes']);
		expect(selectors.getBlock(heading.clientId).attributes.level).toBe(3);
		expect(
			getBlockChangeState(selectors, change, heading.clientId).state
		).toBe('applied');
		expect(describeBlockChange(change)).toBe('Change to a level 3 heading');
	});

	it('reads as outdated once the author changed the level', () => {
		const heading = createBlock('core/heading', {
			level: 5,
			content: 'Setup',
		});
		const { selectors, actions, calls } = setUp([heading]);

		expect(
			getBlockChangeState(selectors, change, heading.clientId).state
		).toBe('outdated');
		expect(
			applyBlockChange(selectors, actions, change, heading.clientId)
		).toBeNull();
		expect(calls).toEqual([]);
		expect(selectors.getBlock(heading.clientId).attributes.level).toBe(5);
	});
});

describe('image alt text and caption', () => {
	it('adds alt text and an escaped caption', () => {
		const image = createBlock('core/image', {
			url: 'http://example.com/chart.png',
			alt: '',
		});
		const { selectors, actions } = setUp([image]);
		const change = {
			type: 'set_attributes',
			attributes: { alt: 'Bar chart', caption: 'Before & <after>' },
			from: { alt: '', caption: '' },
			image: 'http://example.com/chart.png',
		};

		expect(
			applyBlockChange(selectors, actions, change, image.clientId)
		).toBe(image.clientId);
		const { attributes } = selectors.getBlock(image.clientId);
		expect(attributes.alt).toBe('Bar chart');
		expect(String(attributes.caption)).toBe('Before &amp; &lt;after>');
		expect(
			getBlockChangeState(selectors, change, image.clientId).state
		).toBe('applied');
	});

	it('never replaces alt text the author wrote', () => {
		const image = createBlock('core/image', {
			url: 'http://example.com/chart.png',
			alt: 'My own words',
		});
		const { selectors } = setUp([image]);

		expect(
			getBlockChangeState(
				selectors,
				{
					type: 'set_attributes',
					attributes: { alt: 'Bar chart' },
					from: { alt: '' },
					image: 'http://example.com/chart.png',
				},
				image.clientId
			).state
		).toBe('outdated');
	});
});

describe('paragraph to list', () => {
	const change = {
		type: 'transform',
		to: 'core/list',
		items: ['Node', 'npm', 'Docker', 'Git'],
		ordered: false,
	};

	it('splits the items, keeping links, formatting and the note link', () => {
		const paragraph = createBlock('core/paragraph', {
			content:
				'You need <a href="https://nodejs.org">Node</a>, <strong>npm</strong>, Docker and Git.',
			metadata: NOTE,
		});
		const after = createBlock('core/paragraph', { content: 'After.' });
		const { selectors, actions, calls } = setUp([paragraph, after]);

		expect(
			getBlockChangeState(selectors, change, paragraph.clientId).state
		).toBe('ready');
		expect(getBlockChangePreview(paragraph, change).after).toEqual([
			'Paragraph: You need',
			'• Node',
			'• npm',
			'• Docker',
			'• Git',
		]);

		const listId = applyBlockChange(
			selectors,
			actions,
			change,
			paragraph.clientId
		);

		expect(calls).toEqual(['replaceBlocks']);
		const [lead, list, rest] = selectors.getBlocks();
		expect(lead.name).toBe('core/paragraph');
		expect(String(lead.attributes.content)).toBe('You need');
		expect(lead.attributes.metadata).toBeUndefined();
		expect(list.clientId).toBe(listId);
		expect(list.name).toBe('core/list');
		expect(list.attributes.metadata).toEqual(NOTE);
		expect(
			list.innerBlocks.map((item) => String(item.attributes.content))
		).toEqual([
			'<a href="https://nodejs.org">Node</a>',
			'<strong>npm</strong>',
			'Docker',
			'Git',
		]);
		expect(rest.clientId).toBe(after.clientId);

		// After a reload the note finds the list by its link.
		const noteBlock = findNoteBlock(selectors, paragraph.clientId, 42);
		expect(noteBlock).toBe(listId);
		expect(getBlockChangeState(selectors, change, noteBlock).state).toBe(
			'applied'
		);
	});

	it('is not offered when the text changed', () => {
		const paragraph = createBlock('core/paragraph', {
			content: 'You need Node, npm and Docker.',
		});
		const { selectors, actions, calls } = setUp([paragraph]);

		expect(
			getBlockChangeState(selectors, change, paragraph.clientId).state
		).toBe('outdated');
		expect(
			applyBlockChange(selectors, actions, change, paragraph.clientId)
		).toBeNull();
		expect(calls).toEqual([]);
	});

	it('is not offered when a dropped separator holds an inline image', () => {
		const paragraph = createBlock('core/paragraph', {
			content:
				'Node,<img class="wp-image-1" src="http://example.com/i.png" alt=""> npm',
		});
		const { selectors } = setUp([paragraph]);

		expect(
			getBlockChangeState(
				selectors,
				{ ...change, items: ['Node', 'npm'] },
				paragraph.clientId
			).state
		).toBe('outdated');
	});

	it('is not applied, nor marked applied, when the editor refuses it', () => {
		const paragraph = createBlock('core/paragraph', {
			content: 'Node, npm, Docker and Git',
		});
		const { selectors, actions } = setUp([paragraph], {
			templateLock: 'all',
		});

		expect(
			applyBlockChange(selectors, actions, change, paragraph.clientId)
		).toBeNull();
		expect(selectors.getBlocks()).toHaveLength(1);
		expect(selectors.getBlocks()[0].name).toBe('core/paragraph');
	});
});

describe('split', () => {
	const change = {
		type: 'split',
		after: 'idea ends here.',
		next: 'The second idea goes on.',
		next_length: 24,
	};

	it('splits after the sentence, keeping formatting on both halves', () => {
		const paragraph = createBlock('core/paragraph', {
			content:
				'The first <em>idea ends here.</em> The <a href="https://example.com">second</a> idea goes on.',
			metadata: NOTE,
			dropCap: true,
		});
		const { selectors, actions, calls } = setUp([paragraph]);

		expect(getBlockChangePreview(paragraph, change).after).toEqual([
			'Paragraph: The first idea ends here.',
			'Paragraph: The second idea goes on.',
		]);
		const firstId = applyBlockChange(
			selectors,
			actions,
			change,
			paragraph.clientId
		);

		expect(calls).toEqual(['replaceBlocks']);
		const [first, second] = selectors.getBlocks();
		expect(first.clientId).toBe(firstId);
		expect(String(first.attributes.content)).toBe(
			'The first <em>idea ends here.</em>'
		);
		expect(String(second.attributes.content)).toBe(
			'The <a href="https://example.com">second</a> idea goes on.'
		);
		expect(first.attributes.metadata).toEqual(NOTE);
		expect(second.attributes.metadata).toBeUndefined();
		// A drop cap belongs to the first half only.
		expect(second.attributes.dropCap).toBe(false);
		expect(getBlockChangeState(selectors, change, firstId).state).toBe(
			'applied'
		);
	});
});

describe('heading and paragraph transforms', () => {
	it('turns a paragraph into a heading at the proposed level', () => {
		const paragraph = createBlock('core/paragraph', {
			content: 'Getting <em>started</em>',
			metadata: NOTE,
		});
		const { selectors, actions } = setUp([paragraph]);
		const change = {
			type: 'transform',
			to: 'core/heading',
			level: 3,
			text: 'Getting started',
		};

		const id = applyBlockChange(
			selectors,
			actions,
			change,
			paragraph.clientId
		);

		const heading = selectors.getBlock(id);
		expect(heading.name).toBe('core/heading');
		expect(heading.attributes.level).toBe(3);
		expect(String(heading.attributes.content)).toBe(
			'Getting <em>started</em>'
		);
		expect(heading.attributes.metadata).toEqual(NOTE);
	});

	it('turns a heading into a paragraph', () => {
		const heading = createBlock('core/heading', {
			content: 'This is really body text.',
			level: 2,
		});
		const { selectors, actions } = setUp([heading]);
		const change = {
			type: 'transform',
			to: 'core/paragraph',
			text: 'This is really body text.',
		};

		const id = applyBlockChange(
			selectors,
			actions,
			change,
			heading.clientId
		);

		expect(selectors.getBlock(id).name).toBe('core/paragraph');
		expect(getBlockChangeState(selectors, change, id).state).toBe(
			'applied'
		);
	});

	it('is not offered when the editor does not offer the transform', () => {
		const paragraph = createBlock('core/paragraph', { content: 'Title' });
		const { selectors } = setUp([paragraph], {
			allowedBlockTypes: ['core/paragraph'],
		});
		vi.spyOn(console, 'warn').mockImplementation(() => {});

		expect(
			getBlockChangeState(
				selectors,
				{
					type: 'transform',
					to: 'core/heading',
					level: 2,
					text: 'Title',
				},
				paragraph.clientId
			).state
		).toBe('outdated');
	});
});

describe('stale changes', () => {
	it('never puts alt text written for one image on another', () => {
		const image = createBlock('core/image', {
			url: 'http://example.com/other.png',
			alt: '',
		});
		const { selectors, actions } = setUp([image]);
		const change = {
			type: 'set_attributes',
			attributes: { alt: 'Bar chart' },
			from: { alt: '' },
			image: 'http://example.com/chart.png',
		};

		expect(
			getBlockChangeState(selectors, change, image.clientId).state
		).toBe('outdated');
		expect(
			applyBlockChange(selectors, actions, change, image.clientId)
		).toBeNull();
		expect(selectors.getBlock(image.clientId).attributes.alt).toBe('');
	});

	it('does not read a list of the other type as applied', () => {
		const list = createBlock('core/list', { ordered: false }, [
			createBlock('core/list-item', { content: 'Node' }),
			createBlock('core/list-item', { content: 'npm' }),
		]);
		const { selectors } = setUp([list]);
		const change = {
			type: 'transform',
			to: 'core/list',
			items: ['Node', 'npm'],
			ordered: true,
		};

		expect(
			getBlockChangeState(selectors, change, list.clientId).state
		).toBe('outdated');
		expect(
			getBlockChangeState(
				selectors,
				{ ...change, ordered: false },
				list.clientId
			).state
		).toBe('applied');
	});

	it('does not turn an edited paragraph into a heading', () => {
		const paragraph = createBlock('core/paragraph', {
			content:
				'Setup, and a lot more text the author added after the review.',
		});
		const { selectors } = setUp([paragraph]);

		expect(
			getBlockChangeState(
				selectors,
				{
					type: 'transform',
					to: 'core/heading',
					level: 2,
					text: 'Setup',
				},
				paragraph.clientId
			).state
		).toBe('outdated');
	});

	it('does not count an unrelated next paragraph as a split', () => {
		const first = createBlock('core/paragraph', {
			content: 'First sentence.',
		});
		const references = createBlock('core/paragraph', {
			content: 'References.',
		});
		const { selectors } = setUp([first, references]);
		const change = {
			type: 'split',
			after: 'First sentence.',
			next: 'Second sentence.',
			next_length: 16,
		};

		expect(
			getBlockChangeState(selectors, change, first.clientId).state
		).toBe('outdated');
	});

	it('does not count a next paragraph that only starts like the split', () => {
		const first = createBlock('core/paragraph', {
			content: 'First sentence.',
		});
		const longer = createBlock('core/paragraph', {
			content: 'Second sentence. References.',
		});
		const { selectors } = setUp([first, longer]);
		const change = {
			type: 'split',
			after: 'First sentence.',
			next: 'Second sentence.',
			next_length: 16,
		};

		expect(
			getBlockChangeState(selectors, change, first.clientId).state
		).toBe('outdated');
	});
});

describe('anchors', () => {
	const change = {
		type: 'transform',
		to: 'core/list',
		items: ['Node', 'npm'],
		ordered: false,
	};

	it('keeps the anchor on the introduction', () => {
		const paragraph = createBlock('core/paragraph', {
			content: 'You need Node and npm.',
			anchor: 'requirements',
		});
		const { selectors, actions } = setUp([paragraph]);

		applyBlockChange(selectors, actions, change, paragraph.clientId);

		const [lead, list] = selectors.getBlocks();
		expect(lead.attributes.anchor).toBe('requirements');
		expect(list.attributes.anchor).toBeUndefined();
	});

	it('keeps the anchor on the list when there is no introduction', () => {
		const paragraph = createBlock('core/paragraph', {
			content: 'Node and npm',
			anchor: 'requirements',
		});
		const { selectors, actions } = setUp([paragraph]);

		applyBlockChange(selectors, actions, change, paragraph.clientId);

		const [list] = selectors.getBlocks();
		expect(list.name).toBe('core/list');
		expect(list.attributes.anchor).toBe('requirements');
	});
});

describe('block styling', () => {
	const styled = {
		className: 'is-callout',
		fontSize: 'large',
		textColor: 'accent',
		backgroundColor: 'base',
		style: {
			color: { text: '#123456' },
			typography: { fontSize: '20px', lineHeight: '1.8' },
			spacing: { margin: { top: '2rem' } },
		},
	};

	it('carries the styling core carries over to the list, and keeps it on the introduction', () => {
		const paragraph = createBlock('core/paragraph', {
			content: 'You need Node and npm.',
			...styled,
		});
		const { selectors, actions } = setUp([paragraph]);

		applyBlockChange(
			selectors,
			actions,
			{
				type: 'transform',
				to: 'core/list',
				items: ['Node', 'npm'],
				ordered: false,
			},
			paragraph.clientId
		);

		const [lead, list] = selectors.getBlocks();
		expect(lead.attributes).toMatchObject(styled);
		// As core's transforms: class name, font size and colors, but not
		// other styles such as spacing or line height.
		expect(list.attributes).toMatchObject({
			className: 'is-callout',
			fontSize: 'large',
			textColor: 'accent',
			backgroundColor: 'base',
			style: {
				color: { text: '#123456' },
				typography: { fontSize: '20px' },
			},
		});
		expect(list.attributes.style.spacing).toBeUndefined();
		expect(list.attributes.style.typography.lineHeight).toBeUndefined();
	});

	it('keeps a drop cap on the first half of a split only', () => {
		const paragraph = createBlock('core/paragraph', {
			content: 'One idea. Another idea.',
			dropCap: true,
			className: 'is-callout',
		});
		const { selectors, actions } = setUp([paragraph]);

		applyBlockChange(
			selectors,
			actions,
			{
				type: 'split',
				after: 'One idea.',
				next: 'Another idea.',
				next_length: 13,
			},
			paragraph.clientId
		);

		const [first, second] = selectors.getBlocks();
		expect(first.attributes.dropCap).toBe(true);
		expect(second.attributes.dropCap).toBe(false);
		expect(second.attributes.className).toBe('is-callout');
	});
});
