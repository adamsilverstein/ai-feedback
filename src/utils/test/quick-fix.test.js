/**
 * WordPress dependencies
 */
import { RichTextData } from '@wordpress/rich-text';

/**
 * Internal dependencies
 */
import {
	applyFixToContent,
	getFixPreview,
	getRichTextAttributeKey,
} from '../quick-fix';

describe('getRichTextAttributeKey', () => {
	it.each([
		[{ attributes: { content: { type: 'rich-text' } } }, 'content'],
		[{ attributes: { content: { source: 'html' } } }, 'content'],
		[{ attributes: { url: { type: 'string' } } }, null],
		[undefined, null],
	])('for %j returns %s', (blockType, expected) => {
		expect(getRichTextAttributeKey(blockType)).toBe(expected);
	});
});

describe('applyFixToContent', () => {
	it('replaces text and keeps surrounding formatting', () => {
		expect(
			applyFixToContent('We <strong>utilize</strong> tools daily.', {
				find: 'tools daily',
				replace: 'tools every day',
			})
		).toBe('We <strong>utilize</strong> tools every day.');
	});

	it('deletes text with an empty replacement', () => {
		expect(
			applyFixToContent('This is very very good.', {
				find: 'very very ',
				replace: '',
			})
		).toBe('This is good.');
	});

	it('returns RichTextData for RichTextData input', () => {
		const result = applyFixToContent(
			RichTextData.fromHTMLString('Hello wrold'),
			{ find: 'wrold', replace: 'world' }
		);

		expect(result).toBeInstanceOf(RichTextData);
		expect(result.toHTMLString()).toBe('Hello world');
	});

	it.each([
		['missing text', 'Hello world', { find: 'planet', replace: 'x' }],
		[
			'ambiguous text',
			'the cat and the dog',
			{ find: 'the', replace: 'a' },
		],
		['empty find', 'Hello', { find: '', replace: 'x' }],
	])('returns null for %s', (_label, content, fix) => {
		expect(applyFixToContent(content, fix)).toBeNull();
	});
});

describe('getFixPreview', () => {
	it('returns the change with surrounding context', () => {
		expect(
			getFixPreview(
				'A long sentence that we utilize here.',
				{ find: 'utilize', replace: 'use' },
				10
			)
		).toEqual({
			before: '…e that we ',
			removed: 'utilize',
			added: 'use',
			after: ' here.',
		});
	});

	it('returns null when the fix no longer applies', () => {
		expect(
			getFixPreview('Changed', { find: 'x', replace: 'y' })
		).toBeNull();
	});
});

describe('applyFixToContent formatting', () => {
	it('keeps formatting on replaced text', () => {
		expect(
			applyFixToContent(
				'Go <a href="https://example.com">here now</a>.',
				{
					find: 'here now',
					replace: 'there',
				}
			)
		).toBe('Go <a href="https://example.com">there</a>.');
	});
});
