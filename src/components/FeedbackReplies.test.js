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

	it('keeps showing existing replies while an AI reply is being generated', async () => {
		// Pending reply id ⇒ the hook will poll; apiFetch is mocked to never
		// resolve so the local status stays 'idle' then would flip to
		// 'pending' on first response. For this component test we just verify
		// the thread renders existing entries regardless.
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

		expect(screen.getByText('Clarify please.')).toBeInTheDocument();
	});
});
