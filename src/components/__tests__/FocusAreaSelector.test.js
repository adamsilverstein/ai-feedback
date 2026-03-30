/**
 * Tests for FocusAreaSelector component.
 */
import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useSelect, useDispatch } from '@wordpress/data';
import FocusAreaSelector from '../FocusAreaSelector';

// Mock WordPress modules.
jest.mock('@wordpress/data', () => ({
	useSelect: jest.fn(),
	useDispatch: jest.fn(),
}));

jest.mock('@wordpress/components', () => ({
	CheckboxControl: ({ label, checked, onChange, help }) => (
		<label>
			<input
				type="checkbox"
				checked={checked}
				onChange={(e) => onChange(e.target.checked)}
				aria-label={label}
			/>
			{label}
			{help && <span>{help}</span>}
		</label>
	),
}));

jest.mock('@wordpress/i18n', () => ({
	__: (text) => text,
}));

jest.mock('../../store', () => ({
	STORE_NAME: 'ai-feedback/store',
}));

const MOCK_FOCUS_AREAS = [
	{ id: 'content', label: 'Content Quality', description: 'Clarity, accuracy' },
	{ id: 'tone', label: 'Tone & Voice', description: 'Consistency' },
	{ id: 'flow', label: 'Flow & Structure', description: 'Progression' },
	{ id: 'design', label: 'Design & Formatting', description: 'Visual hierarchy' },
];

const mockUpdateSettings = jest.fn();

function setupMocks(focusAreas = ['content', 'tone', 'flow'], availableFocusAreas = MOCK_FOCUS_AREAS) {
	useSelect.mockImplementation((selector) =>
		selector(() => ({
			getAvailableFocusAreas: () => availableFocusAreas,
			getFocusAreas: () => focusAreas,
		}))
	);
	useDispatch.mockReturnValue({ updateSettings: mockUpdateSettings });
}

describe('FocusAreaSelector', () => {
	beforeEach(() => {
		jest.clearAllMocks();
	});

	it('renders all focus area checkboxes', () => {
		setupMocks();
		render(<FocusAreaSelector />);

		expect(screen.getByLabelText('Content Quality')).toBeInTheDocument();
		expect(screen.getByLabelText('Tone & Voice')).toBeInTheDocument();
		expect(screen.getByLabelText('Flow & Structure')).toBeInTheDocument();
		expect(screen.getByLabelText('Design & Formatting')).toBeInTheDocument();
	});

	it('checks boxes matching selected focus areas', () => {
		setupMocks(['content', 'flow']);
		render(<FocusAreaSelector />);

		expect(screen.getByLabelText('Content Quality')).toBeChecked();
		expect(screen.getByLabelText('Tone & Voice')).not.toBeChecked();
		expect(screen.getByLabelText('Flow & Structure')).toBeChecked();
		expect(screen.getByLabelText('Design & Formatting')).not.toBeChecked();
	});

	it('calls updateSettings with added area when checking a box', async () => {
		setupMocks(['content']);
		render(<FocusAreaSelector />);

		await userEvent.click(screen.getByLabelText('Tone & Voice'));

		expect(mockUpdateSettings).toHaveBeenCalledWith({
			default_focus_areas: ['content', 'tone'],
		});
	});

	it('calls updateSettings with removed area when unchecking a box', async () => {
		setupMocks(['content', 'tone', 'flow']);
		render(<FocusAreaSelector />);

		await userEvent.click(screen.getByLabelText('Tone & Voice'));

		expect(mockUpdateSettings).toHaveBeenCalledWith({
			default_focus_areas: ['content', 'flow'],
		});
	});

	it('renders nothing when no focus areas are available', () => {
		setupMocks([], []);
		const { container } = render(<FocusAreaSelector />);

		expect(container).toBeEmptyDOMElement();
	});

	it('displays help text for each focus area', () => {
		setupMocks();
		render(<FocusAreaSelector />);

		expect(screen.getByText(/grammar, spelling, clarity/i)).toBeInTheDocument();
		expect(screen.getByText(/voice consistency/i)).toBeInTheDocument();
		expect(screen.getByText(/logical progression/i)).toBeInTheDocument();
		expect(screen.getByText(/formatting, headings/i)).toBeInTheDocument();
	});

	it('renders the fieldset legend', () => {
		setupMocks();
		render(<FocusAreaSelector />);

		expect(screen.getByText('Focus Areas')).toBeInTheDocument();
	});
});
