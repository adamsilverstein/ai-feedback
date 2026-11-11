/**
 * External dependencies
 */
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import {
	createRegistry,
	createReduxStore,
	RegistryProvider,
} from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as keyboardShortcutsStore } from '@wordpress/keyboard-shortcuts';
import { store as preferencesStore } from '@wordpress/preferences';
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies
 */
import TipNotice from '../TipNotice';

// The real block editor store is heavy, so stand in a minimal one.
vi.mock('@wordpress/block-editor', async () => {
	const { createReduxStore: createStore } =
		await vi.importActual('@wordpress/data');
	return {
		store: createStore('core/block-editor', {
			reducer: (state = { blocks: [], selected: null }, action) => {
				switch (action.type) {
					case 'SET_BLOCKS':
						return { ...state, blocks: action.blocks };
					case 'SELECT_BLOCK':
						return { ...state, selected: action.clientId };
				}
				return state;
			},
			selectors: {
				getBlock: (state, clientId) =>
					state.blocks.includes(clientId) ? { clientId } : null,
				getSelectedBlockClientId: (state) => state.selected,
			},
			actions: {
				setBlocks: (blocks) => ({ type: 'SET_BLOCKS', blocks }),
				selectBlock: (clientId) => ({
					type: 'SELECT_BLOCK',
					clientId,
				}),
			},
		}),
	};
});
vi.mock('@wordpress/a11y', () => ({ speak: vi.fn() }));
vi.mock('../../store', () => ({ STORE_NAME: 'ai-feedback/store' }));

/**
 * Render TipNotice against a fresh registry.
 *
 * @param {Object}        state               Fake store state.
 * @param {number}        state.reviewCount   Completed reviews.
 * @param {Array<string>} state.dismissedTips Dismissed tip IDs.
 * @param {Array<Object>} state.models        Available models.
 * @param {Array<Object>} state.focusAreas    Available focus areas.
 * @param {Array<Object>} state.tones         Available tones.
 * @param {Object}        state.blockMapping  Last review block mapping.
 * @param {Array<string>} state.blocks        Client IDs in the editor.
 * @param {boolean}       state.withShortcut  Register the review shortcut.
 * @return {Object} The registry and render container.
 */
function renderTip({
	reviewCount = 0,
	dismissedTips = [],
	models = [],
	focusAreas = [],
	tones = [],
	blockMapping = {},
	blocks = [],
	withShortcut = false,
}) {
	const registry = createRegistry();
	registry.register(preferencesStore);
	registry.register(keyboardShortcutsStore);
	registry.register(
		createReduxStore('ai-feedback/store', {
			reducer: (state = {}) => state,
			selectors: {
				getAvailableModels: () => models,
				getAvailableFocusAreas: () => focusAreas,
				getAvailableTones: () => tones,
				getLastReview: () => ({ block_mapping: blockMapping }),
			},
		})
	);
	registry.register(blockEditorStore);
	registry.dispatch(blockEditorStore).setBlocks(blocks);

	if (withShortcut) {
		registry.dispatch(keyboardShortcutsStore).registerShortcut({
			name: 'ai-feedback/review',
			category: 'global',
			description: 'Review',
			keyCombination: { modifier: 'access', character: 'r' },
		});
	}

	const { set } = registry.dispatch(preferencesStore);
	set('ai-feedback', 'reviewCount', reviewCount);
	set('ai-feedback', 'dismissedTips', dismissedTips);

	const { container } = render(
		<RegistryProvider value={registry}>
			<TipNotice />
		</RegistryProvider>
	);
	return { registry, container };
}

describe('TipNotice', () => {
	it('renders nothing before the first review', () => {
		const { container } = renderTip({ reviewCount: 0 });
		expect(container.querySelector('.ai-feedback-tip')).toBeNull();
	});

	it('shows the first tip and persists dismissal', () => {
		const { registry, container } = renderTip({ reviewCount: 1 });
		expect(container.querySelector('.ai-feedback-tip')).toHaveTextContent(
			/saved as a Note on its block/
		);

		fireEvent.click(screen.getByRole('button', { name: /close/i }));
		expect(
			registry
				.select(preferencesStore)
				.get('ai-feedback', 'dismissedTips')
		).toEqual(['notes-on-blocks']);
		expect(container.querySelector('.ai-feedback-tip')).toBeNull();
	});

	it('selects the first block with a note from the tip action', () => {
		const { registry } = renderTip({
			reviewCount: 1,
			blockMapping: { missing: 1, 'block-2': 2 },
			blocks: ['block-2'],
		});

		fireEvent.click(
			screen.getByRole('button', { name: 'Show first note' })
		);
		expect(
			registry.select(blockEditorStore).getSelectedBlockClientId()
		).toBe('block-2');
	});

	it('hides the action when no noted block is in the editor', () => {
		renderTip({ reviewCount: 1, blockMapping: { gone: 1 } });
		expect(
			screen.queryByRole('button', { name: 'Show first note' })
		).not.toBeInTheDocument();
	});

	it('names the registered review shortcut in the keyboard tip', () => {
		const dismissedTips = [
			'notes-on-blocks',
			'reply-to-notes',
			'feedback-language',
		];
		const { container } = renderTip({
			reviewCount: 10,
			dismissedTips,
			withShortcut: true,
		});
		const tip = container.querySelector('.ai-feedback-tip');
		expect(tip).toHaveTextContent(/start a review from anywhere/);
		expect(tip).toHaveTextContent(/Shift\+Alt\+R|⌃⌥R/);
	});

	it('skips the keyboard tip when the shortcut is not registered', () => {
		const { container } = renderTip({
			reviewCount: 10,
			dismissedTips: [
				'notes-on-blocks',
				'reply-to-notes',
				'feedback-language',
			],
		});
		expect(container.querySelector('.ai-feedback-tip')).toBeNull();
	});

	it('does not announce the tip over the review result', () => {
		speak.mockClear();
		const { container } = renderTip({ reviewCount: 1 });
		expect(container.querySelector('.ai-feedback-tip')).not.toBeNull();
		expect(speak).not.toHaveBeenCalled();
	});
});
