/**
 * External dependencies
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import { createRegistry, RegistryProvider } from '@wordpress/data';

/**
 * Internal dependencies
 */
import ExportReview from '../ExportReview';
import ReviewSummary from '../ReviewSummary';
import StatusAnnouncer from '../StatusAnnouncer';

vi.mock('@wordpress/a11y', () => ({ speak: vi.fn() }));
vi.mock('@wordpress/editor', () => ({ store: 'core/editor' }));
vi.mock('@wordpress/notices', () => ({ store: 'core/notices' }));

const review = {
	review_id: 'review-1',
	notes: [],
	note_count: 0,
	summary_text: 'Looks good.',
};

/**
 * Render the export menu with the stores it reads.
 *
 * @param {Object} props ExportReview props.
 * @return {Object} Render result.
 */
function renderExport(props) {
	const registry = createRegistry();
	registry.registerStore('core/editor', {
		reducer: (state = {}) => state,
		selectors: { getEditedPostAttribute: () => 'Title' },
	});
	registry.registerStore('core/notices', {
		reducer: (state = {}) => state,
		actions: {
			createSuccessNotice: () => ({ type: 'NOOP' }),
			createErrorNotice: () => ({ type: 'NOOP' }),
		},
	});

	return render(
		<RegistryProvider value={registry}>
			<ExportReview {...props} />
		</RegistryProvider>
	);
}

describe('ExportReview', () => {
	it('can export a finished review', () => {
		renderExport({ review });
		expect(
			screen.getByRole('button', { name: 'Export review' })
		).toBeInTheDocument();
	});

	it.each([
		['still running', { is_in_progress: true }],
		['stopped early', { is_partial: true }],
	])('is hidden while the review is %s', (_, flags) => {
		renderExport({ review: { ...review, ...flags } });
		expect(
			screen.queryByRole('button', { name: 'Export review' })
		).not.toBeInTheDocument();
	});
});

describe('ReviewSummary status', () => {
	beforeEach(() => {
		vi.spyOn(console, 'log').mockImplementation(() => {});
	});

	it('says nothing extra for a finished review', () => {
		render(<ReviewSummary review={review} />);
		expect(screen.queryByText(/Review in progress/)).toBeNull();
		expect(screen.queryByText(/did not finish/)).toBeNull();
	});

	it('marks a review that is still running', () => {
		render(<ReviewSummary review={{ ...review, is_in_progress: true }} />);
		expect(
			screen.getByText(
				'Review in progress. Showing feedback from the parts finished so far.'
			)
		).toBeInTheDocument();
	});

	it('marks a review that stopped early', () => {
		render(<ReviewSummary review={{ ...review, is_partial: true }} />);
		expect(screen.getByText(/This review did not finish/)).toBeVisible();
	});
});

describe('StatusAnnouncer with a multi-part review', () => {
	beforeEach(() => {
		speak.mockClear();
	});

	it('waits for the last part before announcing completion', () => {
		const { rerender } = render(
			<StatusAnnouncer isReviewing lastReview={null} error={null} />
		);

		rerender(
			<StatusAnnouncer
				isReviewing
				lastReview={{ notes: [{ id: 1 }], is_in_progress: true }}
				error={null}
			/>
		);
		expect(speak).toHaveBeenCalledTimes(1);
		expect(speak).toHaveBeenLastCalledWith(
			'Review in progress. Please wait.'
		);

		rerender(
			<StatusAnnouncer
				isReviewing={false}
				lastReview={{ notes: [{ id: 1 }, { id: 2 }] }}
				error={null}
			/>
		);
		expect(speak).toHaveBeenLastCalledWith(
			'Review complete. 2 notes created.'
		);
	});

	it('does not call a review that stopped early complete', () => {
		const { rerender } = render(
			<StatusAnnouncer isReviewing lastReview={null} error={null} />
		);

		// The partial review can land a render before the error does.
		rerender(
			<StatusAnnouncer
				isReviewing={false}
				lastReview={{ notes: [{ id: 1 }], is_partial: true }}
				error={null}
			/>
		);
		rerender(
			<StatusAnnouncer
				isReviewing={false}
				lastReview={{ notes: [{ id: 1 }], is_partial: true }}
				error={{ code: 'unknown_error', message: 'Down' }}
			/>
		);

		expect(speak).toHaveBeenCalledTimes(1);
		expect(speak).not.toHaveBeenCalledWith(
			expect.stringContaining('Review complete')
		);
	});
});
