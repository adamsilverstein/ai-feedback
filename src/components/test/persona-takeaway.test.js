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
	summary_text: 'Two claims need a source.',
};

describe('ReviewSummary persona takeaway', () => {
	beforeEach(() => {
		vi.spyOn(console, 'log').mockImplementation(() => {});
	});

	it('leads the summary with the persona takeaway', () => {
		render(
			<ReviewSummary
				review={{
					...review,
					persona: 'skeptical_expert',
					persona_label: 'Skeptical expert',
					persona_takeaway:
						'Not convinced until the benchmark is linked.',
				}}
			/>
		);

		const takeaway = screen
			.getByText('Not convinced until the benchmark is linked.')
			.closest('p');
		expect(takeaway).toHaveTextContent(
			'Skeptical expert: Not convinced until the benchmark is linked.'
		);
		expect(screen.getByText('Two claims need a source.')).toBeVisible();
	});

	it('shows the takeaway when the summary is empty', () => {
		render(
			<ReviewSummary
				review={{
					...review,
					summary_text: '',
					persona_label: 'Busy skimmer',
					persona_takeaway: 'Got it by the second heading.',
				}}
			/>
		);

		expect(
			screen.getByText('Got it by the second heading.')
		).toBeInTheDocument();
	});

	it('shows no takeaway for a default review', () => {
		render(<ReviewSummary review={review} />);

		expect(screen.getByText('Two claims need a source.')).toBeVisible();
		expect(screen.queryByText(/:$/)).not.toBeInTheDocument();
	});
});
