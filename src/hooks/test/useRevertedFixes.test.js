/**
 * External dependencies
 */
import { beforeAll, describe, expect, it, vi } from 'vitest';
import { act, render } from '@testing-library/react';

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

/**
 * Internal dependencies
 */
import useRevertedFixes from '../useRevertedFixes';
import { acceptAllFixes, trackSaves } from '../../utils/proofreading';

vi.mock('../../store', () => ({ STORE_NAME: 'ai-feedback/store' }));

beforeAll(() => {
	if (!getBlockTypes().some((type) => type.name === 'core/paragraph')) {
		registerCoreBlocks();
	}
});

const note = (id, blockId, find, replace) => ({
	id,
	block_id: blockId,
	category: 'grammar',
	quick_fix: { find, replace },
});

function Probe({ items }) {
	useRevertedFixes(items);
	return null;
}

/**
 * A registry with one paragraph and a feedback store that records calls.
 *
 * @param {string} content Paragraph HTML.
 * @return {Object} Registry, block and recorded calls.
 */
function setUp(content) {
	const registry = createRegistry();
	registry.register(blocksStore);
	registry.register(blockEditorStore);
	registry.dispatch(blocksStore).addBlockTypes(getBlockTypes());
	const block = createBlock('core/paragraph', { content });
	registry.dispatch(blockEditorStore).resetBlocks([block]);

	const calls = [];
	registry.registerStore('ai-feedback/store', {
		reducer: (state = {}, action) =>
			action.type === 'SET_FIX_APPLIED'
				? { ...state, [action.noteId]: action.isApplied }
				: state,
		selectors: { isFixApplied: (state, id) => !!state[id] },
		actions: {
			setFixApplied: (noteId, isApplied) => {
				calls.push(['applied', noteId, isApplied]);
				return { type: 'SET_FIX_APPLIED', noteId, isApplied };
			},
			setNoteResolved: (noteId, isResolved) => {
				calls.push(['resolved', noteId, isResolved]);
				return { type: 'NOOP' };
			},
		},
	});
	return { registry, block, calls };
}

describe('useRevertedFixes', () => {
	it('reopens applied fixes once the editor’s Undo brings their text back', async () => {
		const { registry, block, calls } = setUp('Teh cat ate alot.');
		const items = [
			note(1, block.clientId, 'Teh', 'The'),
			note(2, block.clientId, 'alot', 'a lot'),
			// Dismissed, not applied: its text staying put changes nothing.
			note(3, block.clientId, 'cat', 'dog'),
		];
		const plan = acceptAllFixes(registry, items.slice(0, 2));
		plan.applied.forEach((id) =>
			registry.dispatch('ai-feedback/store').setFixApplied(id, true)
		);
		let release;
		trackSaves([1, 2], new Promise((resolve) => (release = resolve)));
		calls.length = 0;

		render(
			<RegistryProvider value={registry}>
				<Probe items={items} />
			</RegistryProvider>
		);
		expect(calls).toEqual([]);

		// The editor's Undo, not the snackbar's.
		await act(async () => {
			registry
				.dispatch(blockEditorStore)
				.updateBlockAttributes(block.clientId, {
					content: 'Teh cat ate alot.',
				});
		});
		expect(calls).toEqual([
			['applied', 1, false],
			['applied', 2, false],
		]);

		// Reopening waits for the save that resolved the notes.
		await act(async () => {
			release();
		});
		expect(calls).toEqual([
			['applied', 1, false],
			['applied', 2, false],
			['resolved', 1, false],
			['resolved', 2, false],
		]);
	});
});
