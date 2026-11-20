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

// Shared with tests/php/MentionsTest.php, so both sides agree.
const CASES = require('../../../tests/fixtures/mention-cases.json');

describe('mentionsFreshEyes', () => {
	it.each(CASES.mentions)('finds a mention in %s', (content) => {
		expect(mentionsFreshEyes(content)).toBe(true);
	});

	it.each([...CASES.others, undefined])(
		'finds no mention in %s',
		(content) => {
			expect(mentionsFreshEyes(content)).toBe(false);
		}
	);

	it('matches handles the server adds through its filter', () => {
		window.aiFeedbackData = { mentionHandles: ['ojos frescos'] };
		try {
			expect(mentionsFreshEyes('@ojos-frescos simplifica')).toBe(true);
			expect(mentionsFreshEyes('@fresh-eyes simplify')).toBe(false);
		} finally {
			delete window.aiFeedbackData;
		}
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
