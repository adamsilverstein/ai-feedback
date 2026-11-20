/**
 * Tests for the house style rule text area helpers.
 */
import { describe, expect, it } from 'vitest';
import { getRuleRows, toSingleLine } from '../RuleTextarea';

describe('getRuleRows', () => {
	it('grows with the rule and stops at ten rows', () => {
		expect(getRuleRows('')).toBe(2);
		expect(getRuleRows('a'.repeat(48))).toBe(3);
		expect(getRuleRows('a'.repeat(150))).toBe(8);
		expect(getRuleRows('a'.repeat(1000))).toBe(10);
	});
});

describe('toSingleLine', () => {
	it('turns line breaks into single spaces', () => {
		expect(
			toSingleLine('Use sentence case\n\n  in headings\r\nalways')
		).toBe('Use sentence case in headings always');
		expect(toSingleLine(undefined)).toBe('');
	});
});
