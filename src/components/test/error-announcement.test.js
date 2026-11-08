/**
 * External dependencies
 */
import { render } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies
 */
import ErrorNotice from '../ErrorNotice';
import StatusAnnouncer from '../StatusAnnouncer';

jest.mock('@wordpress/a11y', () => ({ speak: jest.fn() }));

const error = {
	code: 'ai_request_failed',
	message: 'The AI provider is currently unavailable.',
	data: { status: 500 },
};

describe('review error announcements', () => {
	beforeEach(() => {
		speak.mockClear();
	});

	it('announces the error once, prefixed with "Error:"', () => {
		render(
			<>
				<StatusAnnouncer
					isReviewing={false}
					lastReview={null}
					error={error}
				/>
				<ErrorNotice error={error} onRemove={() => {}} />
			</>
		);

		expect(speak).toHaveBeenCalledTimes(1);
		expect(speak).toHaveBeenCalledWith(
			'Error: The AI provider is currently unavailable.',
			'assertive'
		);
	});

	it('still announces review progress and completion', () => {
		const { rerender } = render(
			<StatusAnnouncer
				isReviewing={true}
				lastReview={null}
				error={null}
			/>
		);
		expect(speak).toHaveBeenLastCalledWith(
			'Review in progress. Please wait.'
		);

		rerender(
			<StatusAnnouncer
				isReviewing={false}
				lastReview={{ notes: [{ id: 1 }] }}
				error={null}
			/>
		);
		expect(speak).toHaveBeenLastCalledWith(
			'Review complete. 1 notes created.'
		);
	});
});
