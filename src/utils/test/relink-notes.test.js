/**
 * Internal dependencies
 */
import { describe, it, expect } from 'vitest';
import { getNoteRelinks } from '../relink-notes';
import { getBlockAnchor } from '../block-utils';

const paragraph = (clientId, text, metadata) => ({
	clientId,
	name: 'core/paragraph',
	originalContent: `<p>${text}</p>`,
	attributes: metadata ? { metadata } : {},
	innerBlocks: [],
});

const note = (id, text, extra = {}) => ({
	id,
	parent: 0,
	block_anchor: getBlockAnchor('core/paragraph', text),
	...extra,
});

describe('getBlockAnchor', () => {
	it('is stable for the same block and text', () => {
		expect(getBlockAnchor('core/paragraph', 'Hello')).toBe(
			getBlockAnchor('core/paragraph', 'Hello')
		);
		expect(getBlockAnchor('core/paragraph', 'Hello')).toMatch(
			/^[a-z0-9]{1,32}$/
		);
	});

	it('changes with the text or the block name', () => {
		const anchor = getBlockAnchor('core/paragraph', 'Hello');
		expect(getBlockAnchor('core/paragraph', 'Hello!')).not.toBe(anchor);
		expect(getBlockAnchor('core/heading', 'Hello')).not.toBe(anchor);
	});
});

describe('getNoteRelinks', () => {
	it('links orphaned notes to blocks with the same anchor', () => {
		const blocks = [paragraph('a', 'First.'), paragraph('b', 'Second.')];
		const notes = [note(11, 'Second.'), note(10, 'First.')];

		expect(getNoteRelinks(blocks, notes)).toEqual({ a: 10, b: 11 });
	});

	it('skips notes that are already linked, replies and notes without an anchor', () => {
		const blocks = [
			paragraph('a', 'First.', { noteId: 10 }),
			paragraph('b', 'First.'),
		];
		const notes = [
			note(10, 'First.'),
			note(12, 'First.', { parent: 10 }),
			{ id: 13, parent: 0, block_anchor: '' },
		];

		expect(getNoteRelinks(blocks, notes)).toEqual({});
	});

	it('does not replace a link to another note', () => {
		const blocks = [paragraph('a', 'First.', { noteId: 99 })];

		expect(getNoteRelinks(blocks, [note(10, 'First.')])).toEqual({});
	});

	it('gives blocks with the same text to notes in document order', () => {
		const blocks = [paragraph('a', 'Same.'), paragraph('b', 'Same.')];
		const notes = [note(21, 'Same.'), note(20, 'Same.')];

		expect(getNoteRelinks(blocks, notes)).toEqual({ a: 20, b: 21 });
	});

	it('finds inner blocks', () => {
		const blocks = [
			{
				clientId: 'group',
				name: 'core/group',
				originalContent: '',
				attributes: {},
				innerBlocks: [paragraph('inner', 'Nested.')],
			},
		];

		expect(getNoteRelinks(blocks, [note(30, 'Nested.')])).toEqual({
			inner: 30,
		});
	});

	it('leaves notes whose block text changed', () => {
		const blocks = [paragraph('a', 'Edited.')];

		expect(getNoteRelinks(blocks, [note(10, 'Original.')])).toEqual({});
	});
});
