/**
 * Internal dependencies
 */
import { describe, it, expect } from 'vitest';
import { toRows, csvCell, generateDailyCsv } from '../utils';

describe('toRows', () => {
	it('sorts by count, largest first', () => {
		expect(toRows({ a: 1, b: 3 })).toEqual([
			{ label: 'b', count: 3 },
			{ label: 'a', count: 1 },
		]);
	});
});

describe('csvCell', () => {
	it('quotes cells with commas and quotes', () => {
		expect(csvCell('a,"b"')).toBe('"a,""b"""');
	});

	it('neutralizes formula-like cells', () => {
		expect(csvCell('=SUM(A1)')).toBe("'=SUM(A1)");
	});
});

describe('generateDailyCsv', () => {
	it('writes a header and one row per day', () => {
		expect(
			generateDailyCsv([{ date: '2026-11-08', reviews: 2, feedback: 5 }])
		).toBe('date,reviews,feedback\n2026-11-08,2,5\n');
	});
});
