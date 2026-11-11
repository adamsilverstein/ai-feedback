/**
 * Tests for ToneSelector component.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useSelect, useDispatch } from '@wordpress/data';
import ToneSelector from '../ToneSelector';

// Mock WordPress modules.
vi.mock('@wordpress/data', () => ({
	useSelect: vi.fn(),
	useDispatch: vi.fn(),
}));

vi.mock('@wordpress/components', () => ({
	// eslint-disable-next-line jsx-a11y/label-has-associated-control
	SelectControl: ({ label, value, options, onChange, help }) => (
		<label htmlFor={`sel-${label}`}>
			{label}
			<select
				id={`sel-${label}`}
				aria-label={label}
				value={value}
				onChange={(e) => onChange(e.target.value)}
			>
				{options.map((opt) => (
					<option key={opt.value} value={opt.value}>
						{opt.label}
					</option>
				))}
			</select>
			{help && <span>{help}</span>}
		</label>
	),
}));

vi.mock('@wordpress/i18n', () => ({
	__: (text) => text,
}));

vi.mock('../../store', () => ({
	STORE_NAME: 'ai-feedback/store',
}));

const MOCK_TONES = [
	{ id: 'professional', label: 'Professional' },
	{ id: 'casual', label: 'Casual' },
	{ id: 'academic', label: 'Academic' },
	{ id: 'friendly', label: 'Friendly' },
];

const mockUpdateSettings = vi.fn();

function setupMocks(targetTone = 'professional', availableTones = MOCK_TONES) {
	useSelect.mockImplementation((selector) =>
		selector(() => ({
			getAvailableTones: () => availableTones,
			getTargetTone: () => targetTone,
		}))
	);
	useDispatch.mockReturnValue({ updateSettings: mockUpdateSettings });
}

describe('ToneSelector', () => {
	beforeEach(() => {
		vi.clearAllMocks();
	});

	it('renders the tone select control', () => {
		setupMocks();
		render(<ToneSelector />);

		expect(screen.getByLabelText('Target Tone')).toBeInTheDocument();
	});

	it('renders all tone options', () => {
		setupMocks();
		render(<ToneSelector />);

		const select = screen.getByLabelText('Target Tone');
		const options = select.querySelectorAll('option');

		expect(options).toHaveLength(4);
		expect(options[0]).toHaveTextContent('Professional');
		expect(options[1]).toHaveTextContent('Casual');
		expect(options[2]).toHaveTextContent('Academic');
		expect(options[3]).toHaveTextContent('Friendly');
	});

	it('selects the current tone', () => {
		setupMocks('academic');
		render(<ToneSelector />);

		expect(screen.getByLabelText('Target Tone')).toHaveValue('academic');
	});

	it('calls updateSettings when tone is changed', async () => {
		setupMocks('professional');
		render(<ToneSelector />);

		await userEvent.selectOptions(
			screen.getByLabelText('Target Tone'),
			'casual'
		);

		expect(mockUpdateSettings).toHaveBeenCalledWith({
			default_tone: 'casual',
		});
	});

	it('renders nothing when no tones are available', () => {
		setupMocks('professional', []);
		const { container } = render(<ToneSelector />);

		expect(container).toBeEmptyDOMElement();
	});

	it('displays contextual help text for professional tone', () => {
		setupMocks('professional');
		render(<ToneSelector />);

		expect(
			screen.getByText(/business content and documentation/i)
		).toBeInTheDocument();
	});

	it('displays contextual help text for casual tone', () => {
		setupMocks('casual');
		render(<ToneSelector />);

		expect(screen.getByText(/blogs and social media/i)).toBeInTheDocument();
	});

	it('updates displayed value and help text immediately without waiting on store', async () => {
		// The store's targetTone stays at 'professional' because updateSettings
		// is mocked and never dispatches back. The draft state must drive the
		// visible selection and help text so the UI does not flicker back to
		// the previous value between click and server response.
		setupMocks('professional');
		render(<ToneSelector />);

		await userEvent.selectOptions(
			screen.getByLabelText('Target Tone'),
			'academic'
		);

		expect(screen.getByLabelText('Target Tone')).toHaveValue('academic');
		expect(
			screen.getByText(/research and educational content/i)
		).toBeInTheDocument();
	});

	it.each([
		['academic', /research and educational content/i],
		['friendly', /community and customer content/i],
	])('displays contextual help text for %s tone', (tone, help) => {
		setupMocks(tone);
		render(<ToneSelector />);

		expect(screen.getByText(help)).toBeInTheDocument();
	});

	it('reverts the draft when saving fails', async () => {
		setupMocks('professional');
		mockUpdateSettings.mockRejectedValueOnce(new Error('nope'));
		render(<ToneSelector />);

		await userEvent.selectOptions(
			screen.getByLabelText('Target Tone'),
			'casual'
		);

		expect(screen.getByLabelText('Target Tone')).toHaveValue(
			'professional'
		);
	});

	it('resyncs when the stored tone changes externally', () => {
		setupMocks('professional');
		const { rerender } = render(<ToneSelector />);

		setupMocks('friendly');
		rerender(<ToneSelector />);

		expect(screen.getByLabelText('Target Tone')).toHaveValue('friendly');
	});
});
