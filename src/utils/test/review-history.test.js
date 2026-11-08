/**
 * Internal dependencies
 */
import { describeChange, formatNoteCount } from '../review-history';

describe('describeChange', () => {
	it('returns nothing for the oldest review', () => {
		expect(describeChange(3, undefined)).toBe('');
	});

	it('describes no change', () => {
		expect(describeChange(3, 3)).toBe('Same as previous review');
	});

	it('describes fewer items', () => {
		expect(describeChange(1, 3)).toBe('2 fewer items than previous review');
		expect(describeChange(2, 3)).toBe('1 fewer item than previous review');
	});

	it('describes more items', () => {
		expect(describeChange(5, 3)).toBe('2 more items than previous review');
	});
});

describe('formatNoteCount', () => {
	it('pluralizes', () => {
		expect(formatNoteCount(1)).toBe('1 feedback item');
		expect(formatNoteCount(4)).toBe('4 feedback items');
	});
});
