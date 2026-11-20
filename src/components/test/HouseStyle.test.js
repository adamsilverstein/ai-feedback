/**
 * Tests for the House style settings.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';
import HouseStyle from '../HouseStyle';
import { resetHouseStyle } from '../../utils/house-style';

vi.mock('@wordpress/api-fetch', () => ({ default: vi.fn() }));

/**
 * Rules as the server returns them.
 *
 * @param {Object} overrides Fields to change.
 * @return {Object} House style state.
 */
function state(overrides = {}) {
	return {
		site: [{ id: 's1', text: 'Use the Oxford comma' }],
		user: [{ id: 'u1', text: 'Headings use sentence case' }],
		can_manage_site: false,
		max_rules: 20,
		max_length: 150,
		...overrides,
	};
}

beforeEach(() => {
	resetHouseStyle();
	apiFetch.mockReset();
});

describe('HouseStyle', () => {
	it('shows site rules read-only to an author', async () => {
		apiFetch.mockResolvedValue(state());
		render(<HouseStyle />);

		expect(await screen.findByText('Use the Oxford comma')).toBeVisible();
		expect(screen.getByText('Headings use sentence case')).toBeVisible();
		expect(
			screen.queryByRole('button', {
				name: 'Remove rule: Use the Oxford comma',
			})
		).not.toBeInTheDocument();
		expect(
			screen.getByRole('button', {
				name: 'Remove rule: Headings use sentence case',
			})
		).toBeVisible();
		expect(screen.queryByLabelText('Applies to')).not.toBeInTheDocument();
	});

	it('hides the empty site list from an author', async () => {
		apiFetch.mockResolvedValue(state({ site: [] }));
		render(<HouseStyle />);

		expect(await screen.findByText('Just for you')).toBeVisible();
		expect(screen.queryByText('Whole site')).not.toBeInTheDocument();
	});

	it('adds a personal rule', async () => {
		const user = userEvent.setup();
		apiFetch.mockResolvedValueOnce(state());
		apiFetch.mockResolvedValueOnce(
			state({
				user: [
					{ id: 'u1', text: 'Headings use sentence case' },
					{ id: 'u2', text: 'Spell out numbers under ten' },
				],
			})
		);
		render(<HouseStyle />);

		await user.type(
			await screen.findByLabelText('New rule'),
			'Spell out numbers under ten'
		);
		await user.click(screen.getByRole('button', { name: 'Add rule' }));

		expect(apiFetch).toHaveBeenLastCalledWith({
			path: '/ai-feedback/v1/house-style',
			method: 'POST',
			data: { text: 'Spell out numbers under ten', scope: 'user' },
		});
		expect(
			await screen.findByText('Spell out numbers under ten')
		).toBeVisible();
		expect(screen.getByLabelText('New rule')).toHaveValue('');
	});

	it('lets an administrator add a site rule', async () => {
		const user = userEvent.setup();
		apiFetch.mockResolvedValue(state({ can_manage_site: true }));
		render(<HouseStyle />);

		await user.type(await screen.findByLabelText('New rule'), 'Rule');
		await user.selectOptions(
			screen.getByLabelText('Applies to'),
			'Everyone on this site'
		);
		await user.click(screen.getByRole('button', { name: 'Add rule' }));

		expect(apiFetch.mock.lastCall[0].data).toEqual({
			text: 'Rule',
			scope: 'site',
		});
	});

	it('edits and removes a rule', async () => {
		const user = userEvent.setup();
		apiFetch.mockResolvedValue(state({ can_manage_site: true }));
		render(<HouseStyle />);

		await user.click(
			await screen.findByRole('button', {
				name: 'Edit rule: Use the Oxford comma',
			})
		);
		const field = screen.getByLabelText('Edit rule');
		await user.clear(field);
		await user.type(field, 'Skip the Oxford comma');
		await user.click(screen.getByRole('button', { name: 'Save' }));

		expect(apiFetch).toHaveBeenCalledWith({
			path: '/ai-feedback/v1/house-style/s1',
			method: 'PUT',
			data: { text: 'Skip the Oxford comma', scope: 'site' },
		});

		await user.click(
			await screen.findByRole('button', {
				name: 'Remove rule: Headings use sentence case',
			})
		);
		expect(apiFetch).toHaveBeenLastCalledWith({
			path: '/ai-feedback/v1/house-style/u1?scope=user',
			method: 'DELETE',
		});
	});

	it('shows the error when a rule is rejected', async () => {
		const user = userEvent.setup();
		apiFetch.mockResolvedValueOnce(state());
		apiFetch.mockRejectedValueOnce({
			message: 'Rules can be at most 150 characters.',
		});
		render(<HouseStyle />);

		await user.type(await screen.findByLabelText('New rule'), 'Rule');
		await user.click(screen.getByRole('button', { name: 'Add rule' }));

		// The notice is also announced, so its text appears twice.
		const matches = await screen.findAllByText(
			'Rules can be at most 150 characters.'
		);
		const notice = matches
			.map((match) => match.closest('.components-notice'))
			.find(Boolean);
		expect(notice).toBeVisible();
		expect(screen.getByLabelText('New rule')).toHaveValue('Rule');
	});

	it('stops adding once the list is full', async () => {
		const user = userEvent.setup();
		apiFetch.mockResolvedValue(state({ max_rules: 1 }));
		render(<HouseStyle />);

		await user.type(await screen.findByLabelText('New rule'), 'Rule');

		const form = screen.getByRole('button', { name: 'Add rule' });
		expect(form).toHaveAttribute('aria-disabled', 'true');
		expect(
			within(form.closest('form')).getByText(/This list is full/)
		).toBeVisible();
	});

	it('renders nothing when the rules cannot load', async () => {
		apiFetch.mockRejectedValue(new Error('forbidden'));
		const { container } = render(<HouseStyle />);

		await vi.waitFor(() => expect(apiFetch).toHaveBeenCalled());
		expect(container).toBeEmptyDOMElement();
	});
});
