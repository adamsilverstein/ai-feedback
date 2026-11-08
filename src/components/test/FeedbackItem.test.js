/**
 * External dependencies
 */
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
} from '@testing-library/react';
import '@testing-library/jest-dom';

/**
 * WordPress dependencies
 */
import { createRegistry, RegistryProvider } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import FeedbackItem from '../FeedbackItem';

jest.mock('@wordpress/block-editor', () => ({ store: 'core/block-editor' }));
jest.mock('../../store', () => ({ STORE_NAME: 'ai-feedback/store' }));

const item = {
	id: 7,
	block_id: 'block-1',
	severity: 'important',
	title: 'Passive voice',
	feedback: 'This sentence is passive.',
	suggestion: 'Make it active.',
	is_resolved: false,
};

function renderItem(props, setNoteResolved = jest.fn()) {
	const registry = createRegistry();
	registry.register(noticesStore);
	registry.registerStore('ai-feedback/store', {
		reducer: (state = {}) => state,
		actions: {
			setNoteResolved: (...args) => {
				setNoteResolved(...args);
				return { type: 'NOOP' };
			},
		},
		selectors: {},
	});
	registry.registerStore('core/block-editor', {
		reducer: (state = {}) => state,
		actions: { selectBlock: () => ({ type: 'NOOP' }) },
		selectors: {},
	});

	render(
		<RegistryProvider value={registry}>
			<FeedbackItem item={{ ...item, ...props }} />
		</RegistryProvider>
	);

	return registry;
}

describe('FeedbackItem quick actions', () => {
	it('shows the feedback text and actions', () => {
		renderItem();

		expect(screen.getByText('Passive voice')).toBeInTheDocument();
		expect(screen.getByText('This sentence is passive.')).toBeVisible();
		expect(
			screen.getByRole('button', { name: 'Go to block' })
		).toBeInTheDocument();
		expect(
			screen.getByRole('button', {
				name: 'Dismiss feedback: Passive voice',
			})
		).toBeInTheDocument();
	});

	it('dismisses the item and offers undo', async () => {
		const setNoteResolved = jest.fn();
		const registry = renderItem({}, setNoteResolved);

		await act(async () => {
			fireEvent.click(
				screen.getByRole('button', {
					name: 'Dismiss feedback: Passive voice',
				})
			);
		});

		expect(setNoteResolved).toHaveBeenCalledWith(7, true);

		await waitFor(() =>
			expect(registry.select(noticesStore).getNotices()).toHaveLength(1)
		);
		const [notice] = registry.select(noticesStore).getNotices();
		expect(notice.content).toBe('Feedback dismissed.');

		await act(async () => {
			notice.actions[0].onClick();
		});
		expect(setNoteResolved).toHaveBeenLastCalledWith(7, false);
	});

	it('shows dismissed items collapsed with a restore action', async () => {
		const setNoteResolved = jest.fn();
		renderItem({ is_resolved: true }, setNoteResolved);

		expect(screen.getByText('Dismissed')).toBeInTheDocument();
		expect(
			screen.queryByText('This sentence is passive.')
		).not.toBeInTheDocument();

		await act(async () => {
			fireEvent.click(
				screen.getByRole('button', {
					name: 'Restore feedback: Passive voice',
				})
			);
		});
		expect(setNoteResolved).toHaveBeenCalledWith(7, false);
	});

	it('hides dismiss for items without a note ID', () => {
		renderItem({ id: undefined });

		expect(
			screen.queryByRole('button', { name: /Dismiss/ })
		).not.toBeInTheDocument();
	});
});
