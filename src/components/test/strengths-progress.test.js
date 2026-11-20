/**
 * External dependencies
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { act, render, screen, fireEvent } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import { createRegistry, RegistryProvider } from '@wordpress/data';

/**
 * Internal dependencies
 */
import ReviewSummary from '../ReviewSummary';
import StatusAnnouncer, { PROGRESS_ANNOUNCE_DELAY } from '../StatusAnnouncer';
import { findStrengthBlock } from '../../utils/strengths';
import { navigateToBlock } from '../../utils/navigate-to-block';

const startReview = vi.fn();

vi.mock('@wordpress/a11y', () => ({ speak: vi.fn() }));
vi.mock('@wordpress/block-editor', () => ({ store: 'core/block-editor' }));
vi.mock('@wordpress/editor', () => ({ store: 'core/editor' }));
vi.mock('../FeedbackList', () => ({
	default: () => <div>Feedback list</div>,
}));
vi.mock('../../hooks/use-start-review', () => ({
	default: () => ({ startReview }),
}));
vi.mock('../../utils/navigate-to-block', () => ({
	navigateToBlock: vi.fn(),
}));
vi.mock('../../utils/strengths', async (importOriginal) => ({
	...(await importOriginal()),
	findStrengthBlock: vi.fn(),
}));

const notes = [
	{ id: 1, title: 'One' },
	{ id: 2, title: 'Two' },
];

const review = {
	review_id: 'r2',
	notes,
	note_count: 2,
	summary_text: 'A solid draft.',
	strengths: [
		{ block_id: 'b1', text: 'The opening example makes it concrete.' },
		{ block_id: 'b2', text: '<img src=x onerror=alert(1)> Clear steps.' },
	],
	progress: { addressed: 3, still_open: 1 },
};

/**
 * Render the summary with the stores its children read.
 *
 * @param {Object} props ReviewSummary props.
 * @return {Object} Render result.
 */
function renderSummary(props) {
	const registry = createRegistry();
	registry.registerStore('core/block-editor', {
		reducer: (state = {}) => state,
		selectors: { getGlobalBlockCount: () => 2 },
	});

	return render(
		<RegistryProvider value={registry}>
			<ReviewSummary {...props} />
		</RegistryProvider>
	);
}

describe('ReviewSummary strengths and progress', () => {
	beforeEach(() => {
		vi.spyOn(console, 'log').mockImplementation(() => {});
		startReview.mockClear();
		navigateToBlock.mockClear();
		findStrengthBlock.mockImplementation((strength) =>
			strength.block_id === 'b1' ? 'client-b1' : null
		);
	});

	it('lists what is working, linking the blocks it can find', () => {
		renderSummary({ review, progress: { addressed: 0, total: 2 } });

		expect(
			screen.getByRole('heading', { name: 'What’s working' })
		).toBeInTheDocument();

		fireEvent.click(
			screen.getByRole('button', {
				name: 'The opening example makes it concrete.',
			})
		);
		expect(navigateToBlock).toHaveBeenCalledWith('client-b1');

		// A block that can't be found is plain text, and AI text is never HTML.
		const text = screen.getByText(
			'<img src=x onerror=alert(1)> Clear steps.'
		);
		expect(text.tagName).toBe('SPAN');
		expect(document.querySelector('img')).toBeNull();
	});

	it('keeps strengths out of the feedback count', () => {
		renderSummary({ review, progress: { addressed: 0, total: 2 } });
		expect(screen.getByText('2 feedback items')).toBeInTheDocument();
	});

	it('shows nothing for a review without strengths', () => {
		renderSummary({
			review: { ...review, strengths: undefined },
			progress: { addressed: 0, total: 2 },
		});
		expect(screen.queryByText('What’s working')).toBeNull();
	});

	it('reports what changed since the last review', () => {
		renderSummary({ review, progress: { addressed: 0, total: 2 } });
		expect(
			screen.getByText(
				'Since the last review: 3 addressed, 2 new, 1 still open'
			)
		).toBeInTheDocument();
	});

	it('says nothing about a previous review on a first review', () => {
		renderSummary({
			review: { ...review, progress: null },
			progress: { addressed: 0, total: 2 },
		});
		expect(screen.queryByText(/Since the last review/)).toBeNull();
	});

	it('shows progress through the feedback', () => {
		renderSummary({ review, progress: { addressed: 1, total: 2 } });
		expect(screen.getByText('1 of 2 notes addressed')).toBeInTheDocument();
		expect(screen.getByText('Feedback list')).toBeInTheDocument();
	});

	it('hides progress while the review is still running', () => {
		renderSummary({
			review: { ...review, is_in_progress: true },
			progress: { addressed: 1, total: 2 },
		});
		expect(screen.queryByText('1 of 2 notes addressed')).toBeNull();
	});

	it('replaces the list with a done state once every note is addressed', () => {
		renderSummary({ review, progress: { addressed: 2, total: 2 } });

		expect(
			screen.getAllByText('All 2 notes from this review are addressed.')
		).not.toHaveLength(0);
		expect(screen.queryByText('Feedback list')).toBeNull();

		fireEvent.click(screen.getByRole('button', { name: 'Review again' }));
		expect(startReview).toHaveBeenCalledTimes(1);

		fireEvent.click(screen.getByRole('button', { name: 'Show feedback' }));
		expect(screen.getByText('Feedback list')).toBeInTheDocument();
		expect(
			screen.queryByRole('button', { name: 'Review again' })
		).toBeNull();
	});
});

describe('StatusAnnouncer progress', () => {
	const finished = { review_id: 'r2', notes };

	beforeEach(() => {
		vi.useFakeTimers();
		speak.mockClear();
	});

	afterEach(() => {
		vi.useRealTimers();
	});

	/**
	 * Render the announcer with progress counts.
	 *
	 * @param {Object} progress   Progress counts.
	 * @param {Object} lastReview Last review.
	 * @return {Object} Render result.
	 */
	const renderAnnouncer = (progress, lastReview = finished) =>
		render(
			<StatusAnnouncer
				isReviewing={false}
				lastReview={lastReview}
				error={null}
				progress={progress}
			/>
		);

	it('stays quiet for the counts a review loads with', () => {
		renderAnnouncer({ addressed: 1, total: 2 });
		act(() => vi.advanceTimersByTime(PROGRESS_ANNOUNCE_DELAY * 2));
		expect(speak).not.toHaveBeenCalled();
	});

	it('announces a note being addressed, after the dismissal notice', () => {
		const { rerender } = renderAnnouncer({ addressed: 0, total: 2 });
		rerender(
			<StatusAnnouncer
				isReviewing={false}
				lastReview={finished}
				error={null}
				progress={{ addressed: 1, total: 2 }}
			/>
		);
		expect(speak).not.toHaveBeenCalled();

		act(() => vi.advanceTimersByTime(PROGRESS_ANNOUNCE_DELAY));
		expect(speak).toHaveBeenCalledWith('1 of 2 notes addressed');

		rerender(
			<StatusAnnouncer
				isReviewing={false}
				lastReview={finished}
				error={null}
				progress={{ addressed: 2, total: 2 }}
			/>
		);
		act(() => vi.advanceTimersByTime(PROGRESS_ANNOUNCE_DELAY));
		expect(speak).toHaveBeenLastCalledWith(
			'All 2 notes from this review are addressed.'
		);
	});

	it('says nothing when a dismissal is undone right away', () => {
		const { rerender } = renderAnnouncer({ addressed: 0, total: 2 });
		const update = (addressed) =>
			rerender(
				<StatusAnnouncer
					isReviewing={false}
					lastReview={finished}
					error={null}
					progress={{ addressed, total: 2 }}
				/>
			);
		update(1);
		update(0);
		act(() => vi.advanceTimersByTime(PROGRESS_ANNOUNCE_DELAY * 2));
		expect(speak).not.toHaveBeenCalled();
	});

	it('does not announce the counts of a new review', () => {
		const { rerender } = renderAnnouncer({ addressed: 2, total: 2 });
		rerender(
			<StatusAnnouncer
				isReviewing={false}
				lastReview={{ review_id: 'r3', notes }}
				error={null}
				progress={{ addressed: 0, total: 2 }}
			/>
		);
		act(() => vi.advanceTimersByTime(PROGRESS_ANNOUNCE_DELAY * 2));
		expect(speak).not.toHaveBeenCalled();
	});
});
