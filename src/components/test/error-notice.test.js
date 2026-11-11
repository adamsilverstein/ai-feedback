/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import ErrorNotice from '../ErrorNotice';

jest.mock('@wordpress/a11y', () => ({ speak: jest.fn() }));

describe('ErrorNotice', () => {
	beforeEach(() => {
		window.aiFeedbackData = {
			connectorsUrl: '/wp-admin/options-general.php?page=connectors',
		};
	});

	afterEach(() => {
		delete window.aiFeedbackData;
	});

	it('shows the message, code, and a Connectors link for credential errors', () => {
		render(
			<ErrorNotice
				error={{
					code: 'prompt_client_error',
					message: 'Insufficient credit balance',
					data: { status: 402 },
				}}
				onRemove={() => {}}
			/>
		);

		expect(screen.getByText('Insufficient credit balance')).toBeTruthy();
		expect(screen.getByText(/Error code:/).textContent).toContain(
			'prompt_client_error'
		);
		expect(
			screen.getByRole('link', { name: /Open Connectors Settings/ })
		).toBeTruthy();
	});
});
