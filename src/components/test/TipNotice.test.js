/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';

/**
 * WordPress dependencies
 */
import {
	createRegistry,
	createReduxStore,
	RegistryProvider,
} from '@wordpress/data';

/**
 * Internal dependencies
 */
import TipNotice from '../TipNotice';

// Minimal stand-ins for the core stores TipNotice reads from.
jest.mock('@wordpress/preferences', () => {
	const { createReduxStore: createStore } =
		jest.requireActual('@wordpress/data');
	return {
		store: createStore('core/preferences', {
			reducer: (state = {}, action) =>
				action.type === 'SET'
					? {
							...state,
							[action.scope]: {
								...state[action.scope],
								[action.name]: action.value,
							},
						}
					: state,
			selectors: {
				get: (state, scope, name) => state[scope]?.[name],
			},
			actions: {
				set: (scope, name, value) => ({
					type: 'SET',
					scope,
					name,
					value,
				}),
			},
		}),
	};
});
jest.mock('@wordpress/block-editor', () => {
	const { createReduxStore: createStore } =
		jest.requireActual('@wordpress/data');
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
jest.mock('../../store', () => ({ STORE_NAME: 'ai-feedback/store' }));

const { store: preferencesStore } = jest.requireMock('@wordpress/preferences');
const { store: blockEditorStore } = jest.requireMock('@wordpress/block-editor');

/**
 * Render TipNotice against a fresh registry.
 *
 * @param {Object}        state               Fake store state.
 * @param {number}        state.reviewCount   Completed reviews.
 * @param {Array<string>} state.dismissedTips Dismissed tip IDs.
 * @param {Array<Object>} state.models        Available models.
 * @param {Object}        state.blockMapping  Last review block mapping.
 * @param {Array<string>} state.blocks        Client IDs in the editor.
 * @return {Object} The registry and render container.
 */
function renderTip({
	reviewCount = 0,
	dismissedTips = [],
	models = [],
	blockMapping = {},
	blocks = [],
}) {
	const registry = createRegistry();
	registry.register(preferencesStore);
	registry.register(
		createReduxStore('ai-feedback/store', {
			reducer: (state = {}) => state,
			selectors: {
				getAvailableModels: () => models,
				getLastReview: () => ({ block_mapping: blockMapping }),
			},
		})
	);
	registry.register(blockEditorStore);
	registry.dispatch(blockEditorStore).setBlocks(blocks);

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
});
