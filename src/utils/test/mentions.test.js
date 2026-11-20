/**
 * Tests for mentions.
 */
import { describe, it, expect } from 'vitest';
import {
	getNoteContent,
	getNoteText,
	mayAwaitAnswer,
	mentionsFreshEyes,
} from '../mentions';

describe('mentionsFreshEyes', () => {
	it.each([
		'@fresh-eyes simplify this',
		'@FreshEyes adjust the tone',
		'Can @Fresh Eyes help here?',
		'@fresh_eyes, shorten this.',
		'<p>Hey <strong>@fresh-eyes</strong> simplify</p>',
		'<span class="wp-note-mention user-9">@Fresh Eyes</span> simplify',
		'&#64;fresh-eyes simplify',
		'Simplify (@fresh-eyes)',
	])('finds a mention in %s', (content) => {
		expect(mentionsFreshEyes(content)).toBe(true);
	});

	it.each([
		'Simplify this paragraph.',
		'Fresh Eyes said this was fine.',
		'Write to editor@fresh-eyes.example',
		'See @fresh-eyes.com for details',
		'@fresh-eyesight is a different user',
		'<blockquote>@fresh-eyes simplify this</blockquote> I disagree.',
		'She wrote <q>@fresh-eyes rewrite</q> earlier.',
		'Type <code>@fresh-eyes</code> to ask.',
		'Earlier: <blockquote>@fresh-eyes do it',
		'<span class="wp-note-mention fresh-eyes">@jane</span>',
		'<a href="https://example.com/@fresh-eyes">link</a>',
		'',
		undefined,
	])('finds no mention in %s', (content) => {
		expect(mentionsFreshEyes(content)).toBe(false);
	});

	it('only scans the start of a long note', () => {
		const content = 'word '.repeat(5000) + '@fresh-eyes simplify';
		expect(mentionsFreshEyes(content)).toBe(false);
		expect(getNoteText(content).length).toBeLessThanOrEqual(5000);
	});
});

describe('getNoteContent', () => {
	it('reads raw, rendered or plain content', () => {
		expect(getNoteContent({ content: { raw: 'a', rendered: 'b' } })).toBe(
			'a'
		);
		expect(getNoteContent({ content: { rendered: 'b' } })).toBe('b');
		expect(getNoteContent({ content: 'c' })).toBe('c');
		expect(getNoteContent(null)).toBe('');
	});
});

describe('mayAwaitAnswer', () => {
	it('is true for replies and new notes that mention Fresh Eyes', () => {
		expect(mayAwaitAnswer({ parent: 3, content: { raw: 'Why?' } })).toBe(
			true
		);
		expect(
			mayAwaitAnswer({
				parent: 0,
				content: { raw: '<p>@fresh-eyes simplify</p>' },
			})
		).toBe(true);
		expect(
			mayAwaitAnswer({ parent: 0, content: { raw: 'Note to self' } })
		).toBe(false);
	});
});
