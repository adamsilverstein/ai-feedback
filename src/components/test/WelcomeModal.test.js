/**
 * External dependencies
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, act } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import { store as preferencesStore } from '@wordpress/preferences';

/**
 * Internal dependencies
 */
import WelcomeModal from '../WelcomeModal';

vi.mock('@wordpress/api-fetch', () => ({ default: vi.fn() }));

const LEGACY_KEY = 'ai-feedback-welcomed';

/**
 * Render WelcomeModal against a fresh registry.
 *
 * @param {boolean} welcomeDismissed Stored welcome preference.
 * @return {Object} The registry.
 */
function renderModal(welcomeDismissed) {
	const registry = createRegistry();
	registry.register(preferencesStore);
	registry
		.dispatch(preferencesStore)
		.set('ai-feedback', 'welcomeDismissed', welcomeDismissed);

	render(
		<RegistryProvider value={registry}>
			<WelcomeModal />
		</RegistryProvider>
	);
	return registry;
}

const getWelcomeDismissed = (registry) =>
	registry.select(preferencesStore).get('ai-feedback', 'welcomeDismissed');

describe('WelcomeModal', () => {
	beforeEach(() => {
		window.localStorage.clear();
		apiFetch.mockReset();
		apiFetch.mockResolvedValue({
			ai_client_available: true,
			connector_configured: true,
		});
	});

	it('opens after the status check when not yet dismissed', async () => {
		renderModal(false);

		expect(
			await screen.findByRole('dialog', {
				name: /Welcome to AI Feedback/,
			})
		).toBeInTheDocument();
		expect(apiFetch).toHaveBeenCalledTimes(1);
	});

	it('stays closed once dismissed', () => {
		renderModal(true);

		expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
		expect(apiFetch).not.toHaveBeenCalled();
	});

	it('moves the legacy localStorage flag into preferences', async () => {
		window.localStorage.setItem(LEGACY_KEY, 'true');
		const registry = renderModal(false);

		await waitFor(() => expect(getWelcomeDismissed(registry)).toBe(true));
		expect(window.localStorage.getItem(LEGACY_KEY)).toBeNull();
		expect(apiFetch).not.toHaveBeenCalled();
		expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
	});

	it('clears a stale legacy flag so a reset reopens the modal', async () => {
		window.localStorage.setItem(LEGACY_KEY, 'true');
		const registry = renderModal(true);
		expect(window.localStorage.getItem(LEGACY_KEY)).toBeNull();

		act(() => {
			registry
				.dispatch(preferencesStore)
				.set('ai-feedback', 'welcomeDismissed', false);
		});

		expect(await screen.findByRole('dialog')).toBeInTheDocument();
		expect(getWelcomeDismissed(registry)).toBe(false);
	});
});
