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
import { navigateToBlock } from '../../utils/navigate-to-block';

// The real block editor store is heavy, so stand in a minimal one.
vi.mock('@wordpress/block-editor', async () => {
	const { createReduxStore: createStore } =
		await vi.importActual('@wordpress/data');
	return {
		store: createStore('core/block-editor', {
			reducer: (state = { blocks: [] }, action) =>
				action.type === 'SET_BLOCKS'
					? { ...state, blocks: action.blocks }
					: state,
			selectors: {
				getClientIdsWithDescendants: (state) => state.blocks,
			},
			actions: {
				setBlocks: (blocks) => ({ type: 'SET_BLOCKS', blocks }),
			},
		}),
	};
});
vi.mock('@wordpress/a11y', () => ({ speak: vi.fn() }));
vi.mock('../../utils/navigate-to-block', () => ({ navigateToBlock: vi.fn() }));
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
 * @param {Array<Object>} state.notes         Last review notes.
 * @param {Array<string>} state.blocks        Client IDs in document order.
 * @param {boolean}       state.withShortcut  Register the review shortcut.
 * @return {Object} The registry and render container.
 */
function renderTip({
	reviewCount = 0,
	dismissedTips = [],
	models = [],
	focusAreas = [],
	tones = [],
	notes = [],
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
				getLastReview: () => ({ notes }),
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

	it('goes to the topmost block with a note, not the top priority one', () => {
		navigateToBlock.mockClear();
		renderTip({
			reviewCount: 1,
			// Notes arrive sorted by priority, so the bottom block is first.
			notes: [
				{ block_id: 'block-3' },
				{ block_id: 'missing' },
				{ block_ids: ['block-2', 'block-3'] },
			],
			blocks: ['block-1', 'block-2', 'block-3'],
		});

		fireEvent.click(
			screen.getByRole('button', { name: 'Show first note' })
		);
		expect(navigateToBlock).toHaveBeenCalledWith('block-2');
	});

	it('hides the action when no noted block is in the editor', () => {
		renderTip({ reviewCount: 1, notes: [{ block_id: 'gone' }] });
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
