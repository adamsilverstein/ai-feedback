/**
 * External dependencies
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import ReviewSummary from '../ReviewSummary';

vi.mock('../FeedbackList', () => ({ default: () => null }));
vi.mock('../ModelName', () => ({ default: () => null }));

const review = {
	review_id: 'review-1',
	notes: [],
	note_count: 0,
	summary_text: 'One image needs alt text.',
};

describe('ReviewSummary skipped images', () => {
	beforeEach(() => {
		vi.spyOn(console, 'log').mockImplementation(() => {});
	});

	it('says which images the AI was not shown and why', () => {
		render(
			<ReviewSummary
				review={{
					...review,
					images_skipped: [{ block_id: 'gif', reason: 'type' }],
				}}
			/>
		);

		expect(
			screen.getByText(
				/1 image was not shown to the AI: the AI provider does not take its file type/
			)
		).toBeVisible();
	});

	it('shows nothing extra when every image was sent', () => {
		const { container } = render(<ReviewSummary review={review} />);

		expect(
			container.querySelector('.ai-feedback-images-skipped')
		).toBeNull();
	});
});
