/**
 * External dependencies
 */
import { fireEvent, render } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { createRegistry, RegistryProvider } from '@wordpress/data';
import { store as keyboardShortcutsStore } from '@wordpress/keyboard-shortcuts';

/**
 * Internal dependencies
 */
import KeyboardShortcuts, { getShortcuts } from '../KeyboardShortcuts';

jest.mock('@wordpress/block-editor', () => ({ store: 'core/block-editor' }));
jest.mock('../../hooks/use-start-review', () => () => ({
	startReview: jest.fn(),
}));
jest.mock('../../store', () => ({ STORE_NAME: 'ai-feedback/store' }));

describe('getShortcuts', () => {
	it('defines unique names and key combinations', () => {
		const shortcuts = getShortcuts();
		const names = shortcuts.map(({ name }) => name);
		const combos = shortcuts.map(
			({ keyCombination }) =>
				`${keyCombination.modifier}+${keyCombination.character}`
		);

		expect(new Set(names).size).toBe(shortcuts.length);
		expect(new Set(combos).size).toBe(shortcuts.length);
	});

	it('avoids access-key combinations used by core', () => {
		// Core editor shortcuts on the access modifier.
		const coreAccessKeys = [
			'0',
			'1',
			'7',
			'd',
			'h',
			'n',
			'o',
			'p',
			'x',
			'z',
		];

		getShortcuts().forEach(({ keyCombination }) => {
			expect(keyCombination.modifier).toBe('access');
			expect(coreAccessKeys).not.toContain(keyCombination.character);
		});
	});
});

function createTestRegistry(enableComplementaryArea) {
	const registry = createRegistry();
	registry.register(keyboardShortcutsStore);
	[
		['core/interface', {}],
		['core/block-editor', {}],
		['ai-feedback/store', {}],
	].forEach(([name]) =>
		registry.registerStore(name, {
			reducer: (state = {}) => state,
			selectors: {
				getActiveComplementaryArea: () => null,
				getClientIdsWithDescendants: () => [],
				getSelectedBlockClientId: () => null,
				getLastReview: () => null,
			},
			actions: {
				enableComplementaryArea: (...args) => {
					enableComplementaryArea(...args);
					return { type: 'NOOP' };
				},
				disableComplementaryArea: () => ({ type: 'NOOP' }),
			},
		})
	);

	return registry;
}

describe('KeyboardShortcuts', () => {
	it('registers shortcuts on mount and removes them on unmount', () => {
		const registry = createTestRegistry(jest.fn());

		const { unmount } = render(
			<RegistryProvider value={registry}>
				<KeyboardShortcuts />
			</RegistryProvider>
		);

		const select = registry.select(keyboardShortcutsStore);
		expect(select.getShortcutKeyCombination('ai-feedback/review')).toEqual({
			modifier: 'access',
			character: 'r',
		});
		expect(
			select
				.getCategoryShortcuts('global')
				.filter((name) => name.startsWith('ai-feedback/'))
		).toHaveLength(4);

		unmount();

		expect(
			select.getShortcutKeyCombination('ai-feedback/review')
		).toBeNull();
	});
});

describe('KeyboardShortcuts handlers', () => {
	it('opens the sidebar on Shift+Alt+F', () => {
		const enableComplementaryArea = jest.fn();
		const registry = createTestRegistry(enableComplementaryArea);

		render(
			<RegistryProvider value={registry}>
				<KeyboardShortcuts />
			</RegistryProvider>
		);

		fireEvent.keyDown(document, {
			key: 'F',
			code: 'KeyF',
			keyCode: 70,
			shiftKey: true,
			altKey: true,
		});

		expect(enableComplementaryArea).toHaveBeenCalledWith(
			'core',
			'ai-feedback/ai-feedback-sidebar'
		);
	});
});
