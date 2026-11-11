/**
 * External dependencies
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { act, render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import ReviewProgress from '../ReviewProgress';
import { STAGE_ANALYZING, STAGE_PREPARING } from '../../store/review-stages';

describe('ReviewProgress', () => {
	beforeEach(() => {
		vi.useFakeTimers();
	});

	afterEach(() => {
		vi.useRealTimers();
	});

	it('shows the current step and label', () => {
		render(<ReviewProgress stageKey={STAGE_ANALYZING} />);

		expect(screen.getByText('Step 2 of 3')).toBeInTheDocument();
		expect(
			screen.getByText(/AI is analyzing your content/)
		).toBeInTheDocument();
	});

	it('renders a determinate progress bar', () => {
		render(<ReviewProgress stageKey={STAGE_PREPARING} />);

		const bar = screen.getByRole('progressbar', {
			name: 'Review progress',
		});
		expect(bar).toHaveAttribute('value', '5');
	});

	it('spreads the steps over every part of a chunked review', () => {
		render(
			<ReviewProgress
				stageKey={STAGE_ANALYZING}
				chunk={{ current: 2, total: 4 }}
			/>
		);

		expect(screen.getByText('Reviewing part 2 of 4.')).toBeInTheDocument();

		// Part 2 of 4 starts at 25%, and analyzing starts 20% into a part.
		const bar = screen.getByRole('progressbar', {
			name: 'Review progress',
		});
		expect(bar).toHaveAttribute('value', '30');
	});

	it('advances the bar and shows elapsed time during a long stage', () => {
		render(<ReviewProgress stageKey={STAGE_ANALYZING} />);

		const bar = screen.getByRole('progressbar', {
			name: 'Review progress',
		});
		const initial = Number(bar.getAttribute('value'));

		act(() => {
			vi.advanceTimersByTime(10000);
		});

		expect(Number(bar.getAttribute('value'))).toBeGreaterThan(initial);
		expect(screen.getByText('10s elapsed')).toBeInTheDocument();
	});

	it('keeps counting total time across stage changes', () => {
		const { rerender } = render(
			<ReviewProgress stageKey={STAGE_PREPARING} />
		);

		act(() => {
			vi.advanceTimersByTime(3000);
		});
		rerender(<ReviewProgress stageKey={STAGE_ANALYZING} />);
		act(() => {
			vi.advanceTimersByTime(4000);
		});

		expect(screen.getByText('7s elapsed')).toBeInTheDocument();
		expect(screen.getByText('Step 2 of 3')).toBeInTheDocument();
	});

	it('renders nothing for an unknown stage', () => {
		const { container } = render(<ReviewProgress stageKey={null} />);

		expect(container).toBeEmptyDOMElement();
	});
});
