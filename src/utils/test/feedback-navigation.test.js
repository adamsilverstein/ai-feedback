/**
 * Internal dependencies
 */
import { describe, it, expect } from 'vitest';
import {
	getAdjacentFeedbackBlock,
	getFeedbackBlockIds,
} from '../feedback-navigation';

describe('getFeedbackBlockIds', () => {
	it('collects block IDs from single and grouped notes', () => {
		const ids = getFeedbackBlockIds([
			{ block_id: 'a' },
			{ block_id: 'b', is_group: true, block_ids: ['b', 'c'] },
			{ block_id: '' },
		]);

		expect([...ids].sort()).toEqual(['a', 'b', 'c']);
	});

	it('handles missing notes', () => {
		expect(getFeedbackBlockIds(undefined).size).toBe(0);
	});
});

describe('getAdjacentFeedbackBlock', () => {
	const ordered = ['a', 'b', 'c', 'd', 'e'];
	const feedback = new Set(['b', 'd']);

	it.each([
		// [currentId, direction, expected]
		[null, 1, 'b'],
		[null, -1, 'd'],
		['a', 1, 'b'],
		['b', 1, 'd'],
		['c', 1, 'd'],
		['d', 1, 'b'],
		['e', -1, 'd'],
		['d', -1, 'b'],
		['b', -1, 'd'],
		['c', -1, 'b'],
	])('from %s in direction %d goes to %s', (current, direction, expected) => {
		expect(
			getAdjacentFeedbackBlock(ordered, feedback, current, direction)
		).toBe(expected);
	});

	it('returns null when no block has feedback', () => {
		expect(getAdjacentFeedbackBlock(ordered, new Set(), 'a', 1)).toBeNull();
	});

	it('ignores feedback for blocks no longer in the document', () => {
		expect(
			getAdjacentFeedbackBlock(ordered, new Set(['gone']), null, 1)
		).toBeNull();
	});
});
