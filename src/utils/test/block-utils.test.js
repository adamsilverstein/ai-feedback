/**
 * External dependencies
 */
import { afterAll, beforeAll, describe, expect, it } from 'vitest';

/**
 * WordPress dependencies
 */
import { create, RichTextData } from '@wordpress/rich-text';

/**
 * Internal dependencies
 */
import {
	extractBlockData,
	extractEditorText,
	getBlockAnchor,
} from '../block-utils';
import {
	registerEditorFormats,
	unregisterEditorFormats,
} from '../../../tests/js/editor-formats';

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
	// With core's formats, as in the editor, a footnote is an object.
	beforeAll(registerEditorFormats);
	afterAll(unregisterEditorFormats);

	it.each(EDITOR_TEXT.cases.map((item) => [item.name, item]))(
		'reads %s as the editor does',
		(_name, { html, text }) => {
			// The fixture is what rich text itself reads, not a guess.
			expect(create({ html }).text).toBe(text);
			expect(extractEditorText(html)).toBe(text);
		}
	);

	it('drops block delimiters', () => {
		expect(
			extractEditorText(
				'<!-- wp:paragraph -->\n<p>a<br>b</p>\n<!-- /wp:paragraph -->'
			)
		).toBe('\na\nb\n');
	});

	it('keeps a comment inside the text as an object, as the editor does', () => {
		expect(
			extractEditorText(
				'<!-- wp:paragraph -->\n<p>a<!-- note -->b</p>\n<!-- /wp:paragraph -->'
			)
		).toBe('\na\ufffcb\n');
	});

	it('reads a footnote in a list item with a nested list as an object', () => {
		const [data] = extractBlockData([
			{
				clientId: 'item',
				name: 'core/list-item',
				attributes: {
					content:
						'Revenue grew<sup data-fn="f1" class="fn"><a href="#f1" id="f1-link">1</a></sup> last year.',
				},
				originalContent: '<li>x</li>',
				innerBlocks: [
					{
						clientId: 'nested',
						name: 'core/list',
						attributes: {},
						originalContent: '<ul><li>Nested item</li></ul>',
						innerBlocks: [],
					},
				],
			},
		]);

		expect(data.content).toBe('Revenue grew\ufffc last year.');
	});
});

// Shared with ReplyServiceTest::test_text_edit_matches_own_text_of_a_block_with_inner_blocks().
describe('extractBlockData for a block with inner blocks', () => {
	it.each(
		EDITOR_TEXT.blocks.flatMap((item) => [
			[item.name, 'a string', item, item.content],
			[
				item.name,
				'rich text',
				item,
				RichTextData.fromHTMLString(item.content),
			],
		])
	)('sends the own text of a %s, as %s', (_name, _type, item, content) => {
		const [data] = extractBlockData([
			{
				clientId: 'item',
				name: item.block,
				attributes: { content },
				originalContent: item.before + item.inner + item.after,
				innerBlocks: [
					{
						clientId: 'nested',
						name: 'core/list',
						attributes: {},
						originalContent: item.inner,
						innerBlocks: [],
					},
				],
			},
		]);

		expect(create({ html: item.content }).text).toBe(item.text);
		expect(data.content).toBe(item.text.trim());
	});
});
