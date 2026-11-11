/**
 * External dependencies
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { useCommand } from '@wordpress/commands';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { store as preferencesStore } from '@wordpress/preferences';

/**
 * Internal dependencies
 */
import ResetTipsCommand from '../ResetTipsCommand';

vi.mock('@wordpress/commands', () => ({ useCommand: vi.fn() }));

const LEGACY_KEY = 'ai-feedback-welcomed';

/**
 * Render the command and run its callback.
 *
 * @return {Object} The registry.
 */
function runResetCommand() {
	const registry = createRegistry();
	registry.register(preferencesStore);
	registry.register(noticesStore);
	registry
		.dispatch(preferencesStore)
		.set('ai-feedback', 'welcomeDismissed', true);
	registry.dispatch(preferencesStore).set('ai-feedback', 'reviewCount', 3);

	render(
		<RegistryProvider value={registry}>
			<ResetTipsCommand />
		</RegistryProvider>
	);
	const { callback } = useCommand.mock.calls.at(-1)[0];
	callback({ close: vi.fn() });
	return registry;
}

describe('ResetTipsCommand', () => {
	beforeEach(() => {
		window.localStorage.clear();
		useCommand.mockReset();
	});

	it('resets the welcome and tip preferences', () => {
		const registry = runResetCommand();
		const get = (key) =>
			registry.select(preferencesStore).get('ai-feedback', key);
		expect(get('welcomeDismissed')).toBe(false);
		expect(get('reviewCount')).toBe(0);
		expect(get('dismissedTips')).toEqual([]);
	});

	it('clears the legacy welcome flag so it cannot dismiss the modal again', () => {
		window.localStorage.setItem(LEGACY_KEY, 'true');
		runResetCommand();
		expect(window.localStorage.getItem(LEGACY_KEY)).toBeNull();
	});
});
