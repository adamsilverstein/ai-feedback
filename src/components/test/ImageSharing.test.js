/**
 * Tests for the site setting that sends a post's images to the AI provider.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useSelect, useDispatch } from '@wordpress/data';
import ImageSharing from '../ImageSharing';

// The real CheckboxControl is rendered, and its packages need the rest of
// @wordpress/data.
vi.mock('@wordpress/data', async (importOriginal) => ({
	...(await importOriginal()),
	useSelect: vi.fn(),
	useDispatch: vi.fn(),
}));

vi.mock('../../store', () => ({
	STORE_NAME: 'ai-feedback/store',
}));

const LABEL = 'Send images to the AI provider';

const mockUpdateSettings = vi.fn();

/**
 * Set what the store holds.
 *
 * @param {Object}  state               Store state.
 * @param {boolean} state.sendImages    Whether images are sent.
 * @param {boolean} state.canManageSite Whether the user may change it.
 */
function setupMocks({ sendImages = true, canManageSite = true } = {}) {
	useSelect.mockImplementation((selector) =>
		selector(() => ({
			getSendImages: () => sendImages,
			canManageSite: () => canManageSite,
		}))
	);
	useDispatch.mockReturnValue({ updateSettings: mockUpdateSettings });
}

beforeEach(() => {
	vi.clearAllMocks();
	mockUpdateSettings.mockResolvedValue({});
});

describe('ImageSharing', () => {
	it('shows an administrator the setting, checked while images are sent', () => {
		setupMocks();
		render(<ImageSharing />);

		expect(screen.getByLabelText(LABEL)).toBeChecked();
	});

	it('is unchecked when the site keeps its images', () => {
		setupMocks({ sendImages: false });
		render(<ImageSharing />);

		expect(screen.getByLabelText(LABEL)).not.toBeChecked();
	});

	it('is hidden from users who cannot change site settings', () => {
		setupMocks({ canManageSite: false });
		const { container } = render(<ImageSharing />);

		expect(container).toBeEmptyDOMElement();
	});

	it('saves the setting off when unchecked', async () => {
		const user = userEvent.setup();
		setupMocks();
		render(<ImageSharing />);

		await user.click(screen.getByLabelText(LABEL));

		expect(mockUpdateSettings).toHaveBeenCalledWith({ send_images: false });
		expect(screen.getByLabelText(LABEL)).not.toBeChecked();
	});

	it('saves the setting on when checked', async () => {
		const user = userEvent.setup();
		setupMocks({ sendImages: false });
		render(<ImageSharing />);

		await user.click(screen.getByLabelText(LABEL));

		expect(mockUpdateSettings).toHaveBeenCalledWith({ send_images: true });
	});

	it('goes back to the saved value when saving fails', async () => {
		const user = userEvent.setup();
		setupMocks();
		mockUpdateSettings.mockRejectedValue(new Error('Forbidden'));
		render(<ImageSharing />);

		await user.click(screen.getByLabelText(LABEL));

		await waitFor(() => expect(screen.getByLabelText(LABEL)).toBeChecked());
	});
});
