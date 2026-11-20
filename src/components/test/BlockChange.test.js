/**
 * BlockChange against the real block editor store and core blocks.
 *
 * External dependencies
 */
import { beforeAll, describe, it, expect, vi } from 'vitest';
import { act, fireEvent, render, screen } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { createRegistry, RegistryProvider } from '@wordpress/data';
import {
	createBlock,
	getBlockTypes,
	store as blocksStore,
} from '@wordpress/blocks';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { registerCoreBlocks } from '@wordpress/block-library';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import BlockChange from '../BlockChange';
import FeedbackItemActions from '../FeedbackItemActions';

vi.mock('../../store', () => ({ STORE_NAME: 'ai-feedback/store' }));
vi.mock('../../utils/navigate-to-block', () => ({ navigateToBlock: vi.fn() }));
vi.mock('../../hooks/useRequestReply', () => ({
	default: () => ({ requestReply: vi.fn(), canRequest: false }),
}));
vi.mock('../../hooks/useSuggestionStatus', () => ({ default: () => null }));

beforeAll(() => {
	if (!getBlockTypes().some((type) => type.name === 'core/heading')) {
		registerCoreBlocks();
	}
});

const LEVEL = {
	type: 'set_attributes',
	attributes: { level: 3 },
	from: { level: 4 },
};

/**
 * Render a note's formatting fix against a post with one heading.
 *
 * @param {Object}  options         Options.
 * @param {number}  options.level   The heading's level.
 * @param {boolean} options.actions Render FeedbackItemActions instead.
 * @param {Object}  options.extra   Extra note fields.
 * @return {Object} Registry, heading and spies.
 */
function renderChange({ level = 4, actions = false, extra = {} } = {}) {
	const spies = {
		setNoteResolved: vi.fn(),
		setFixApplied: vi.fn(),
		undo: vi.fn(),
	};
	const registry = createRegistry();
	registry.register(blocksStore);
	registry.register(blockEditorStore);
	registry.register(noticesStore);
	registry.registerStore('core/editor', {
		reducer: (state = {}) => state,
		actions: {
			undo: () => {
				spies.undo();
				return { type: 'NOOP' };
			},
		},
		selectors: {},
	});
	registry.registerStore('ai-feedback/store', {
		reducer: (state = {}, action) =>
			action.type === 'APPLIED'
				? { ...state, [action.id]: action.applied }
				: state,
		actions: {
			setNoteResolved: (...args) => {
				spies.setNoteResolved(...args);
				return { type: 'NOOP' };
			},
			setFixApplied: (id, applied) => {
				spies.setFixApplied(id, applied);
				return { type: 'APPLIED', id, applied };
			},
		},
		selectors: { isFixApplied: (state, id) => !!state[id] },
	});
	const heading = createBlock('core/heading', { level, content: 'Setup' });
	registry.dispatch(blockEditorStore).resetBlocks([heading]);

	const item = {
		id: 7,
		block_id: heading.clientId,
		title: 'Fix heading level',
		feedback: 'This H4 follows an H2.',
		block_change: LEVEL,
		...extra,
	};
	render(
		<RegistryProvider value={registry}>
			{actions ? (
				<FeedbackItemActions item={item} title={item.title} />
			) : (
				<BlockChange item={item} />
			)}
		</RegistryProvider>
	);
	return { registry, heading, spies };
}

describe('BlockChange', () => {
	it('previews and applies a heading level fix, and resolves the note', () => {
		const { registry, heading, spies } = renderChange();

		expect(screen.getByText('Change to a level 3 heading')).toBeTruthy();
		fireEvent.click(screen.getByRole('button', { name: 'Preview' }));
		const preview = screen.getByRole('region', {
			name: 'Preview of the change',
		});
		expect(preview.textContent).toContain('H4 Setup');
		expect(preview.textContent).toContain('H3 Setup');

		act(() => {
			fireEvent.click(screen.getByRole('button', { name: 'Apply fix' }));
		});

		expect(
			registry.select(blockEditorStore).getBlock(heading.clientId)
				.attributes.level
		).toBe(3);
		expect(spies.setFixApplied).toHaveBeenCalledWith(7, true);
		expect(spies.setNoteResolved).toHaveBeenCalledWith(7, true);
		expect(screen.getByText('You applied this change.')).toBeTruthy();
	});

	it('reads as outdated once the heading changed, with Apply disabled', () => {
		const { registry, heading } = renderChange({ level: 5 });

		expect(
			screen.getByText(
				'This change is outdated: the block has changed since the review.'
			)
		).toBeTruthy();
		const apply = screen.getByRole('button', { name: 'Apply fix' });
		expect(apply.getAttribute('aria-disabled')).toBe('true');
		fireEvent.click(apply);
		expect(
			registry.select(blockEditorStore).getBlock(heading.clientId)
				.attributes.level
		).toBe(5);
	});

	it('shows in place of a quick fix in the note’s actions', () => {
		renderChange({
			actions: true,
			extra: { quick_fix: { find: 'Setup', replace: 'Set up' } },
		});

		expect(screen.getByText('Change to a level 3 heading')).toBeTruthy();
		expect(screen.queryByText(/Set up/)).toBeNull();
	});

	it('is not shown for a malformed change', () => {
		renderChange({
			actions: true,
			extra: { block_change: { type: 'set_style' } },
		});

		expect(screen.queryByRole('button', { name: 'Apply fix' })).toBeNull();
	});
});
