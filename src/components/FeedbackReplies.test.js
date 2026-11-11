/**
 * Tests for FeedbackReplies component.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { speak } from '@wordpress/a11y';

// Drive the polling hook's status directly.
let mockStatus = 'pending';
vi.mock('../hooks/useReplyStatus', () => ({
	default: () => ({ status: mockStatus }),
}));

vi.mock('@wordpress/a11y', () => ({ speak: vi.fn() }));

// Mock @wordpress/components Spinner to a plain element — avoids pulling the
// full WP components bundle into the test runtime.
vi.mock('@wordpress/components', () => ({
	Spinner: () => <span data-testid="wp-spinner" />,
}));

import FeedbackReplies from './FeedbackReplies';

describe('FeedbackReplies', () => {
	beforeEach(() => {
		mockStatus = 'pending';
		speak.mockClear();
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
						author: 'AI Feedback',
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
			author: 'AI Feedback',
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
						author: 'AI Feedback',
						content: 'The 40% growth claim.',
						is_ai: true,
					},
				]}
			/>
		);

		expect(speak).toHaveBeenCalledTimes(1);
		expect(speak).toHaveBeenCalledWith(
			'AI Feedback replied: The 40% growth claim.'
		);
	});

	it('does not read out replies that load with the sidebar', () => {
		const { rerender } = render(<FeedbackReplies replies={[]} />);

		rerender(
			<FeedbackReplies
				replies={[
					{
						id: 2,
						author: 'AI Feedback',
						content: 'Already here.',
						is_ai: true,
					},
				]}
			/>
		);

		expect(speak).not.toHaveBeenCalled();
	});
});
