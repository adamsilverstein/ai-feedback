/**
 * Finding a move's target by its own text, against the real block editor
 * store and core blocks.
 *
 * External dependencies
 */
import { beforeAll, describe, expect, it } from 'vitest';

/**
 * WordPress dependencies
 */
import { createRegistry } from '@wordpress/data';
import { getBlockTypes, parse, store as blocksStore } from '@wordpress/blocks';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { registerCoreBlocks } from '@wordpress/block-library';

/**
 * Internal dependencies
 */
import { findMoveTarget, getOwnBlockTexts } from '../block-action';

const EDITOR_TEXT = require('../../../tests/fixtures/editor-text.json');

beforeAll(() => {
	if (!getBlockTypes().some((type) => type.name === 'core/quote')) {
		registerCoreBlocks();
	}
});

const NOTE = '<!-- wp:paragraph --><p>Move me.</p><!-- /wp:paragraph -->';

/**
 * A quote with a paragraph inside and, optionally, a citation.
 *
 * @param {string} inner Paragraph text.
 * @param {string} cite  Citation, or '' for none.
 * @return {string} Serialized quote.
 */
const quote = (inner, cite = '') =>
	`<!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>${inner}</p><!-- /wp:paragraph -->${
		cite ? `<cite>${cite}</cite>` : ''
	}</blockquote><!-- /wp:quote -->`;

const NESTED_LIST =
	'<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>Parent item<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>Nested item</li><!-- /wp:list-item --></ul><!-- /wp:list --></li><!-- /wp:list-item --></ul><!-- /wp:list -->';

const DETAILS =
	'<!-- wp:details --><details class="wp-block-details"><summary>More info</summary><!-- wp:paragraph --><p>Hidden words.</p><!-- /wp:paragraph --></details><!-- /wp:details -->';

const GROUP =
	'<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Grouped words.</p><!-- /wp:paragraph --></div><!-- /wp:group -->';

/**
 * Block editor selectors over a post loaded from markup, as after a
 * reload, and the note's block, which comes first.
 *
 * @param {...string} markup Serialized blocks after the note's block.
 * @return {{selectors: Object, note: string, find: (name: string, text: string) => string[]}} Selectors, the note block's client ID, and a lookup of a block's client ID by name and text.
 */
function load(...markup) {
	const registry = createRegistry();
	registry.register(blocksStore);
	registry.register(blockEditorStore);
	registry
		.dispatch(blockEditorStore)
		.resetBlocks(parse(NOTE + markup.join('')));
	const selectors = registry.select(blockEditorStore);
	const ids = selectors.getClientIdsWithDescendants();
	const find = (name, text) =>
		ids.filter((id) => {
			const block = selectors.getBlock(id);
			return block.name === name && getOwnBlockTexts(block)[0] === text;
		});
	return { selectors, note: ids[0], find };
}

/**
 * A `move_block` edit as the server keeps it after a reload: no client
 * ID that still names a block, so the target is found by its text.
 *
 * @param {string} name Target block name.
 * @param {string} text Kept target text.
 * @return {Object} Edit.
 */
const move = (name, text) => ({
	type: 'move_block',
	position: 'after',
	target_block_id: 'stale',
	target_name: name,
	target_text: text,
});

// Shared with ReplyServiceTest::test_target_text_matches_the_editor().
describe('getOwnBlockTexts', () => {
	it.each(EDITOR_TEXT.targets.map((item) => [item.name, item]))(
		'reads the own text of a %s as the server keeps it',
		(_name, item) => {
			const [block] = parse(item.markup);
			const texts = getOwnBlockTexts(block);
			expect(texts).toContain(item.text);
			// No reading takes in the text of the blocks inside.
			expect(texts.join(' ')).not.toMatch(/Inner|Nested|Hidden|Grouped/);
		}
	);
});

describe('findMoveTarget by a container block’s own text', () => {
	it.each([
		[
			'a quote by its citation',
			[quote('Inner words.', 'Ada Lovelace')],
			'core/quote',
			'Ada Lovelace',
		],
		[
			'a list item with a nested list',
			[NESTED_LIST],
			'core/list-item',
			'Parent item',
		],
		['a nested list item', [NESTED_LIST], 'core/list-item', 'Nested item'],
		[
			'a details block by its summary',
			[DETAILS],
			'core/details',
			'More info',
		],
	])('finds %s', (_label, markup, name, text) => {
		const { selectors, note, find } = load(...markup);
		const [expected] = find(name, text);
		expect(expected).toBeTruthy();
		expect(findMoveTarget(selectors, move(name, text), note)).toBe(
			expected
		);
	});

	it('does not match a container by the text of a block inside it', () => {
		const { selectors, note, find } = load(
			quote('Ada Lovelace'),
			quote('Inner words.', 'Ada Lovelace')
		);
		const [cited] = find('core/quote', 'Ada Lovelace');
		expect(
			findMoveTarget(selectors, move('core/quote', 'Ada Lovelace'), note)
		).toBe(cited);
		// A list item is found by its own text, not its nested item's.
		const list = load(NESTED_LIST);
		const [nested] = list.find('core/list-item', 'Nested item');
		expect(
			findMoveTarget(
				list.selectors,
				move('core/list-item', 'Nested item'),
				list.note
			)
		).toBe(nested);
	});

	it('gives up when two containers have the same own text', () => {
		const { selectors, note } = load(
			quote('First.', 'Ada Lovelace'),
			quote('Second.', 'Ada Lovelace')
		);
		expect(
			findMoveTarget(selectors, move('core/quote', 'Ada Lovelace'), note)
		).toBeNull();
	});

	it('gives up on a group, which has no text of its own', () => {
		const { selectors, note } = load(GROUP);
		expect(
			findMoveTarget(selectors, move('core/group', ''), note)
		).toBeNull();
	});

	// Replies kept a parent's text with its inner blocks' before.
	it.each([
		[
			'quote',
			[quote('Inner words.', 'Ada Lovelace')],
			'core/quote',
			'Inner words. Ada Lovelace',
		],
		['group', [GROUP], 'core/group', 'Grouped words.'],
		[
			'list item',
			[NESTED_LIST],
			'core/list-item',
			'Parent item Nested item',
		],
	])(
		'still finds a %s by the text older moves kept',
		(_label, markup, name, text) => {
			const { selectors, note } = load(...markup);
			const target = findMoveTarget(selectors, move(name, text), note);
			expect(target).not.toBeNull();
			expect(selectors.getBlock(target).name).toBe(name);
		}
	);
});
