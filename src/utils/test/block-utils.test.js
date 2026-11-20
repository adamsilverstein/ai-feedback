/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { extractBlockData } from '../block-utils';

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
});
