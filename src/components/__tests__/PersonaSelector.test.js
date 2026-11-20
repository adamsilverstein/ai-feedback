/**
 * Tests for PersonaSelector component.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useSelect, useDispatch } from '@wordpress/data';
import PersonaSelector, { MAX_AUDIENCE_LENGTH } from '../PersonaSelector';

vi.mock('@wordpress/data', () => ({
	useSelect: vi.fn(),
	useDispatch: vi.fn(),
}));

vi.mock('@wordpress/components', () => ({
	RadioControl: ({ label, selected, options, onChange }) => (
		<fieldset>
			<legend>{label}</legend>
			{options.map((option) => (
				<label key={option.value} htmlFor={`persona-${option.value}`}>
					<input
						id={`persona-${option.value}`}
						type="radio"
						name="persona"
						value={option.value}
						checked={selected === option.value}
						onChange={() => onChange(option.value)}
					/>
					{option.label}
					{option.description && <span>{option.description}</span>}
				</label>
			))}
		</fieldset>
	),
	TextControl: ({ label, value, onChange, onFocus, onBlur, maxLength }) => (
		<label htmlFor="audience">
			{label}
			<input
				id="audience"
				type="text"
				value={value}
				maxLength={maxLength}
				onChange={(event) => onChange(event.target.value)}
				onFocus={onFocus}
				onBlur={onBlur}
			/>
		</label>
	),
}));

vi.mock('@wordpress/i18n', () => ({
	__: (text) => text,
}));

vi.mock('../../store', () => ({
	STORE_NAME: 'ai-feedback/store',
}));

const PERSONAS = [
	{ id: 'copy_editor', label: 'Copy editor', description: 'As before.' },
	{
		id: 'skeptical_expert',
		label: 'Skeptical expert',
		description: 'Questions claims and asks for sources.',
	},
	{ id: 'busy_skimmer', label: 'Busy skimmer', description: '' },
];

const updateSettings = vi.fn();
const setReviewAudience = vi.fn();

/**
 * Mock the store.
 *
 * @param {Object} state                   Store state.
 * @param {string} state.persona           Selected persona.
 * @param {string} state.audience          Audience.
 * @param {Array}  state.availablePersonas Personas.
 */
function setup({
	persona = 'copy_editor',
	audience = '',
	availablePersonas = PERSONAS,
} = {}) {
	useSelect.mockImplementation((selector) =>
		selector(() => ({
			getAvailablePersonas: () => availablePersonas,
			getPersona: () => persona,
			getReviewAudience: () => audience,
		}))
	);
	useDispatch.mockReturnValue({ updateSettings, setReviewAudience });
}

describe('PersonaSelector', () => {
	beforeEach(() => {
		vi.clearAllMocks();
	});

	it('lists the personas with the saved one selected', () => {
		setup({ persona: 'skeptical_expert' });
		render(<PersonaSelector />);

		expect(
			screen.getByRole('group', { name: 'Read it as' })
		).toBeInTheDocument();
		expect(screen.getByLabelText(/Skeptical expert/)).toBeChecked();
		expect(screen.getByLabelText(/Copy editor/)).not.toBeChecked();
		expect(
			screen.getByText('Questions claims and asks for sources.')
		).toBeInTheDocument();
	});

	it('saves the chosen persona as a preference', async () => {
		setup();
		render(<PersonaSelector />);

		await userEvent.click(screen.getByLabelText(/Busy skimmer/));

		expect(updateSettings).toHaveBeenCalledWith({
			default_persona: 'busy_skimmer',
		});
		expect(screen.getByLabelText(/Busy skimmer/)).toBeChecked();
	});

	it('rolls back the choice when saving fails', async () => {
		setup();
		updateSettings.mockRejectedValueOnce(new Error('Nope'));
		render(<PersonaSelector />);

		await userEvent.click(screen.getByLabelText(/Busy skimmer/));

		expect(await screen.findByLabelText(/Copy editor/)).toBeChecked();
	});

	it('keeps the audience in the store as it is typed and saves it on blur', async () => {
		setup();
		const { rerender } = render(<PersonaSelector />);

		const field = screen.getByLabelText('Who is this for? (optional)');
		expect(field).toHaveAttribute('maxLength', `${MAX_AUDIENCE_LENGTH}`);

		await userEvent.type(field, 'x');
		expect(setReviewAudience).toHaveBeenCalledWith('x');
		expect(updateSettings).not.toHaveBeenCalled();

		// The store now holds the typed audience.
		setup({ audience: ' plugin developers ' });
		rerender(<PersonaSelector />);
		await userEvent.tab();

		expect(updateSettings).toHaveBeenCalledWith({
			review_audience: 'plugin developers',
		});
	});

	it('does not save an unchanged audience', async () => {
		setup({ audience: 'plugin developers' });
		render(<PersonaSelector />);

		await userEvent.click(
			screen.getByLabelText('Who is this for? (optional)')
		);
		await userEvent.tab();

		expect(updateSettings).not.toHaveBeenCalled();
	});

	it('renders nothing before the personas load', () => {
		setup({ availablePersonas: [] });
		const { container } = render(<PersonaSelector />);

		expect(container).toBeEmptyDOMElement();
	});
});
