/**
 * WordPress dependencies
 */
import { afterAll, beforeAll, describe, it, expect } from 'vitest';
import { create, RichTextData, toHTMLString } from '@wordpress/rich-text';

/**
 * Internal dependencies
 */
import {
	findMatches,
	applyFixToContent,
	getFixPreview,
	getRichTextAttributeKey,
	isFixInPlace,
} from '../quick-fix';
import {
	registerEditorFormats,
	unregisterEditorFormats,
} from '../../../tests/js/editor-formats';

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

describe('findMatches', () => {
	it.each([
		['whole words only', 'the cat sat in concatenate', 'cat', [4]],
		['punctuation edges', 'Hello, world.', 'world.', [7]],
		['leading space', 'very very good', ' very', [4]],
		['unicode letters', 'café cafés', 'café', [0]],
		['regex characters', 'a (b) c', '(b)', [2]],
		['no find', 'text', '', []],
	])('handles %s', (_label, text, find, expected) => {
		expect(findMatches(text, find)).toEqual(expected);
	});
});

describe('applyFixToContent replacements', () => {
	it('leaves no undefined entries for core-data to read', () => {
		const result = applyFixToContent(
			RichTextData.fromHTMLString('We utilize tools daily.'),
			{ find: 'utilize', replace: 'use' }
		);
		const seen = [];

		// forEach skips holes, which is how core-data walks this array when
		// it orders footnotes.
		result.replacements.forEach((replacement) => seen.push(replacement));

		expect(seen).toEqual([]);
		expect(result.toHTMLString()).toBe('We use tools daily.');
	});
});

describe('fixes that add words', () => {
	const fix = { find: 'data', replace: 'the data' };

	it('applies once, then reads as in place instead of applying again', () => {
		const once = applyFixToContent('Read data now.', fix);
		expect(once).toBe('Read the data now.');
		expect(applyFixToContent(once, fix)).toBeNull();
		expect(getFixPreview(once, fix)).toBeNull();
		expect(isFixInPlace(once, fix)).toBe(true);
		expect(isFixInPlace('Read data now.', fix)).toBe(false);
	});

	it('still applies where the replacement is elsewhere in the text', () => {
		expect(
			applyFixToContent('was not here, it was there', {
				find: 'it was',
				replace: 'it was not',
			})
		).toBe('was not here, it was not there');
	});
});

// The server reads the block the same way (Reply_Service::editor_text()), so
// a `find` it accepts always matches here.
describe('line breaks', () => {
	it.each([
		['br', 'Revenue grew 40%<br>last year.'],
		['self-closing', 'Revenue grew 40%<br/>last year.'],
		['spaced closing', 'Revenue grew 40%<br />last year.'],
	])('does not match a find across a %s with a space', (_label, html) => {
		expect(
			applyFixToContent(html, { find: '40% last year', replace: 'x' })
		).toBeNull();
	});

	it('applies a find that holds the break and keeps it', () => {
		expect(
			applyFixToContent('Revenue grew 40%<br />last year.', {
				find: '40%\nlast year',
				replace: '40%\nin 2025',
			})
		).toBe('Revenue grew 40%<br>in 2025.');
	});

	it('applies a find on one side of the break', () => {
		expect(
			applyFixToContent('Revenue grew 40%<br>last year.', {
				find: 'last year',
				replace: 'in 2025',
			})
		).toBe('Revenue grew 40%<br>in 2025.');
	});

	it('shows the break in the preview', () => {
		expect(
			getFixPreview('Revenue grew 40%<br>last year.', {
				find: 'last year',
				replace: 'in 2025',
			})
		).toEqual({
			before: 'Revenue grew 40%\n',
			removed: 'last year',
			added: 'in 2025',
			after: '.',
		});
	});
});

// The server reads `&nbsp;` as a space, so a `find` written with spaces
// matches non-breaking ones here too.
describe('non-breaking spaces', () => {
	it.each([
		['space in find', 'grew 40%', 'grew 40%', [0]],
		['nbsp in find', 'grew 40%', 'grew 40%', [0]],
		['still one character', 'a  b', 'a b', []],
	])('matches with a %s', (_label, text, find, expected) => {
		expect(findMatches(text, find)).toEqual(expected);
	});

	it('applies a fix over a non-breaking space', () => {
		expect(
			applyFixToContent('Revenue grew&nbsp;40% last year.', {
				find: 'grew 40%',
				replace: 'grew 45%',
			})
		).toBe('Revenue grew 45% last year.');
	});

	it('sees a fix that adds words as in place', () => {
		expect(
			isFixInPlace('We read the&nbsp;data.', {
				find: 'data',
				replace: 'the data',
			})
		).toBe(true);
	});
});

describe('footnotes', () => {
	// With core's formats, as in the editor, a footnote is one object.
	beforeAll(registerEditorFormats);
	afterAll(unregisterEditorFormats);

	const FOOTNOTE =
		'<sup data-fn="f1" class="fn"><a href="#f1" id="f1-link">1</a></sup>';
	const content = `Revenue growth${FOOTNOTE} was strong last year.`;
	// Rich text writes a footnote's attributes in its own order.
	const normalize = (html) => toHTMLString({ value: create({ html }) });
	const footnotes = (html) => html.split('data-fn="f1"').length - 1;

	it('applies a fix beside a footnote and keeps the footnote once', () => {
		const result = applyFixToContent(content, {
			find: 'strong',
			replace: 'steady',
		});

		expect(result).toBe(
			normalize(`Revenue growth${FOOTNOTE} was steady last year.`)
		);
		expect(footnotes(result)).toBe(1);
	});

	it('applies a fix that ends at the footnote', () => {
		const result = applyFixToContent(RichTextData.fromHTMLString(content), {
			find: 'Revenue growth',
			replace: 'Sales growth',
		});

		expect(result).toBeInstanceOf(RichTextData);
		expect(result.toHTMLString()).toBe(
			normalize(`Sales growth${FOOTNOTE} was strong last year.`)
		);
	});

	it('never matches the footnote number', () => {
		expect(
			applyFixToContent(content, { find: 'growth1 was', replace: 'x' })
		).toBeNull();
	});

	it('never applies a fix holding the object character', () => {
		expect(
			applyFixToContent(content, {
				find: 'growth\ufffc was',
				replace: 'growth was',
			})
		).toBeNull();
		expect(
			applyFixToContent(content, {
				find: 'strong',
				replace: 'strong\ufffc',
			})
		).toBeNull();
		expect(
			getFixPreview(content, { find: 'growth\ufffc', replace: 'x' })
		).toBeNull();
	});
});
