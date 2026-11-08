/**
 * External dependencies
 */
import { render, screen, fireEvent, act } from '@testing-library/react';
import '@testing-library/jest-dom';

/**
 * Internal dependencies
 */
import ServiceUnavailableAction, {
	MAX_AUTO_RETRIES,
} from '../ServiceUnavailableAction';

const error = {
	code: 'ai_service_unavailable',
	message: 'The AI service is temporarily unavailable.',
	data: { status: 503, retry_after: 10 },
};

describe('ServiceUnavailableAction', () => {
	beforeEach(() => {
		jest.useFakeTimers();
	});

	afterEach(() => {
		jest.useRealTimers();
	});

	it('retries automatically after the suggested delay', () => {
		const onRetry = jest.fn();
		render(
			<ServiceUnavailableAction
				error={error}
				retries={1}
				onRetry={onRetry}
			/>
		);

		expect(
			screen.getByText('Retrying automatically in 10 seconds.')
		).toBeInTheDocument();

		act(() => {
			jest.advanceTimersByTime(9999);
		});
		expect(onRetry).not.toHaveBeenCalled();

		act(() => {
			jest.advanceTimersByTime(1);
		});
		expect(onRetry).toHaveBeenCalledTimes(1);
	});

	it('retries immediately when asked', () => {
		const onRetry = jest.fn();
		render(
			<ServiceUnavailableAction
				error={error}
				retries={1}
				onRetry={onRetry}
			/>
		);

		fireEvent.click(screen.getByRole('button', { name: 'Retry now' }));
		expect(onRetry).toHaveBeenCalledTimes(1);
	});

	it('stops the automatic retry when cancelled', () => {
		const onRetry = jest.fn();
		render(
			<ServiceUnavailableAction
				error={error}
				retries={1}
				onRetry={onRetry}
			/>
		);

		fireEvent.click(
			screen.getByRole('button', { name: 'Cancel automatic retry' })
		);
		act(() => {
			jest.advanceTimersByTime(60000);
		});

		expect(onRetry).not.toHaveBeenCalled();
		expect(
			screen.getByRole('button', { name: 'Retry now' })
		).toBeInTheDocument();
	});

	it('stops retrying automatically after repeated failures', () => {
		const onRetry = jest.fn();
		render(
			<ServiceUnavailableAction
				error={error}
				retries={MAX_AUTO_RETRIES + 1}
				onRetry={onRetry}
			/>
		);

		act(() => {
			jest.advanceTimersByTime(60000);
		});

		expect(onRetry).not.toHaveBeenCalled();
		expect(
			screen.getByText(
				'The AI service is still unavailable. Try again in a few minutes.'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByRole('button', { name: 'Cancel automatic retry' })
		).not.toBeInTheDocument();
	});

	it('caps a very long suggested delay', () => {
		render(
			<ServiceUnavailableAction
				error={{ ...error, data: { retry_after: 999999 } }}
				retries={1}
				onRetry={jest.fn()}
			/>
		);

		expect(
			screen.getByText('Retrying automatically in 300 seconds.')
		).toBeInTheDocument();
	});

	it('explains when the review cannot start', async () => {
		const onRetry = jest.fn(() => false);
		render(
			<ServiceUnavailableAction
				error={error}
				retries={1}
				onRetry={onRetry}
			/>
		);

		await act(async () => {
			jest.advanceTimersByTime(10000);
		});

		expect(onRetry).toHaveBeenCalledTimes(1);
		expect(
			screen.getByText(
				'Add some content and save the post, then run the review again.'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByText('Retrying automatically in 10 seconds.')
		).not.toBeInTheDocument();
	});
});
