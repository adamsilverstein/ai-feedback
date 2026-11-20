/**
 * External dependencies
 */
import { beforeAll, describe, expect, it, vi } from 'vitest';
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
import { store as noticesStore } from '@wordpress/notices';
import { registerCoreBlocks } from '@wordpress/block-library';

/**
 * Internal dependencies
 */
import QuickFix from '../QuickFix';
import { getAppliedState } from '../../utils/proofreading';

vi.mock('../../store', () => ({ STORE_NAME: 'ai-feedback/store' }));

beforeAll(() => {
	if (!getBlockTypes().some((type) => type.name === 'core/paragraph')) {
		registerCoreBlocks();
	}
});

describe('QuickFix', () => {
	it('records the block content around a fix it applies, and forgets it on Undo', async () => {
		const registry = createRegistry();
		registry.register(blocksStore);
		registry.register(blockEditorStore);
		registry.register(noticesStore);
		registry.dispatch(blocksStore).addBlockTypes(getBlockTypes());
		const block = createBlock('core/paragraph', {
			content: 'Read data now.',
		});
		registry.dispatch(blockEditorStore).resetBlocks([block]);
		registry.registerStore('ai-feedback/store', {
			reducer: (state = {}, action) =>
				action.type === 'SET_FIX_APPLIED'
					? { ...state, [action.noteId]: action.isApplied }
					: state,
			selectors: { isFixApplied: (state, id) => !!state[id] },
			actions: {
				setFixApplied: (noteId, isApplied) => ({
					type: 'SET_FIX_APPLIED',
					noteId,
					isApplied,
				}),
				setNoteResolved: () => ({ type: 'NOOP' }),
			},
		});
		registry.registerStore('core/editor', {
			reducer: (state = {}) => state,
			selectors: { getCurrentPostId: () => null },
		});
		const getValue = ({ clientId, key }) =>
			registry.select(blockEditorStore).getBlock(clientId).attributes[
				key
			];

		render(
			<RegistryProvider value={registry}>
				<QuickFix
					item={{
						id: 31,
						block_id: block.clientId,
						quick_fix: { find: 'data', replace: 'the data' },
					}}
				/>
			</RegistryProvider>
		);
		await act(async () => {
			fireEvent.click(screen.getByRole('button', { name: 'Apply fix' }));
		});

		expect(
			String(getValue({ clientId: block.clientId, key: 'content' }))
		).toBe('Read the data now.');
		expect(getAppliedState(31, getValue)).toBe('after');

		const [notice] = registry.select(noticesStore).getNotices();
		await act(async () => {
			notice.actions[0].onClick();
		});
		expect(
			String(getValue({ clientId: block.clientId, key: 'content' }))
		).toBe('Read data now.');
		expect(getAppliedState(31, getValue)).toBeNull();
	});
});
