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
vi.mock('@wordpress/a11y', () => ({ speak: vi.fn() }));

const SUGGEST_PATH = '/ai-feedback/v1/house-style/suggestions/posts';
const SUGGEST = 'Suggest rules from published posts';

/**
 * Answer the rules request with `rules`, a suggestion request with
 * `suggestions`, and any other request with the next of `responses`.
 *
 * @param {Object}                               rules       Rules returned by GET.
 * @param {Object|Error|(() => Promise<Object>)} suggestions Suggestion response, an error to reject with, or a function returning a promise.
 * @param {...Object}                            responses   Responses to later requests.
 */
function mockSuggestions(rules, suggestions, ...responses) {
	apiFetch.mockImplementation(({ path, method }) => {
		if (!method) {
			return Promise.resolve(rules);
		}
		if (path === SUGGEST_PATH) {
			if (typeof suggestions === 'function') {
				return suggestions();
			}
			return suggestions instanceof Error
				? Promise.reject(suggestions)
				: Promise.resolve(suggestions);
		}
		return Promise.resolve(responses.shift() ?? rules);
	});
}

/**
 * Requests that saved or changed a rule.
 *
 * @return {Object[]} Request options.
 */
function ruleWrites() {
	return apiFetch.mock.calls
		.map(([options]) => options)
		.filter(({ path, method }) => method && path !== SUGGEST_PATH);
}

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

	it('explains what suggesting does and that it uses an AI request', async () => {
		apiFetch.mockResolvedValue(state());
		render(<HouseStyle />);

		const button = await screen.findByRole('button', { name: SUGGEST });
		expect(button).toHaveAccessibleDescription(
			'Sends your most recent published posts to your AI provider, which proposes a few rules. Nothing is saved until you choose to remember one. Uses one AI request.'
		);
	});

	it('suggests rules from the author’s posts without saving any', async () => {
		const user = userEvent.setup();
		mockSuggestions(state(), {
			suggestions: ['Spell out numbers under ten', 'Write WordPress'],
			scope: 'user',
			post_count: 5,
		});
		render(<HouseStyle model="some-model" />);

		await user.click(await screen.findByRole('button', { name: SUGGEST }));

		expect(apiFetch).toHaveBeenLastCalledWith({
			path: SUGGEST_PATH,
			method: 'POST',
			data: { scope: 'user', model: 'some-model' },
		});
		expect(await screen.findByLabelText('Suggested rule 1')).toHaveValue(
			'Spell out numbers under ten'
		);
		expect(screen.getByLabelText('Suggested rule 2')).toHaveValue(
			'Write WordPress'
		);
		expect(ruleWrites()).toEqual([]);
		// Not in the saved list.
		expect(
			screen.queryByRole('button', {
				name: 'Remove rule: Spell out numbers under ten',
			})
		).not.toBeInTheDocument();
	});

	it('is busy while the suggestions load', async () => {
		const user = userEvent.setup();
		let finish;
		mockSuggestions(
			state(),
			() =>
				new Promise((resolve) => {
					finish = resolve;
				})
		);
		render(<HouseStyle />);

		const button = await screen.findByRole('button', { name: SUGGEST });
		await user.click(button);

		expect(button).toHaveAttribute('aria-disabled', 'true');
		expect(button).toHaveClass('is-busy');
		// A second click while waiting asks nothing more.
		await user.click(button);
		expect(
			apiFetch.mock.calls.filter(([{ path }]) => path === SUGGEST_PATH)
		).toHaveLength(1);

		finish({ suggestions: ['Write WordPress'] });
		expect(await screen.findByLabelText('Suggested rule 1')).toBeVisible();
		expect(button).not.toHaveAttribute('aria-disabled', 'true');
	});

	it('shows the error when suggesting fails', async () => {
		const user = userEvent.setup();
		const error = new Error(
			'You have reached the maximum number of reviews per hour (10). Please try again later.'
		);
		mockSuggestions(state(), error);
		render(<HouseStyle />);

		await user.click(await screen.findByRole('button', { name: SUGGEST }));

		const matches = await screen.findAllByText(error.message);
		expect(
			matches
				.map((match) => match.closest('.components-notice'))
				.find(Boolean)
		).toBeVisible();
		expect(screen.queryByLabelText(/Suggested rule/)).toBeNull();
		expect(
			screen.getByRole('button', { name: SUGGEST })
		).not.toHaveAttribute('aria-disabled', 'true');
	});

	it('says so when there is nothing new to suggest', async () => {
		const user = userEvent.setup();
		mockSuggestions(state(), { suggestions: [] });
		render(<HouseStyle />);

		await user.click(await screen.findByRole('button', { name: SUGGEST }));

		expect(
			await screen.findByText('No new rules to suggest.')
		).toBeVisible();
	});

	it('saves a suggestion as edited, and only that one', async () => {
		const user = userEvent.setup();
		const saved = 'Spell out numbers under ten, even in headings';
		mockSuggestions(
			state(),
			{ suggestions: ['Spell out numbers under ten', 'Write WordPress'] },
			state({
				user: [
					{ id: 'u1', text: 'Headings use sentence case' },
					{ id: 'u2', text: saved },
				],
				rule: { id: 'u2', text: saved },
			})
		);
		render(<HouseStyle />);

		await user.click(await screen.findByRole('button', { name: SUGGEST }));
		await user.type(
			await screen.findByLabelText('Suggested rule 1'),
			', even in headings'
		);
		await user.click(
			screen.getAllByRole('button', { name: 'Remember' })[0]
		);

		expect(ruleWrites()).toEqual([
			{
				path: '/ai-feedback/v1/house-style',
				method: 'POST',
				data: { text: saved, scope: 'user' },
			},
		]);
		expect(
			await screen.findByText(`Saved to house style: “${saved}”`)
		).toBeVisible();
		// Now in the saved list, and the other suggestion still waits.
		expect(
			screen.getByRole('button', { name: `Remove rule: ${saved}` })
		).toBeVisible();
		expect(screen.getByLabelText('Suggested rule 2')).toHaveValue(
			'Write WordPress'
		);
	});

	it('skips a suggestion without a request', async () => {
		const user = userEvent.setup();
		mockSuggestions(state(), {
			suggestions: ['Spell out numbers under ten', 'Write WordPress'],
		});
		render(<HouseStyle />);

		await user.click(await screen.findByRole('button', { name: SUGGEST }));
		await screen.findByLabelText('Suggested rule 1');
		await user.click(screen.getAllByRole('button', { name: 'Not now' })[0]);

		expect(screen.queryByLabelText('Suggested rule 1')).toBeNull();
		expect(screen.getByLabelText('Suggested rule 2')).toBeVisible();
		expect(ruleWrites()).toEqual([]);
	});

	it('suggests site rules from the whole site for an administrator', async () => {
		const user = userEvent.setup();
		mockSuggestions(state({ can_manage_site: true }), {
			suggestions: ['Write WordPress'],
			scope: 'site',
		});
		render(<HouseStyle model="some-model" />);

		await user.selectOptions(
			await screen.findByLabelText('Applies to'),
			'Everyone on this site'
		);
		const button = screen.getByRole('button', { name: SUGGEST });
		expect(button).toHaveAccessibleDescription(
			'Sends the most recent published posts on this site to your AI provider, which proposes a few rules. Nothing is saved until you choose to remember one. Uses one AI request.'
		);
		await user.click(button);

		expect(apiFetch).toHaveBeenLastCalledWith({
			path: SUGGEST_PATH,
			method: 'POST',
			data: { scope: 'site', model: 'some-model' },
		});
		const suggestion = (
			await screen.findByLabelText('Suggested rule 1')
		).closest('.ai-feedback-remember');
		expect(within(suggestion).getByLabelText('Applies to')).toHaveValue(
			'site'
		);
	});

	it('does not offer suggestions for a full list', async () => {
		apiFetch.mockResolvedValue(state({ max_rules: 1 }));
		render(<HouseStyle />);

		expect(
			await screen.findByRole('button', { name: SUGGEST })
		).toHaveAttribute('aria-disabled', 'true');
	});

	it('renders nothing when the rules cannot load', async () => {
		apiFetch.mockRejectedValue(new Error('forbidden'));
		const { container } = render(<HouseStyle />);

		await vi.waitFor(() => expect(apiFetch).toHaveBeenCalled());
		expect(container).toBeEmptyDOMElement();
	});
});
