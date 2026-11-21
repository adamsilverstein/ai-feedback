/**
 * QuickReplies for a note whose formatting fix is the thread's edit, against
 * the real block editor store.
 *
 * External dependencies
 */
import { beforeAll, describe, it, expect, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

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
import QuickReplies from '../QuickReplies';

vi.mock('../../store', () => ({ STORE_NAME: 'ai-feedback/store' }));
const mockRequestReply = vi.fn(() => Promise.resolve(true));
vi.mock('../../hooks/useRequestReply', () => ({
	default: () => ({
		requestReply: mockRequestReply,
		isRequesting: false,
		canRequest: true,
	}),
}));
vi.mock('../../hooks/useSuggestionStatus', () => ({
	default: () => null,
	useHeldSuggestionStatus: () => null,
}));

beforeAll(() => {
	if (!getBlockTypes().some((type) => type.name === 'core/heading')) {
		registerCoreBlocks();
	}
});

const CHANGE = {
	type: 'set_attributes',
	attributes: { level: 3 },
	from: { level: 4 },
};

/**
 * Render the quick replies for a note on a heading at the given level.
 *
 * @param {number} level       Heading level.
 * @param {Object} [noteProps] Extra note fields.
 */
function renderChips(level, noteProps = {}) {
	const registry = createRegistry();
	registry.register(blocksStore);
	registry.register(blockEditorStore);
	registry.registerStore('ai-feedback/store', {
		reducer: (state = {}) => state,
		selectors: { isFixApplied: () => false },
	});
	const heading = createBlock('core/heading', { level, content: 'Setup' });
	registry.dispatch(blockEditorStore).resetBlocks([heading]);

	render(
		<RegistryProvider value={registry}>
			<QuickReplies
				note={{
					id: 7,
					block_id: heading.clientId,
					block_change: CHANGE,
					...noteProps,
				}}
				replies={[]}
			/>
		</RegistryProvider>
	);
}

const labels = () =>
	screen.getAllByRole('button').map((button) => button.textContent);

describe('QuickReplies with a formatting fix', () => {
	it('offers to refine a pending fix, without “Shorter”', () => {
		renderChips(4);

		expect(labels()).toEqual(['Try another', 'Why?']);
	});

	it('offers a new change once the fix is in the post', () => {
		renderChips(3);

		expect(labels()).toEqual([
			'Make that change',
			'Show me an example',
			'Why?',
		]);
	});
});

describe('QuickReplies in a thread a person started', () => {
	it('mentions Fresh Eyes, so the quick reply is answered', async () => {
		mockRequestReply.mockClear();
		renderChips(4, { is_mention: true });

		fireEvent.click(screen.getByRole('button', { name: 'Why?' }));

		await waitFor(() =>
			expect(mockRequestReply).toHaveBeenCalledWith(
				'@fresh-eyes Why do you suggest this?'
			)
		);
	});

	it('sends the message as it is in a thread Fresh Eyes started', async () => {
		mockRequestReply.mockClear();
		renderChips(4);

		fireEvent.click(screen.getByRole('button', { name: 'Why?' }));

		await waitFor(() =>
			expect(mockRequestReply).toHaveBeenCalledWith(
				'Why do you suggest this?'
			)
		);
	});
});
