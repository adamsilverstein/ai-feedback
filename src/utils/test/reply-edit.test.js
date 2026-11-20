/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * WordPress dependencies
 */
import { create, RichTextData } from '@wordpress/rich-text';

/**
 * Internal dependencies
 */
import {
	applyEditToContent,
	describeEdit,
	diffValues,
	getEditPreview,
	getRewriteRuns,
	isEditApplied,
	isEditCurrent,
	isReplyEdit,
	MAX_DIFF_CELLS,
	normalizeText,
} from '../reply-edit';

const CONTENT = 'Revenue grew 40% last year.';
const TEXT_EDIT = {
	type: 'replace_text',
	find: 'last year',
	replace: 'in 2025',
};
const REWRITE = {
	type: 'rewrite_block',
	content: 'Revenue grew <em>sharply</em> last year.',
	original: 'Revenue grew 40% last year.',
};

describe('isReplyEdit', () => {
	it.each([
		[TEXT_EDIT, true],
		[REWRITE, true],
		[{ type: 'replace_text', find: 'a' }, false],
		[{ type: 'rewrite_block', content: 'a' }, false],
		[{ find: 'a', replace: 'b' }, false],
		[null, false],
	])('checks %j', (edit, expected) => {
		expect(isReplyEdit(edit)).toBe(expected);
	});
});

describe('normalizeText', () => {
	it('collapses whitespace', () => {
		expect(normalizeText(' a \n\t b ')).toBe('a b');
	});
});

describe('isEditCurrent', () => {
	it('needs the text of a text edit exactly once', () => {
		expect(isEditCurrent(CONTENT, TEXT_EDIT)).toBe(true);
		expect(isEditCurrent('last year, last year.', TEXT_EDIT)).toBe(false);
		expect(isEditCurrent('Revenue grew.', TEXT_EDIT)).toBe(false);
	});

	it('needs the block to read as the rewrite expects', () => {
		expect(isEditCurrent(CONTENT, REWRITE)).toBe(true);
		// Wrapping does not matter, the words do.
		expect(isEditCurrent('Revenue grew\n 40%  last year.', REWRITE)).toBe(
			true
		);
		expect(isEditCurrent('Revenue grew 45% last year.', REWRITE)).toBe(
			false
		);
	});
});

describe('rewrites of formatted blocks', () => {
	// The AI only saw the plain text, so applying would drop the link.
	const linked =
		'Revenue grew 40% <a href="https://example.com">last year</a>.';

	it.each([
		['a link', linked],
		['bold text', 'Revenue grew <strong>40%</strong> last year.'],
		['a line break', 'Revenue grew 40%<br>last year.'],
	])('never apply to a block with %s', (_label, content) => {
		expect(isEditCurrent(content, REWRITE)).toBe(false);
		expect(applyEditToContent(content, REWRITE)).toBeNull();
		expect(getEditPreview(content, REWRITE)).toBeNull();
		expect(getRewriteRuns(content, REWRITE)).toBeNull();
	});

	it('still lets a text edit through', () => {
		expect(applyEditToContent(linked, TEXT_EDIT)).toBe(
			'Revenue grew 40% <a href="https://example.com">in 2025</a>.'
		);
	});
});

describe('isEditApplied', () => {
	it('reads an edit that adds words as applied, not outdated', () => {
		const edit = {
			type: 'replace_text',
			find: 'data',
			replace: 'the data',
		};
		expect(isEditApplied('Read the data now.', edit)).toBe(true);
		expect(isEditApplied('Read data now.', edit)).toBe(false);
	});

	it('recognizes a text edit already in the block', () => {
		expect(isEditApplied('Revenue grew 40% in 2025.', TEXT_EDIT)).toBe(
			true
		);
		expect(isEditApplied(CONTENT, TEXT_EDIT)).toBe(false);
		// Both texts present: neither clearly applied nor clearly not.
		expect(
			isEditApplied('Last year, last year and in 2025.', TEXT_EDIT)
		).toBe(false);
		expect(isEditApplied('Something else.', TEXT_EDIT)).toBe(false);
	});

	it('cannot tell a deletion was applied', () => {
		expect(
			isEditApplied('Revenue grew.', { find: 'a lot', replace: '' })
		).toBe(false);
	});

	it('recognizes a rewrite already in the block', () => {
		expect(
			isEditApplied('Revenue grew <em>sharply</em> last year.', REWRITE)
		).toBe(true);
		expect(isEditApplied(CONTENT, REWRITE)).toBe(false);
	});
});

describe('applyEditToContent', () => {
	it('applies a text edit like a quick fix', () => {
		expect(applyEditToContent(CONTENT, TEXT_EDIT)).toBe(
			'Revenue grew 40% in 2025.'
		);
	});

	it('replaces the block with a rewrite', () => {
		expect(applyEditToContent(CONTENT, REWRITE)).toBe(REWRITE.content);
	});

	it('keeps RichTextData values as RichTextData', () => {
		const updated = applyEditToContent(
			RichTextData.fromHTMLString(CONTENT),
			REWRITE
		);
		expect(updated).toBeInstanceOf(RichTextData);
		expect(updated.toHTMLString()).toBe(REWRITE.content);
	});

	it('refuses an outdated rewrite', () => {
		expect(
			applyEditToContent('Revenue grew 45% last year.', REWRITE)
		).toBeNull();
	});
});

describe('diffValues', () => {
	const diff = (before, after) =>
		diffValues(create({ html: before }), create({ html: after }));

	it('marks only the changed words', () => {
		expect(
			diff(
				'The quick brown fox jumps over the lazy dog.',
				'The quick red fox jumps over the lazy dog.'
			)
		).toEqual([
			{ type: 'equal', from: 0, to: 10, newFrom: 0, newTo: 10 },
			{ type: 'change', from: 10, to: 15, newFrom: 10, newTo: 13 },
			{ type: 'equal', from: 15, to: 44, newFrom: 13, newTo: 42 },
		]);
	});

	it('treats a formatting change as a change', () => {
		const runs = diff(
			'Revenue grew a lot.',
			'Revenue grew <em>a</em> lot.'
		);
		expect(runs.filter((run) => run.type === 'change')).toEqual([
			{ type: 'change', from: 13, to: 14, newFrom: 13, newTo: 14 },
		]);
	});

	it('replaces the whole text when most of it changed', () => {
		expect(diff('One two three.', 'Four five six seven.')).toEqual([
			{ type: 'change', from: 0, to: 14, newFrom: 0, newTo: 20 },
		]);
	});

	it('replaces the whole text when it is too long to diff', () => {
		const words = Math.ceil(Math.sqrt(MAX_DIFF_CELLS));
		const long = Array.from({ length: words }, (_, i) => `w${i}`).join(' ');
		expect(diff(long, long + ' end')).toHaveLength(1);
	});
});

describe('getEditPreview', () => {
	it('previews a text edit with context', () => {
		expect(getEditPreview(CONTENT, TEXT_EDIT)).toEqual([
			{ type: 'equal', text: 'Revenue grew 40% ' },
			{ type: 'removed', text: 'last year' },
			{ type: 'added', text: 'in 2025' },
			{ type: 'equal', text: '.' },
		]);
	});

	it('previews a rewrite as a word diff', () => {
		expect(getEditPreview(CONTENT, REWRITE)).toEqual([
			{ type: 'equal', text: 'Revenue grew ' },
			{ type: 'removed', text: '40%' },
			{ type: 'added', text: 'sharply' },
			{ type: 'equal', text: ' last year.' },
		]);
	});

	it('returns null for an outdated edit', () => {
		expect(getEditPreview('Something else.', TEXT_EDIT)).toBeNull();
		expect(getEditPreview('Something else.', REWRITE)).toBeNull();
	});
});

describe('getRewriteRuns', () => {
	it('keeps the formatting of the rewrite', () => {
		expect(getRewriteRuns(CONTENT, REWRITE)).toEqual([
			{ type: 'equal', html: 'Revenue grew ' },
			{
				type: 'change',
				html: '<em>sharply</em>',
				removedHtml: '40%',
			},
			{ type: 'equal', html: ' last year.' },
		]);
	});

	it('returns null for an outdated rewrite or another edit', () => {
		expect(getRewriteRuns('Changed.', REWRITE)).toBeNull();
		expect(getRewriteRuns(CONTENT, TEXT_EDIT)).toBeNull();
	});
});

describe('describeEdit', () => {
	it('describes each kind of edit', () => {
		expect(describeEdit(TEXT_EDIT)).toBe(
			'Replace “last year” with “in 2025”'
		);
		expect(describeEdit({ find: 'very', replace: '' })).toBe(
			'Delete “very”'
		);
		expect(describeEdit(REWRITE)).toBe('Rewrite this block');
	});
});
