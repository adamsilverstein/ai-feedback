/**
 * WordPress dependencies
 */
import { describe, it, expect, vi } from 'vitest';

/**
 * Internal dependencies
 */
import { applyFixToContent } from '../quick-fix';
import { applyFixesToContent } from '../proofreading';
import { buildSuggestionContent } from '../suggestions';
import { readSource } from '../source-newlines';

// Rich text that drops the marker, so the marked reading never lines up
// with the plain one.
vi.mock('@wordpress/rich-text', async (importOriginal) => {
	const original = await importOriginal();
	return {
		...original,
		create: (options) =>
			original.create(
				typeof options?.html === 'string'
					? { ...options, html: options.html.replaceAll('', '') }
					: options
			),
	};
});

const FIX = { find: 'in order to', replace: 'to' };

describe('when the marked reading does not line up', () => {
	it('reads the HTML as plain rich text', () => {
		const { value, marker } = readSource('a\nb');
		expect(marker).toBeNull();
		expect(value.text).toBe('a\nb');
	});

	it('applies edits as before, with no marker left in the HTML', () => {
		const html = 'Revenue grew\nlast year in order to grow.';
		expect(applyFixToContent(html, FIX)).toBe(
			'Revenue grew<br>last year to grow.'
		);
		expect(applyFixesToContent(html, [FIX]).content).toBe(
			'Revenue grew<br>last year to grow.'
		);
		const suggested = buildSuggestionContent(html, FIX, { id: 7 });
		expect(suggested).not.toContain('');
		expect(suggested).toContain('Revenue grew<br>last year ');
	});
});
