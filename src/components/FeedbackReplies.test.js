// @jest-environment jsdom
/* eslint-env jest */
/**
 * Tests for FeedbackReplies component.
 */
import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';

// Mock apiFetch so the polling hook never hits the network.
jest.mock('@wordpress/api-fetch', () => jest.fn(() => new Promise(() => {})));

// Mock @wordpress/components Spinner to a plain element — avoids pulling the
// full WP components bundle into the test runtime.
jest.mock('@wordpress/components', () => ({
	Spinner: () => <span data-testid="wp-spinner" />,
}));

import FeedbackReplies from './FeedbackReplies';

describe('FeedbackReplies', () => {
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
});
