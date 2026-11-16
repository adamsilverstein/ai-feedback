/**
 * External dependencies
 */
import { describe, it, expect } from 'vitest';
import { act, renderHook } from '@testing-library/react';

/**
 * Internal dependencies
 */
import useSettingsOpen from '../use-settings-open';

const review = { review_id: 'r1' };

function setup(initialProps) {
	return renderHook(
		({ lastReview, isReviewing }) =>
			useSettingsOpen(lastReview, isReviewing),
		{ initialProps }
	);
}

describe('useSettingsOpen', () => {
	it('starts open before the first review', () => {
		const { result } = setup({ lastReview: null, isReviewing: false });
		expect(result.current[0]).toBe(true);
	});

	it('starts collapsed when the post has a review', () => {
		const { result } = setup({ lastReview: review, isReviewing: false });
		expect(result.current[0]).toBe(false);
	});

	it('collapses when a saved review loads late', () => {
		const { result, rerender } = setup({
			lastReview: null,
			isReviewing: false,
		});
		rerender({ lastReview: review, isReviewing: false });
		expect(result.current[0]).toBe(false);
	});

	it('collapses when a review starts and stays closed after it', () => {
		const { result, rerender } = setup({
			lastReview: null,
			isReviewing: false,
		});
		rerender({ lastReview: null, isReviewing: true });
		expect(result.current[0]).toBe(false);
		rerender({ lastReview: review, isReviewing: false });
		expect(result.current[0]).toBe(false);
	});

	it('can be reopened while a review runs', () => {
		const { result, rerender } = setup({
			lastReview: review,
			isReviewing: false,
		});
		rerender({ lastReview: review, isReviewing: true });
		act(() => result.current[1](true));
		rerender({ lastReview: review, isReviewing: true });
		expect(result.current[0]).toBe(true);
	});

	it('reopens when a first review fails', () => {
		const { result, rerender } = setup({
			lastReview: null,
			isReviewing: false,
		});
		rerender({ lastReview: null, isReviewing: true });
		rerender({ lastReview: null, isReviewing: false });
		expect(result.current[0]).toBe(true);
	});

	it('stays collapsed when a later review fails', () => {
		const { result, rerender } = setup({
			lastReview: review,
			isReviewing: false,
		});
		rerender({ lastReview: review, isReviewing: true });
		rerender({ lastReview: review, isReviewing: false });
		expect(result.current[0]).toBe(false);
	});
});
