/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * WordPress dependencies
 */
import { create } from '@wordpress/rich-text';

/**
 * Internal dependencies
 */
import {
	extractBlockData,
	extractEditorText,
	getBlockAnchor,
} from '../block-utils';

const EDITOR_TEXT = require('../../../tests/fixtures/editor-text.json');

const block = (clientId, content, innerBlocks = []) => ({
	clientId,
	name: 'core/paragraph',
	originalContent: content,
	innerBlocks,
});

describe('extractBlockData', () => {
	it('gives inner blocks their parent’s client ID', () => {
		const data = extractBlockData([
			block('group', '<div>Inside.</div>', [
				block('inner', '<p>Inside.</p>'),
			]),
			block('after', '<p>After.</p>'),
		]);

		expect(data.map(({ clientId, parent }) => [clientId, parent])).toEqual([
			['group', undefined],
			['inner', 'group'],
			['after', undefined],
		]);
	});

	it('sends the attributes formatting fixes check', () => {
		const data = extractBlockData([
			{
				clientId: 'h',
				name: 'core/heading',
				originalContent: '<h4>Setup</h4>',
				attributes: { level: 4, className: 'x' },
				innerBlocks: [],
			},
			{
				clientId: 'l',
				name: 'core/list',
				originalContent: '<ul><li>One</li></ul>',
				attributes: {},
				innerBlocks: [],
			},
			block('p', '<p>Text.</p>'),
		]);

		expect(
			data.map(({ clientId, attributes }) => [clientId, attributes])
		).toEqual([
			['h', { level: 4 }],
			['l', { ordered: false }],
			['p', undefined],
		]);
	});

	it('includes an image without a caption, told apart by its URL', () => {
		const image = (clientId, url) => ({
			clientId,
			name: 'core/image',
			originalContent: `<figure><img src="${url}" alt=""/></figure>`,
			attributes: { url, alt: '', id: 12, className: 'x' },
			innerBlocks: [],
		});
		const data = extractBlockData([
			image('a', 'http://example.com/a.png'),
			image('b', 'http://example.com/b.png'),
			// Still uploading: no URL worth sending.
			image('c', 'blob:http://example.com/1'),
		]);

		expect(data.map(({ clientId }) => clientId)).toEqual(['a', 'b']);
		expect(data[0]).toMatchObject({
			content: '',
			attributes: {
				alt: '',
				id: 12,
				url: 'http://example.com/a.png',
			},
		});
		expect(data[0].anchor).not.toBe(data[1].anchor);
	});

	// The editor reads a break as "\n", so the review sees the same text a
	// quick fix's `find` is later matched against.
	it.each([
		['br', '<p>First line<br>second line.</p>'],
		['self-closing', '<p>First line<br />second line.</p>'],
	])('sends a %s line break as a newline', (_label, html) => {
		const [data] = extractBlockData([block('p', html)]);

		expect(data.content).toBe('First line\nsecond line.');
	});

	it('keeps wrapped source text as written, as the editor does', () => {
		const [data] = extractBlockData([
			block('p', '<p>First\n\tline<br />\nsecond line.</p>'),
		]);

		expect(data.content).toBe('First\n\tline\n\nsecond line.');
	});

	it('keeps the anchor notes saved before were linked by', () => {
		const [data] = extractBlockData([
			block('p', '<p>First line<br>second line.</p>'),
		]);

		expect(data.anchor).toBe(
			getBlockAnchor('core/paragraph', 'First linesecond line.')
		);
	});
});

// Shared with Reply_Service::editor_text() on the server.
describe('extractEditorText', () => {
	it.each(EDITOR_TEXT.cases.map((item) => [item.name, item]))(
		'reads %s as the editor does',
		(_name, { html, text }) => {
			// The fixture is what rich text itself reads, not a guess.
			expect(create({ html }).text).toBe(text);
			expect(extractEditorText(html)).toBe(text);
		}
	);

	it('drops comments, which are block delimiters here', () => {
		expect(
			extractEditorText(
				'<!-- wp:paragraph -->\n<p>a<br>b</p>\n<!-- /wp:paragraph -->'
			)
		).toBe('\na\nb\n');
	});
});
