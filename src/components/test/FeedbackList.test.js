/**
 * External dependencies
 */
import { render, screen, fireEvent, within } from '@testing-library/react';
import '@testing-library/jest-dom';

/**
 * WordPress dependencies
 */
import { createRegistry, RegistryProvider } from '@wordpress/data';

/**
 * Internal dependencies
 */
import FeedbackList from '../FeedbackList';
import { PriorityBadge } from '../FeedbackItem';

jest.mock('@wordpress/block-editor', () => ({
	store: 'core/block-editor',
}));
jest.mock('../../store', () => ({ STORE_NAME: 'ai-feedback/store' }));
// The item actions are covered by FeedbackItem.test.js.
jest.mock('../FeedbackItemActions', () => () => null);

/**
 * Render the list with a registry that has the feedback store.
 *
 * @param {Array} feedbackItems Feedback items.
 * @return {Object} Render result.
 */
function renderList(feedbackItems) {
	const registry = createRegistry();
	registry.registerStore('ai-feedback/store', {
		reducer: (state = {}) => state,
		selectors: { isFixApplied: () => false },
	});

	return render(
		<RegistryProvider value={registry}>
			<FeedbackList feedbackItems={feedbackItems} />
		</RegistryProvider>
	);
}

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
		renderList(items);
		const rows = renderedIds();
		expect(rows[0]).toContain('Factual error');
		expect(rows[1]).toContain('Late suggestion');
	});

	it('re-sorts by document order when selected', () => {
		renderList(items);
		fireEvent.change(screen.getByLabelText('Sort by'), {
			target: { value: 'document' },
		});
		const rows = renderedIds();
		expect(rows[0]).toContain('Late suggestion');
		expect(rows[1]).toContain('Factual error');
	});

	it('renders nothing without items', () => {
		const { container } = renderList([]);
		expect(container).toBeEmptyDOMElement();
	});
});
