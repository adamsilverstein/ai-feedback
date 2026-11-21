/**
 * External dependencies
 */
import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';

/**
 * Internal dependencies
 */
import { getBlockAnchor } from '../block-utils';

const blocks = [];

vi.mock('@wordpress/block-editor', () => ({ store: 'core/block-editor' }));
vi.mock('@wordpress/blocks', () => ({ serialize: () => '' }));
// Only the block editor is faked; rich-text, used to read block text,
// keeps the real registry.
vi.mock('@wordpress/data', async (importOriginal) => {
	const actual = await importOriginal();
	return {
		...actual,
		select: (store) =>
			store === 'core/block-editor'
				? {
						getBlock: (id) =>
							blocks.find((block) => block.clientId === id),
						getBlocks: () => blocks,
					}
				: actual.select(store),
	};
});

import {
	MAX_STRENGTHS,
	findStrengthBlock,
	getStrengths,
	mergeStrengths,
} from '../strengths';

const strength = (blockId, text = `About ${blockId}.`) => ({
	block_id: blockId,
	text,
});

describe('getStrengths', () => {
	it('ignores anything but a list', () => {
		expect(getStrengths(undefined)).toEqual([]);
		expect(getStrengths('Great!')).toEqual([]);
	});

	it('drops malformed strengths', () => {
		expect(
			getStrengths([
				strength('a'),
				{ block_id: 'b' },
				{ text: 'No block.' },
				{ block_id: 'c', text: '  ' },
				{ block_id: 'd', text: 42 },
				null,
			])
		).toEqual([strength('a')]);
	});
});

describe('mergeStrengths', () => {
	it('keeps part order, one per block, capped at three', () => {
		const merged = mergeStrengths(
			[strength('a'), strength('b')],
			[strength('b', 'Again.'), strength('c'), strength('d')]
		);
		expect(merged.map((item) => item.block_id)).toEqual(['a', 'b', 'c']);
		expect(merged).toHaveLength(MAX_STRENGTHS);
		expect(merged[1].text).toBe('About b.');
	});
});

describe('mergeStrengths with a lower site maximum', () => {
	afterEach(() => {
		delete window.aiFeedbackData;
	});

	it('caps a chunked review at the maximum the site allows', () => {
		window.aiFeedbackData = { maxStrengths: '1' };
		expect(
			mergeStrengths([strength('a')], [strength('b')]).map(
				(item) => item.block_id
			)
		).toEqual(['a']);
	});

	it('keeps none when strengths are turned off', () => {
		window.aiFeedbackData = { maxStrengths: '0' };
		expect(mergeStrengths([strength('a')])).toEqual([]);
	});

	it('never goes above three or below the default without a valid value', () => {
		const lists = ['a', 'b', 'c', 'd'].map((id) => [strength(id)]);
		window.aiFeedbackData = { maxStrengths: '50' };
		expect(mergeStrengths(...lists)).toHaveLength(MAX_STRENGTHS);
		window.aiFeedbackData = { maxStrengths: 'many' };
		expect(mergeStrengths(...lists)).toHaveLength(MAX_STRENGTHS);
	});
});

describe('findStrengthBlock', () => {
	beforeEach(() => {
		blocks.length = 0;
		blocks.push({
			clientId: 'new-id',
			name: 'core/paragraph',
			attributes: {},
			originalContent: '<p>The opening example.</p>',
			innerBlocks: [],
		});
	});

	it('uses the client ID while it exists', () => {
		expect(findStrengthBlock({ block_id: 'new-id' })).toBe('new-id');
	});

	it('finds the block by its text after a reload', () => {
		expect(
			findStrengthBlock({
				block_id: 'stale-id',
				block_name: 'core/paragraph',
				block_anchor: getBlockAnchor(
					'core/paragraph',
					'The opening example.'
				),
			})
		).toBe('new-id');
	});

	it('gives up on a block edited since the review', () => {
		expect(
			findStrengthBlock({
				block_id: 'stale-id',
				block_name: 'core/paragraph',
				block_anchor: getBlockAnchor('core/paragraph', 'Old text.'),
			})
		).toBeNull();
	});

	it('gives up without an anchor', () => {
		expect(findStrengthBlock({ block_id: 'stale-id' })).toBeNull();
	});
});
