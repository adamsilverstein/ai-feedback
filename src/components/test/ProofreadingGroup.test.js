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
import ProofreadingGroup, {
	describeAcceptAll,
	getOwnThread,
} from '../ProofreadingGroup';

vi.mock('../../store', () => ({ STORE_NAME: 'ai-feedback/store' }));
// Each fix's own card is covered by FeedbackItem.test.js.
vi.mock('../FeedbackItem', () => ({
	default: ({ item }) => <div data-testid="feedback-item">{item.title}</div>,
}));

beforeAll(() => {
	if (!getBlockTypes().some((type) => type.name === 'core/paragraph')) {
		registerCoreBlocks();
	}
});

const note = (id, blockId, find, replace, extra = {}) => ({
	id,
	block_id: blockId,
	category: 'grammar',
	severity: 'suggestion',
	title: `${find} → ${replace}`,
	quick_fix: { find, replace },
	...extra,
});

/**
 * Render the group with the real block editor and notices stores, and a
 * feedback store that records what the group dispatches.
 *
 * @param {string[]}                       paragraphs HTML of each paragraph.
 * @param {(blocks: Object[]) => Object[]} makeItems  Builds the notes from the blocks.
 * @return {Object} Registry, blocks, recorded calls and render result.
 */
function renderGroup(paragraphs, makeItems) {
	const registry = createRegistry();
	registry.register(blocksStore);
	registry.register(blockEditorStore);
	registry.register(noticesStore);
	registry.dispatch(blocksStore).addBlockTypes(getBlockTypes());
	const blocks = paragraphs.map((content) =>
		createBlock('core/paragraph', { content })
	);
	registry.dispatch(blockEditorStore).resetBlocks(blocks);

	const calls = { resolved: [], applied: [], gate: null };
	registry.registerStore('ai-feedback/store', {
		reducer: (state = { applied: {} }, action) =>
			action.type === 'SET_FIX_APPLIED'
				? {
						applied: {
							...state.applied,
							[action.noteId]: action.isApplied,
						},
					}
				: state,
		selectors: {
			isFixApplied: (state, id) => !!state.applied[id],
		},
		actions: {
			setFixApplied: (noteId, isApplied) => {
				calls.applied.push([noteId, isApplied]);
				return { type: 'SET_FIX_APPLIED', noteId, isApplied };
			},
			setNoteResolved:
				(noteId, isResolved) =>
				async ({ dispatch }) => {
					calls.resolved.push(['start', noteId, isResolved]);
					await calls.gate?.[String(isResolved)];
					calls.resolved.push(['end', noteId, isResolved]);
					dispatch({ type: 'NOOP' });
				},
		},
	});
	registry.registerStore('core/editor', {
		reducer: (state = {}) => state,
		selectors: { getCurrentPostId: () => null },
	});

	const items = makeItems(blocks);
	const result = render(
		<RegistryProvider value={registry}>
			<ProofreadingGroup items={items} />
		</RegistryProvider>
	);
	return { registry, blocks, calls, ...result };
}

const text = (registry, block) =>
	String(
		registry.select(blockEditorStore).getBlock(block.clientId).attributes
			.content
	);

describe('describeAcceptAll', () => {
	it('reports what was applied and what no longer applies', () => {
		expect(describeAcceptAll(7, 1)).toBe(
			'7 fixes applied. 1 no longer applies.'
		);
		expect(describeAcceptAll(1, 0)).toBe('1 fix applied.');
		expect(describeAcceptAll(0, 2)).toBe(
			'No fixes could be applied. 2 no longer apply.'
		);
	});
});

describe('getOwnThread', () => {
	it('shows a thread only with its first note, without the other fixes', () => {
		const threads = {
			1: {
				replies: [{ id: 2 }, { id: 9, is_ai: true }],
				pending_reply_id: null,
			},
		};
		const ids = new Set([1, 2]);

		expect(getOwnThread(threads, { id: 1 }, ids)).toEqual({
			replies: [{ id: 9, is_ai: true }],
			pending_reply_id: null,
		});
		expect(getOwnThread(threads, { id: 2, parent: 1 }, ids)).toBe(
			undefined
		);
		expect(getOwnThread(threads, { id: 3 }, ids)).toBe(undefined);
	});
});

describe('ProofreadingGroup', () => {
	it('shows one row with the open fixes, and lists each fix when expanded', () => {
		renderGroup(['Teh cat ate alot of fish.', 'You recieve it.'], (b) => [
			note(1, b[0].clientId, 'Teh', 'The'),
			note(2, b[0].clientId, 'alot', 'a lot'),
			note(3, b[1].clientId, 'recieve', 'receive'),
			note(4, b[1].clientId, 'You', 'Yu'),
			note(5, b[1].clientId, 'it', 'that', { is_resolved: true }),
		]);

		expect(screen.getByText('4 proofreading fixes')).toBeInTheDocument();
		expect(screen.getByText('Teh → The')).toBeInTheDocument();
		expect(screen.getByText('… 1 more')).toBeInTheDocument();
		expect(screen.queryAllByTestId('feedback-item')).toHaveLength(0);

		fireEvent.click(screen.getByRole('button', { name: /proofreading/ }));
		// Every fix, dismissed ones included, can be acted on one by one.
		expect(screen.getAllByTestId('feedback-item')).toHaveLength(5);
	});

	it('applies every fix that still matches, resolves those notes and reports the rest', async () => {
		const { registry, blocks, calls } = renderGroup(
			['Teh <strong>cat</strong> ate alot.', 'You recieve it.'],
			(b) => [
				note(1, b[0].clientId, 'Teh', 'The'),
				note(2, b[0].clientId, 'alot', 'a lot'),
				note(3, b[1].clientId, 'recieve', 'receive'),
				note(4, b[1].clientId, 'nowhere', 'here'),
			]
		);

		await act(async () => {
			fireEvent.click(screen.getByRole('button', { name: 'Accept all' }));
		});

		expect(text(registry, blocks[0])).toBe(
			'The <strong>cat</strong> ate a lot.'
		);
		expect(text(registry, blocks[1])).toBe('You receive it.');
		expect(
			calls.resolved
				.filter(([step]) => step === 'end')
				.map(([, id, resolved]) => [id, resolved])
				.sort()
		).toEqual([
			[1, true],
			[2, true],
			[3, true],
		]);
		const [notice] = registry.select(noticesStore).getNotices();
		expect(notice.content).toBe('3 fixes applied. 1 no longer applies.');
		expect(screen.getByText('1 proofreading fix')).toBeInTheDocument();

		// Undo puts both blocks back and reopens the notes.
		await act(async () => {
			notice.actions[0].onClick();
		});
		expect(text(registry, blocks[0])).toBe(
			'Teh <strong>cat</strong> ate alot.'
		);
		expect(text(registry, blocks[1])).toBe('You recieve it.');
		expect(
			calls.resolved.filter(
				([step, , resolved]) => step === 'end' && !resolved
			)
		).toHaveLength(3);
		expect(screen.getByText('4 proofreading fixes')).toBeInTheDocument();
	});

	it('waits for the notes to resolve before Undo reopens them', async () => {
		let release;
		const { registry, blocks, calls } = renderGroup(['Teh cat.'], (b) => [
			note(1, b[0].clientId, 'Teh', 'The'),
		]);
		calls.gate = { true: new Promise((resolve) => (release = resolve)) };

		await act(async () => {
			fireEvent.click(screen.getByRole('button', { name: 'Accept all' }));
		});
		const [notice] = registry.select(noticesStore).getNotices();
		await act(async () => {
			notice.actions[0].onClick();
		});
		// The text is back, but the note is not reopened while resolving runs.
		expect(text(registry, blocks[0])).toBe('Teh cat.');
		expect(calls.resolved).toEqual([['start', 1, true]]);

		await act(async () => {
			release();
		});
		expect(calls.resolved).toEqual([
			['start', 1, true],
			['end', 1, true],
			['start', 1, false],
			['end', 1, false],
		]);
	});

	it('disables Accept all once nothing is left', () => {
		renderGroup(['Fine.'], (b) => [
			note(1, b[0].clientId, 'Teh', 'The', { is_resolved: true }),
		]);

		expect(
			screen.getByText('Proofreading fixes: all done')
		).toBeInTheDocument();
		expect(
			screen.getByRole('button', { name: 'Accept all' })
		).toHaveAttribute('aria-disabled', 'true');
	});
});
