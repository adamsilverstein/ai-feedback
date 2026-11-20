/**
 * WordPress dependencies
 */
import { describe, it, expect } from 'vitest';
import { RichTextData } from '@wordpress/rich-text';

/**
 * Internal dependencies
 */
import { applyFixToContent } from '../quick-fix';
import { applyEditToContent, getRewriteRuns } from '../reply-edit';
import { applyFixesToContent } from '../proofreading';
import {
	acceptSuggestionMarkers,
	buildRewriteSuggestionContent,
	buildSuggestionContent,
	removeSuggestionMarkers,
} from '../suggestions';

const SOURCE = 'Revenue grew\nlast year in order to grow.';
const FIX = { find: 'in order to', replace: 'to' };

/**
 * Parse block HTML the way the block editor does when it loads a post.
 *
 * @param {string}  html               Block HTML.
 * @param {boolean} preserveWhiteSpace True for verse, preformatted and code.
 * @return {RichTextData} Attribute value.
 */
function parse(html, preserveWhiteSpace = false) {
	const { body } = document.implementation.createHTMLDocument('');
	body.innerHTML = html;
	return RichTextData.fromHTMLElement(body, { preserveWhiteSpace });
}

describe.each([
	['Apply', (content) => applyFixToContent(content, FIX)],
	[
		'a reply edit',
		(content) =>
			applyEditToContent(content, { type: 'replace_text', ...FIX }),
	],
	['Accept all', (content) => applyFixesToContent(content, [FIX]).content],
])('%s on a block with a newline in its HTML', (label, apply) => {
	it('keeps the newline in an HTML string', () => {
		expect(apply(SOURCE)).toBe('Revenue grew\nlast year to grow.');
	});

	it('reads like a manual edit in a paragraph', () => {
		const result = apply(parse(SOURCE));
		expect(result).toBeInstanceOf(RichTextData);
		expect(result.toHTMLString()).toBe('Revenue grew\nlast year to grow.');
		expect(result.text).toBe('Revenue grew last year to grow.');
	});

	it('keeps the newline as a line in a verse', () => {
		const result = apply(parse(SOURCE, true));
		expect(result.toHTMLString()).toBe('Revenue grew\nlast year to grow.');
		expect(result.text).toBe('Revenue grew\nlast year to grow.');
	});

	it('keeps a real line break', () => {
		expect(apply('Revenue grew<br>last year in order to grow.')).toBe(
			'Revenue grew<br>last year to grow.'
		);
		expect(
			apply(
				parse('Revenue grew<br>last year in order to grow.')
			).toHTMLString()
		).toBe('Revenue grew<br>last year to grow.');
	});

	it('keeps a break and newline written together', () => {
		expect(apply('Revenue grew<br>\nlast year in order to grow.')).toBe(
			'Revenue grew<br>\nlast year to grow.'
		);
	});

	it('keeps newlines inside formatting and tags', () => {
		expect(
			apply(
				'<strong>Revenue\ngrew</strong>\n<a\nhref="#">last</a> year in order to grow.'
			)
		).toBe(
			'<strong>Revenue\ngrew</strong>\n<a href="#">last</a> year to grow.'
		);
	});
});

describe('a fix whose text spans a newline in the HTML', () => {
	const SPANNING = {
		find: 'grew\nlast year in order to',
		replace: 'grew\nlast year to',
	};

	it.each([
		['Apply', (content) => applyFixToContent(content, SPANNING)],
		[
			'Accept all',
			(content) => applyFixesToContent(content, [SPANNING]).content,
		],
	])(
		'keeps the newline where the replacement keeps it, with %s',
		(label, apply) => {
			expect(apply(SOURCE)).toBe('Revenue grew\nlast year to grow.');
			expect(apply(parse(SOURCE)).toHTMLString()).toBe(
				'Revenue grew\nlast year to grow.'
			);
		}
	);

	it('writes a newline the fix adds as a line break', () => {
		expect(
			applyFixToContent('Revenue grew last year.', {
				find: 'grew last',
				replace: 'grew\nlast',
			})
		).toBe('Revenue grew<br>last year.');
	});

	it('keeps a real line break the replacement keeps', () => {
		expect(
			applyFixToContent('Revenue grew<br>last year in order to grow.', {
				find: 'grew\nlast year in order to',
				replace: 'grew\nlast year to',
			})
		).toBe('Revenue grew<br>last year to grow.');
	});
});

describe('Accept all with several fixes around newlines', () => {
	it('keeps every newline between the fixes', () => {
		const result = applyFixesToContent(
			'We utilize\ntools\nin order to\nwin.',
			[
				{ find: 'utilize', replace: 'use' },
				{ find: 'in order to', replace: 'to' },
			]
		);
		expect(result.content).toBe('We use\ntools\nto\nwin.');
	});
});

describe('Suggestion Mode on a block with a newline in its HTML', () => {
	it('keeps the newline outside and inside the markers', () => {
		const result = buildSuggestionContent(
			SOURCE,
			{ find: 'grew\nlast', replace: 'rose\nlast' },
			{ id: 7 }
		);
		expect(result).not.toContain('<br');
		expect(result).toContain('Revenue ');
		expect(result).toContain('rose\nlast');
		expect(result).toContain('grew\nlast');
	});

	it('keeps the newline once the suggestion is accepted or rejected', () => {
		const suggested = buildSuggestionContent(parse(SOURCE), FIX, {
			id: 7,
		});
		expect(suggested.toHTMLString()).not.toContain('<br');

		const accepted = acceptSuggestionMarkers(suggested, 7);
		expect(accepted.toHTMLString()).toBe(
			'Revenue grew\nlast year to grow.'
		);
		expect(accepted.text).toBe('Revenue grew last year to grow.');

		const rejected = removeSuggestionMarkers(suggested, 7);
		expect(rejected.toHTMLString()).toBe(SOURCE);
		expect(rejected.text).toBe('Revenue grew last year in order to grow.');
	});

	it('keeps the newline in a rewrite suggestion', () => {
		const edit = {
			type: 'rewrite_block',
			original: SOURCE,
			content: 'Revenue grew\nlast year to grow.',
		};
		expect(
			getRewriteRuns(SOURCE, edit)
				.map((run) => run.html)
				.join('')
		).not.toContain('<br');
		const result = buildRewriteSuggestionContent(SOURCE, edit, {
			id: 7,
		});
		expect(result).not.toContain('<br');
		expect(result).toContain('Revenue grew\nlast year ');
	});
});

describe('private use characters in the content', () => {
	const GLYPH = '';

	it('keeps one in a block without a source newline', () => {
		expect(
			applyFixToContent(`Call ${GLYPH} us in order to win.`, FIX)
		).toBe(`Call ${GLYPH} us to win.`);
	});

	it('keeps one beside a source newline', () => {
		const html = `Call ${GLYPH} us\nin order to win.`;
		expect(applyFixToContent(html, FIX)).toBe(`Call ${GLYPH} us\nto win.`);
		expect(applyFixesToContent(html, [FIX]).content).toBe(
			`Call ${GLYPH} us\nto win.`
		);
		expect(applyFixToContent(parse(html), FIX).toHTMLString()).toBe(
			`Call ${GLYPH} us\nto win.`
		);
	});

	it('keeps one the replacement brings in', () => {
		const fix = { find: 'in order to', replace: `${GLYPH} to` };
		expect(applyFixToContent(SOURCE, fix)).toBe(
			`Revenue grew\nlast year ${GLYPH} to grow.`
		);
		expect(applyFixesToContent(SOURCE, [fix]).content).toBe(
			`Revenue grew\nlast year ${GLYPH} to grow.`
		);
		const suggested = buildSuggestionContent(SOURCE, fix, { id: 7 });
		expect(suggested).toContain(`${GLYPH} to`);
		expect(suggested).toContain('Revenue grew\nlast');
		expect(suggested).not.toContain('<br');
	});

	it('keeps one in a suggestion and after accepting it', () => {
		const html = `Call ${GLYPH} us\nin order to win.`;
		const suggested = buildSuggestionContent(parse(html), FIX, { id: 7 });
		expect(suggested.toHTMLString()).toContain(`Call ${GLYPH} us\n`);
		expect(acceptSuggestionMarkers(suggested, 7).toHTMLString()).toBe(
			`Call ${GLYPH} us\nto win.`
		);
	});

	it('works as before when every private use character is taken', () => {
		let glyphs = '';
		for (let code = 0xe000; code <= 0xf8ff; code++) {
			glyphs += String.fromCharCode(code);
		}
		expect(
			applyFixToContent(`${glyphs} grew\nlast year in order to win.`, FIX)
		).toBe(`${glyphs} grew<br>last year to win.`);
	});
});
