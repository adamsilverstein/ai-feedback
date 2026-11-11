/**
 * Internal dependencies
 */
import { describe, it, expect, afterEach } from 'vitest';
import {
	SORT_CATEGORY,
	SORT_DOCUMENT,
	SORT_PRIORITY,
	getPriorityLevel,
	getPriorityThresholds,
	sortFeedback,
} from '../priority';

describe('getPriorityThresholds', () => {
	afterEach(() => {
		delete window.aiFeedbackData;
	});

	it('falls back to defaults when nothing is localized', () => {
		expect(getPriorityThresholds()).toEqual({ high: 100, medium: 50 });
	});

	it('uses the thresholds localized from PHP', () => {
		window.aiFeedbackData = {
			priorityThresholds: { high: 120, medium: '60' },
		};
		expect(getPriorityThresholds()).toEqual({ high: 120, medium: 60 });
	});

	it('ignores non-numeric localized values', () => {
		window.aiFeedbackData = {
			priorityThresholds: { high: 'abc', medium: undefined },
		};
		expect(getPriorityThresholds()).toEqual({ high: 100, medium: 50 });
	});
});

describe('getPriorityLevel', () => {
	it.each([
		[150, 'high'],
		[100, 'high'],
		[99, 'medium'],
		[50, 'medium'],
		[49, 'low'],
		[0, 'low'],
		[NaN, 'low'],
		['120', 'low'],
		[undefined, 'low'],
	])('buckets %p as %s', (score, level) => {
		expect(getPriorityLevel(score)).toBe(level);
	});

	it('respects localized thresholds', () => {
		window.aiFeedbackData = {
			priorityThresholds: { high: 200, medium: 150 },
		};
		expect(getPriorityLevel(160)).toBe('medium');
		delete window.aiFeedbackData;
	});
});

describe('sortFeedback', () => {
	const items = [
		{ id: 'a', priority: 40, block_index: 0, category: 'tone' },
		{ id: 'b', priority: 140, block_index: 3, category: 'content' },
		{ id: 'c', priority: 0, block_index: 1, category: 'content' },
		{ id: 'd', priority: 140, block_index: 2, category: 'design' },
		{ id: 'e', is_group: true, block_indexes: [5, 4], category: 'flow' },
	];
	const ids = (list) => list.map((item) => item.id);

	it('sorts by priority, breaking ties by document position', () => {
		expect(ids(sortFeedback(items, SORT_PRIORITY))).toEqual([
			'd',
			'b',
			'a',
			'c',
			'e',
		]);
	});

	it('defaults to priority order for unknown sort keys', () => {
		expect(ids(sortFeedback(items, 'nope'))).toEqual(
			ids(sortFeedback(items, SORT_PRIORITY))
		);
	});

	it('sorts by document order, using the earliest block of a group', () => {
		expect(ids(sortFeedback(items, SORT_DOCUMENT))).toEqual([
			'a',
			'c',
			'd',
			'b',
			'e',
		]);
	});

	it('sorts by category, then priority', () => {
		expect(ids(sortFeedback(items, SORT_CATEGORY))).toEqual([
			'b',
			'c',
			'd',
			'e',
			'a',
		]);
	});

	it('does not mutate the input', () => {
		const copy = [...items];
		sortFeedback(items, SORT_DOCUMENT);
		expect(items).toEqual(copy);
	});
});
