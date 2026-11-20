/**
 * Tests for the "Remember this for future reviews" offer in a thread.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';
import RememberRule from '../RememberRule';
import { resetHouseStyle } from '../../utils/house-style';

vi.mock('@wordpress/api-fetch', () => ({ default: vi.fn() }));
vi.mock('@wordpress/a11y', () => ({ speak: vi.fn() }));

const RULES = {
	site: [],
	user: [],
	can_manage_site: false,
	max_rules: 20,
	max_length: 150,
};

const PROPOSAL = { text: 'Headings use sentence case', status: '' };

/**
 * Answer the rules request, then each later request in turn.
 *
 * @param {Object}    rules     Rules returned by GET.
 * @param {...Object} responses Responses to later requests.
 */
function mockRequests(rules, ...responses) {
	apiFetch.mockImplementation(({ method }) => {
		if (!method) {
			return Promise.resolve(rules);
		}
		const next = responses.shift();
		return next instanceof Error
			? Promise.reject(next)
			: Promise.resolve(next ?? {});
	});
}

beforeEach(() => {
	resetHouseStyle();
	apiFetch.mockReset();
});

describe('RememberRule', () => {
	it('offers the proposed rule for editing and saves it only on click', async () => {
		const user = userEvent.setup();
		mockRequests(RULES, {
			...RULES,
			user: [{ id: 'u1', text: 'Headings use sentence case always' }],
		});
		render(<RememberRule noteId={12} proposal={PROPOSAL} />);

		const field = screen.getByLabelText('Remember this for future reviews');
		expect(field).toHaveValue('Headings use sentence case');
		expect(apiFetch).not.toHaveBeenCalledWith(
			expect.objectContaining({ method: 'POST' })
		);

		await user.type(field, ' always');
		await user.click(screen.getByRole('button', { name: 'Remember' }));

		expect(apiFetch).toHaveBeenCalledWith({
			path: '/ai-feedback/v1/house-style',
			method: 'POST',
			data: {
				text: 'Headings use sentence case always',
				scope: 'user',
				note_id: 12,
			},
		});
		expect(await screen.findByText('Saved to house style.')).toBeVisible();
	});

	it('lets an administrator save it for the whole site', async () => {
		const user = userEvent.setup();
		mockRequests({ ...RULES, can_manage_site: true }, RULES);
		render(<RememberRule noteId={12} proposal={PROPOSAL} />);

		await user.selectOptions(
			await screen.findByLabelText('Applies to'),
			'Everyone on this site'
		);
		await user.click(screen.getByRole('button', { name: 'Remember' }));

		expect(apiFetch.mock.lastCall[0].data.scope).toBe('site');
	});

	it('dismisses the offer with Not now', async () => {
		const user = userEvent.setup();
		mockRequests(RULES);
		const { container } = render(
			<RememberRule noteId={12} proposal={PROPOSAL} />
		);

		await user.click(screen.getByRole('button', { name: 'Not now' }));

		expect(apiFetch).toHaveBeenCalledWith({
			path: '/ai-feedback/v1/house-style/proposals/12/dismiss',
			method: 'POST',
		});
		expect(container).toBeEmptyDOMElement();
	});

	it('shows the error and keeps the offer when saving fails', async () => {
		const user = userEvent.setup();
		mockRequests(RULES, new Error('There can be at most 20 rules.'));
		render(<RememberRule noteId={12} proposal={PROPOSAL} />);

		await user.click(screen.getByRole('button', { name: 'Remember' }));

		expect(await screen.findByRole('alert')).toHaveTextContent(
			'There can be at most 20 rules.'
		);
		expect(screen.getByRole('button', { name: 'Remember' })).toBeVisible();
	});

	it('shows a saved proposal as saved', () => {
		mockRequests(RULES);
		render(
			<RememberRule
				noteId={12}
				proposal={{ ...PROPOSAL, status: 'saved' }}
			/>
		);

		expect(screen.getByText('Saved to house style.')).toBeVisible();
	});

	it('shows a rule that already exists as saved', async () => {
		mockRequests({
			...RULES,
			site: [{ id: 's1', text: 'headings use sentence case.' }],
		});
		render(<RememberRule noteId={12} proposal={PROPOSAL} />);

		expect(await screen.findByText('Saved to house style.')).toBeVisible();
	});

	it('renders nothing for a dismissed proposal', () => {
		mockRequests(RULES);
		const { container } = render(
			<RememberRule
				noteId={12}
				proposal={{ ...PROPOSAL, status: 'dismissed' }}
			/>
		);

		expect(container).toBeEmptyDOMElement();
	});
});
