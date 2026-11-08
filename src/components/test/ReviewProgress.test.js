/**
 * External dependencies
 */
import { act, render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';

/**
 * Internal dependencies
 */
import ReviewProgress from '../ReviewProgress';
import { STAGE_ANALYZING, STAGE_PREPARING } from '../../store/review-stages';

describe('ReviewProgress', () => {
	beforeEach(() => {
		jest.useFakeTimers();
	});

	afterEach(() => {
		jest.useRealTimers();
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

	it('advances the bar and shows elapsed time during a long stage', () => {
		render(<ReviewProgress stageKey={STAGE_ANALYZING} />);

		const bar = screen.getByRole('progressbar', {
			name: 'Review progress',
		});
		const initial = Number(bar.getAttribute('value'));

		act(() => {
			jest.advanceTimersByTime(10000);
		});

		expect(Number(bar.getAttribute('value'))).toBeGreaterThan(initial);
		expect(screen.getByText('10s elapsed')).toBeInTheDocument();
	});

	it('renders nothing for an unknown stage', () => {
		const { container } = render(<ReviewProgress stageKey={null} />);

		expect(container).toBeEmptyDOMElement();
	});
});
