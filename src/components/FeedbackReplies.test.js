/**
 * Tests for FeedbackReplies component.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { speak } from '@wordpress/a11y';

// Drive the polling hook's status directly.
let mockStatus = 'pending';
let mockIsSlow = false;
let mockCanRetry = true;
const mockRetry = vi.fn();
vi.mock('../hooks/useReplyStatus', () => ({
	default: () => ({
		status: mockStatus,
		isSlow: mockIsSlow,
		canRetry: mockCanRetry,
		retry: mockRetry,
	}),
}));

vi.mock('@wordpress/a11y', () => ({ speak: vi.fn() }));

// Mock @wordpress/components Spinner to a plain element — avoids pulling the
// full WP components bundle into the test runtime.
vi.mock('@wordpress/components', () => ({
	Spinner: () => <span data-testid="wp-spinner" />,
	Button: ({ children, onClick, disabled }) => (
		<button type="button" onClick={onClick} disabled={disabled}>
			{children}
		</button>
	),
}));

import FeedbackReplies from './FeedbackReplies';

describe('FeedbackReplies', () => {
	beforeEach(() => {
		mockStatus = 'pending';
		mockIsSlow = false;
		mockCanRetry = true;
		mockRetry.mockClear();
		speak.mockClear();
	});

	it('offers a retry when the reply is taking longer than expected', () => {
		mockIsSlow = true;
		render(<FeedbackReplies replies={[]} pendingReplyId={42} />);

		expect(screen.getByTestId('wp-spinner')).toBeInTheDocument();
		expect(
			screen.getByText('This is taking longer than expected.')
		).toBeInTheDocument();

		fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
		expect(mockRetry).toHaveBeenCalledTimes(1);
	});

	it('does not offer a retry while the reply is on time', () => {
		render(<FeedbackReplies replies={[]} pendingReplyId={42} />);

		expect(
			screen.queryByRole('button', { name: 'Try again' })
		).not.toBeInTheDocument();
	});

	it('offers a retry when generation fails', () => {
		mockStatus = 'failed';
		render(<FeedbackReplies replies={[]} pendingReplyId={42} />);

		fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
		expect(mockRetry).toHaveBeenCalledTimes(1);
	});

	it('disables the retry during the server cooldown', () => {
		mockStatus = 'failed';
		mockCanRetry = false;
		render(<FeedbackReplies replies={[]} pendingReplyId={42} />);

		const button = screen.getByRole('button', { name: 'Try again' });
		expect(button).toBeDisabled();
		expect(
			screen.getByText('Try again in a few seconds.')
		).toBeInTheDocument();

		fireEvent.click(button);
		expect(mockRetry).not.toHaveBeenCalled();
	});

	it('shows no cooldown hint once a retry is allowed', () => {
		mockStatus = 'failed';
		render(<FeedbackReplies replies={[]} pendingReplyId={42} />);

		expect(
			screen.queryByText('Try again in a few seconds.')
		).not.toBeInTheDocument();
	});

	it('renders nothing when there are no replies and no pending reply', () => {
		const { container } = render(<FeedbackReplies replies={[]} />);
		expect(container.firstChild).toBeNull();
	});

	it('renders existing replies with AI vs user distinction', () => {
		render(
			<FeedbackReplies
				replies={[
					{
						id: 1,
						author: 'Jane',
						content: 'Which stat?',
						is_ai: false,
					},
					{
						id: 2,
						author: 'Copydesk AI',
						content: 'The 40% growth claim.',
						is_ai: true,
					},
				]}
			/>
		);

		const items = screen.getAllByRole('listitem');
		expect(items).toHaveLength(2);
		expect(items[0]).toHaveClass('ai-feedback-reply-user');
		expect(items[1]).toHaveClass('ai-feedback-reply-ai');
		expect(screen.getByText('Which stat?')).toBeInTheDocument();
		expect(screen.getByText('The 40% growth claim.')).toBeInTheDocument();
	});

	it('shows the pending spinner immediately when a reply is being generated', () => {
		render(
			<FeedbackReplies
				replies={[
					{
						id: 1,
						author: 'Jane',
						content: 'Clarify please.',
						is_ai: false,
					},
				]}
				pendingReplyId={42}
			/>
		);

		// Existing replies still render alongside the pending row.
		expect(screen.getByText('Clarify please.')).toBeInTheDocument();

		// Spinner is visible synchronously — no first-poll gap.
		expect(screen.getByTestId('wp-spinner')).toBeInTheDocument();
		expect(screen.getByText('AI is replying…')).toBeInTheDocument();
	});

	it('hides the spinner and reports completion once the reply is saved', () => {
		const onReplyComplete = vi.fn();
		const { rerender } = render(
			<FeedbackReplies
				replies={[]}
				pendingReplyId={42}
				onReplyComplete={onReplyComplete}
			/>
		);
		expect(screen.getByTestId('wp-spinner')).toBeInTheDocument();

		mockStatus = 'complete';
		rerender(
			<FeedbackReplies
				replies={[]}
				pendingReplyId={42}
				onReplyComplete={onReplyComplete}
			/>
		);

		expect(screen.queryByTestId('wp-spinner')).not.toBeInTheDocument();
		expect(onReplyComplete).toHaveBeenCalledTimes(1);
	});

	it('shows an error in the live region when generation fails', () => {
		mockStatus = 'failed';
		render(<FeedbackReplies replies={[]} pendingReplyId={42} />);

		expect(screen.queryByTestId('wp-spinner')).not.toBeInTheDocument();
		const row = screen.getByRole('listitem');
		expect(row).toHaveAttribute('aria-live', 'polite');
		expect(row).toHaveTextContent('The AI reply could not be generated.');
	});

	it('announces AI replies that arrive after the first render', () => {
		const userReply = {
			id: 1,
			author: 'Jane',
			content: 'Which stat?',
			is_ai: false,
		};
		const existingAiReply = {
			id: 2,
			author: 'Copydesk AI',
			content: 'Already here.',
			is_ai: true,
		};
		const { rerender } = render(
			<FeedbackReplies
				replies={[userReply, existingAiReply]}
				pendingReplyId={1}
			/>
		);
		expect(speak).not.toHaveBeenCalled();

		rerender(
			<FeedbackReplies
				replies={[
					userReply,
					existingAiReply,
					{
						id: 3,
						author: 'Copydesk AI',
						content: 'The 40% growth claim.',
						is_ai: true,
					},
				]}
			/>
		);

		expect(speak).toHaveBeenCalledTimes(1);
		expect(speak).toHaveBeenCalledWith(
			'Copydesk AI replied: The 40% growth claim.'
		);
	});

	it('does not read out replies that load with the sidebar', () => {
		const { rerender } = render(<FeedbackReplies replies={[]} />);

		rerender(
			<FeedbackReplies
				replies={[
					{
						id: 2,
						author: 'Copydesk AI',
						content: 'Already here.',
						is_ai: true,
					},
				]}
			/>
		);

		expect(speak).not.toHaveBeenCalled();
	});
});
