/**
 * External dependencies
 */
import { render, screen, fireEvent, within } from '@testing-library/react';
import '@testing-library/jest-dom';

/**
 * Internal dependencies
 */
import FeedbackList from '../FeedbackList';
import { PriorityBadge } from '../FeedbackItem';

jest.mock('@wordpress/block-editor', () => ({
	store: 'core/block-editor',
}));
// The item actions are covered by FeedbackItem.test.js.
jest.mock('../FeedbackItemActions', () => () => null);

const items = [
	{
		id: 1,
		title: 'Late suggestion',
		content: { raw: 'Tweak spacing.' },
		category: 'design',
		severity: 'suggestion',
		priority: 30,
		block_index: 0,
	},
	{
		id: 2,
		title: 'Factual error',
		content: { raw: 'This is wrong.' },
		category: 'content',
		severity: 'critical',
		priority: 150,
		block_index: 4,
	},
];

describe('PriorityBadge', () => {
	it('shows a labelled badge for high priority', () => {
		render(<PriorityBadge priority={150} />);
		expect(
			screen.getByRole('img', { name: 'High priority' })
		).toHaveTextContent('!');
	});

	it('shows a labelled badge for medium priority', () => {
		render(<PriorityBadge priority={60} />);
		expect(
			screen.getByRole('img', { name: 'Medium priority' })
		).toBeInTheDocument();
	});

	it('renders nothing for low or missing priority', () => {
		const { container, rerender } = render(<PriorityBadge priority={10} />);
		expect(container).toBeEmptyDOMElement();
		rerender(<PriorityBadge />);
		expect(container).toBeEmptyDOMElement();
	});
});

describe('FeedbackList', () => {
	const renderedIds = () =>
		within(screen.getByRole('list', { name: 'Feedback items' }))
			.getAllByRole('listitem')
			.map((el) => el.textContent);

	it('lists the highest priority item first by default', () => {
		render(<FeedbackList feedbackItems={items} />);
		const rows = renderedIds();
		expect(rows[0]).toContain('Factual error');
		expect(rows[1]).toContain('Late suggestion');
	});

	it('re-sorts by document order when selected', () => {
		render(<FeedbackList feedbackItems={items} />);
		fireEvent.change(screen.getByLabelText('Sort by'), {
			target: { value: 'document' },
		});
		const rows = renderedIds();
		expect(rows[0]).toContain('Late suggestion');
		expect(rows[1]).toContain('Factual error');
	});

	it('renders nothing without items', () => {
		const { container } = render(<FeedbackList feedbackItems={[]} />);
		expect(container).toBeEmptyDOMElement();
	});
});
