/**
 * Structural suggestions while Gutenberg's Suggestion Mode is in Suggest
 * intent, against the real block editor store.
 *
 * In Suggest intent Gutenberg's store interceptor watches the block editor
 * and records any move it sees as a pending suggestion by the current user.
 * `captureSuggestIntent()` below is a model of that interceptor, taken from
 * `packages/editor/src/components/suggestion-mode/store-interceptor.ts` on
 * Gutenberg's `suggest/block-capture` branch (PR 80429):
 *
 * - Kept as in Gutenberg: the synchronous subscribe on the block editor, the
 *   reentrancy gate around its own writes, the previous-tick tree snapshot,
 *   move detection (parent change, else selection-first then LCS within a
 *   parent), the "reject landing" skip, the kept `from*` fields of a block
 *   already marked `pending-move`, the `pending-move` marker write, one
 *   recorded operation per block (`setStructuralOp`), and system metadata
 *   (`noteId`, `suggestion`) being adopted instead of captured.
 * - Simplified: any other attribute change is recorded as one capture
 *   rather than diffed, overlaid and reverted, and auto-save is left out:
 *   every recorded operation becomes a pending note after its debounce.
 * - Left out: inserted and removed blocks, pending insertions, undo and
 *   redo adoption.
 *
 * External dependencies
 */
import { afterEach, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { renderHook } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import {
	createReduxStore,
	dispatch,
	register,
	select,
	subscribe,
	useRegistry,
} from '@wordpress/data';
import {
	createBlock,
	getBlockType,
	registerBlockType,
} from '@wordpress/blocks';
import { store as blockEditorStore } from '@wordpress/block-editor';

/**
 * Internal dependencies
 */
import { applyBlockAction } from '../block-action';
import { suggestBlockAction, supersedeSuggestions } from '../suggestions';

const CURRENT_USER = 7;

/**
 * Notes saved through the stub core-data store, by id.
 */
const savedNotes = new Map();

beforeAll(() => {
	if (!getBlockType('core/paragraph')) {
		registerBlockType('core/paragraph', {
			apiVersion: 3,
			title: 'Paragraph',
			category: 'text',
			attributes: { content: { type: 'rich-text' } },
			edit: () => null,
			save: () => null,
		});
	}
	register(
		createReduxStore('core', {
			reducer: (state = {}) => state,
			actions: {
				saveEntityRecord:
					(kind, name, { id, meta }) =>
					async () => {
						const note = {
							...(savedNotes.get(id) ?? { id }),
							author: CURRENT_USER,
						};
						note.meta = { ...note.meta, ...meta };
						savedNotes.set(id, note);
						return note;
					},
			},
		})
	);
	register(
		createReduxStore('ai-feedback/store', {
			reducer: (state = {}) => state,
			actions: {
				setSuggestionStatus: (id, status) => ({
					type: 'SET_SUGGESTION_STATUS',
					id,
					status,
				}),
			},
		})
	);
});

/**
 * Longest common subsequence of two client id lists, as a set. Copied from
 * Gutenberg's `lcsClientIds()`, including its tie-break.
 *
 * @param {string[]} a Old order.
 * @param {string[]} b New order.
 * @return {Set<string>} Ids in the LCS.
 */
function lcsClientIds(a, b) {
	const dp = Array.from({ length: a.length + 1 }, () =>
		new Array(b.length + 1).fill(0)
	);
	for (let i = 1; i <= a.length; i++) {
		for (let j = 1; j <= b.length; j++) {
			dp[i][j] =
				a[i - 1] === b[j - 1]
					? dp[i - 1][j - 1] + 1
					: Math.max(dp[i - 1][j], dp[i][j - 1]);
		}
	}
	const result = new Set();
	let i = a.length;
	let j = b.length;
	while (i > 0 && j > 0) {
		if (a[i - 1] === b[j - 1]) {
			result.add(a[i - 1]);
			i--;
			j--;
		} else if (dp[i - 1][j] > dp[i][j - 1]) {
			i--;
		} else {
			j--;
		}
	}
	return result;
}

/**
 * Siblings that stayed put. Gutenberg's `stableSiblingSet()`: the selected
 * blocks are the movers when that explains the reorder, else the LCS.
 *
 * @param {string[]}    oldSiblings Old order.
 * @param {string[]}    newSiblings New order.
 * @param {Set<string>} selected    Selected client ids.
 * @return {Set<string>} Ids that did not move.
 */
function stableSiblingSet(oldSiblings, newSiblings, selected) {
	if (selected.size > 0 && oldSiblings.some((id) => selected.has(id))) {
		const oldRest = oldSiblings.filter((id) => !selected.has(id));
		const newRest = newSiblings.filter((id) => !selected.has(id));
		if (
			oldRest.length === newRest.length &&
			oldRest.every((id, i) => id === newRest[i])
		) {
			const stable = new Set(oldRest);
			newSiblings.forEach((id, i) => {
				if (selected.has(id) && oldSiblings[i] === id) {
					stable.add(id);
				}
			});
			return stable;
		}
	}
	return lcsClientIds(oldSiblings, newSiblings);
}

/**
 * Parent, index and block of every block, as Gutenberg's
 * `captureTreeSnapshot()` keeps them.
 *
 * @param {Object} editor Block editor selectors.
 * @return {Object} Tree snapshot.
 */
function captureTree(editor) {
	const blocks = new Map();
	const parents = new Map();
	const indexes = new Map();
	const walk = (ids, parent) =>
		ids.forEach((id, index) => {
			blocks.set(id, editor.getBlock(id));
			parents.set(id, parent);
			indexes.set(id, index);
			walk(editor.getBlockOrder(id), id);
		});
	walk(editor.getBlockOrder(), null);
	return { blocks, parents, indexes };
}

/**
 * Gutenberg's `detectMovedBlocks()`.
 *
 * @param {Object} editor Block editor selectors.
 * @param {Object} tree   Previous-tick tree.
 * @return {Object[]} One entry per moved block.
 */
function detectMovedBlocks(editor, tree) {
	const moved = [];
	const byParent = new Map();
	for (const id of editor.getClientIdsWithDescendants()) {
		if (!tree.parents.has(id)) {
			continue;
		}
		const oldParent = tree.parents.get(id);
		const newParent = editor.getBlockRootClientId(id) || null;
		if (oldParent !== newParent) {
			moved.push({ id, oldParent, newParent });
			continue;
		}
		byParent.set(newParent, [...(byParent.get(newParent) ?? []), id]);
	}
	const selected = new Set(editor.getSelectedBlockClientIds());
	for (const [parent, ids] of byParent) {
		const oldSiblings = [...ids].sort(
			(a, b) => tree.indexes.get(a) - tree.indexes.get(b)
		);
		const newSiblings = editor
			.getBlockOrder(parent ?? undefined)
			.filter((id) => ids.includes(id));
		if (oldSiblings.every((id, i) => id === newSiblings[i])) {
			continue;
		}
		const stable = stableSiblingSet(oldSiblings, newSiblings, selected);
		for (const id of newSiblings) {
			if (!stable.has(id)) {
				moved.push({ id, oldParent: parent, newParent: parent });
			}
		}
	}
	return moved.map(({ id, oldParent, newParent }) => {
		const fromIndex = tree.indexes.get(id);
		const oldOrder = [...tree.parents]
			.filter(([, parent]) => parent === oldParent)
			.map(([sibling]) => sibling)
			.sort((a, b) => tree.indexes.get(a) - tree.indexes.get(b));
		const newOrder = editor.getBlockOrder(newParent ?? undefined);
		const toIndex = newOrder.indexOf(id);
		return {
			clientId: id,
			fromParentClientId: oldParent,
			fromAnchorClientId: fromIndex > 0 ? oldOrder[fromIndex - 1] : null,
			fromIndex,
			toParentClientId: newParent,
			toAnchorClientId: toIndex > 0 ? newOrder[toIndex - 1] : null,
		};
	});
}

/**
 * Attributes other than the system metadata Gutenberg adopts silently.
 *
 * @param {Object} attributes Block attributes.
 * @return {Object} Attributes without `metadata.noteId` and `metadata.suggestion`.
 */
function userAttributes(attributes) {
	const { noteId, suggestion, ...metadata } = attributes?.metadata ?? {};
	return { ...attributes, metadata };
}

/**
 * Starts the model of Gutenberg's Suggest-intent interceptor.
 *
 * @return {{captures: Map<string, Object>, stop: () => void}} Operations recorded per block, and a function that stops it.
 */
function captureSuggestIntent() {
	const editor = select(blockEditorStore);
	const editorDispatch = dispatch(blockEditorStore);
	const captures = new Map();
	const snapshot = new Map(
		editor
			.getClientIdsWithDescendants()
			.map((id) => [id, editor.getBlockAttributes(id)])
	);
	let tree = captureTree(editor);
	let isDispatchingOwnWrite = false;

	const stop = subscribe(() => {
		if (isDispatchingOwnWrite) {
			return;
		}
		for (const id of editor.getClientIdsWithDescendants()) {
			const previous = snapshot.get(id);
			const current = editor.getBlockAttributes(id);
			if (
				previous &&
				previous !== current &&
				JSON.stringify(userAttributes(previous)) !==
					JSON.stringify(userAttributes(current))
			) {
				captures.set(id, { type: 'attributes' });
			}
			snapshot.set(id, current);
		}

		for (const move of detectMovedBlocks(editor, tree)) {
			const attributes = editor.getBlockAttributes(move.clientId);
			const tracked = tree.blocks.get(move.clientId)?.attributes?.metadata
				?.suggestion?.type;
			const marker = attributes.metadata?.suggestion;
			// Reject landing: the marker went with the move back.
			if (tracked === 'pending-move' && marker?.type !== 'pending-move') {
				continue;
			}
			const from =
				marker?.type === 'pending-move'
					? {
							fromAnchorClientId:
								marker.fromAnchorClientId ?? null,
							fromParentClientId:
								marker.fromParentClientId ?? null,
							fromIndex: marker.fromIndex ?? 0,
						}
					: {
							fromAnchorClientId: move.fromAnchorClientId,
							fromParentClientId: move.fromParentClientId,
							fromIndex: move.fromIndex,
						};
			isDispatchingOwnWrite = true;
			try {
				editorDispatch.__unstableMarkNextChangeAsNotPersistent();
				editorDispatch.updateBlockAttributes(move.clientId, {
					metadata: {
						...(attributes.metadata || {}),
						suggestion: {
							type: 'pending-move',
							authorId: CURRENT_USER,
							...from,
						},
					},
				});
			} finally {
				isDispatchingOwnWrite = false;
			}
			captures.set(move.clientId, {
				type: 'block-move',
				clientId: move.clientId,
				...from,
				toAnchorClientId: move.toAnchorClientId,
				toParentClientId: move.toParentClientId,
			});
		}
		tree = captureTree(editor);
	}, blockEditorStore);

	return { captures, stop };
}

/**
 * Pending suggestions the post would end up with: the notes the plugin
 * marked pending, plus one Gutenberg note per recorded operation.
 *
 * @param {Map<string, Object>} captures Operations Gutenberg recorded.
 * @return {number} Pending suggestions.
 */
function pendingSuggestions(captures) {
	const notes = [...savedNotes.values()].filter(
		(note) => note.meta?._wp_suggestion_status === 'pending'
	);
	return notes.length + captures.size;
}

let blocks;
let interceptor;

beforeEach(() => {
	window.__experimentalSuggestionMode = true;
	window.aiFeedbackData = { suggestions: true, structuralSuggestions: true };
	savedNotes.clear();
	blocks = {
		intro: createBlock('core/paragraph', { content: 'Intro.' }),
		bg: createBlock('core/paragraph', { content: 'Background.' }),
		open: createBlock('core/paragraph', {
			content: 'The real opening.',
			metadata: { noteId: 13 },
		}),
		rep: createBlock('core/paragraph', {
			content: 'Repeats.',
			metadata: { noteId: 12 },
		}),
	};
	dispatch(blockEditorStore).resetBlocks([
		blocks.intro,
		blocks.bg,
		blocks.open,
		blocks.rep,
	]);
	interceptor = null;
});

afterEach(() => {
	interceptor?.stop();
	delete window.__experimentalSuggestionMode;
	delete window.aiFeedbackData;
});

/**
 * A review note that moves the opening paragraph next to another block.
 *
 * @param {string} target   Key of the target block.
 * @param {string} position `before` or `after`.
 * @return {Object} Note.
 */
const moveNote = (target, position = 'before') => ({
	id: 13,
	block_id: blocks.open.clientId,
	block_action: {
		type: 'move_block',
		position,
		target_block_id: blocks[target].clientId,
		target_name: 'core/paragraph',
		target_text: blocks[target].attributes.content,
	},
});

const removeNote = () => ({
	id: 12,
	block_id: blocks.rep.clientId,
	block_action: { type: 'remove_block' },
});

const order = () =>
	select(blockEditorStore)
		.getBlockOrder()
		.map((id) =>
			Object.keys(blocks).find((key) => blocks[key].clientId === id)
		);

describe('structural suggestions in Edit intent', () => {
	it('records a move once, as the note’s suggestion', async () => {
		expect(await suggestBlockAction(moveNote('intro'))).toBe(true);

		expect(order()).toEqual(['open', 'intro', 'bg', 'rep']);
		expect(
			select(blockEditorStore).getBlockAttributes(blocks.open.clientId)
				.metadata.suggestion
		).toMatchObject({ type: 'pending-move', commentId: 13 });
		expect(pendingSuggestions(new Map())).toBe(1);
	});

	it('still suggests the move when something else rewrites a marker as it lands', async () => {
		// Another editor extension updates a neighbor's marker on the move.
		const stop = subscribe(() => {
			const attributes = select(blockEditorStore).getBlockAttributes(
				blocks.bg.clientId
			);
			if (!attributes.metadata?.suggestion) {
				dispatch(blockEditorStore).updateBlockAttributes(
					blocks.bg.clientId,
					{
						metadata: {
							...attributes.metadata,
							suggestion: {
								type: 'pending-remove',
								commentId: 99,
							},
						},
					}
				);
			}
		}, blockEditorStore);

		try {
			expect(await suggestBlockAction(moveNote('intro'))).toBe(true);
		} finally {
			stop();
		}

		expect(
			select(blockEditorStore).getBlockAttributes(blocks.open.clientId)
				.metadata.suggestion
		).toMatchObject({ type: 'pending-move', commentId: 13 });
		expect(savedNotes.get(13).meta._wp_suggestion_status).toBe('pending');
	});
});

describe('structural suggestions in Suggest intent', () => {
	beforeEach(() => {
		interceptor = captureSuggestIntent();
	});

	it('records a removal once, as the note’s suggestion', async () => {
		expect(await suggestBlockAction(removeNote())).toBe(true);

		expect(interceptor.captures.size).toBe(0);
		expect(pendingSuggestions(interceptor.captures)).toBe(1);
	});

	it('records a move once', async () => {
		await suggestBlockAction(moveNote('intro'));

		expect(order()).toEqual(['open', 'intro', 'bg', 'rep']);
		expect(pendingSuggestions(interceptor.captures)).toBe(1);
	});

	it('records a move to the next block over once, though Gutenberg tags the neighbor', async () => {
		await suggestBlockAction(moveNote('bg'));

		expect(order()).toEqual(['intro', 'open', 'bg', 'rep']);
		// Without a selection, Gutenberg reads the swap as `bg` moving down.
		expect([...interceptor.captures.keys()]).toEqual([blocks.bg.clientId]);
		expect(pendingSuggestions(interceptor.captures)).toBe(1);
	});

	it('leaves Gutenberg’s capture to hold the move', async () => {
		expect(await suggestBlockAction(moveNote('intro'))).toBe(false);

		expect(savedNotes.get(13).meta).toMatchObject({
			_wp_suggestion: null,
			_wp_suggestion_status: null,
		});
		expect(
			select(blockEditorStore).getBlockAttributes(blocks.open.clientId)
				.metadata.suggestion
		).toMatchObject({ type: 'pending-move', authorId: CURRENT_USER });
		expect(interceptor.captures.get(blocks.open.clientId)).toMatchObject({
			type: 'block-move',
			fromIndex: 2,
			fromAnchorClientId: blocks.bg.clientId,
		});
	});
});

describe('retiring a move in Suggest intent', () => {
	// The registry the editor's components get from `useRegistry()`.
	const registry = () => renderHook(() => useRegistry()).result.current;

	beforeEach(async () => {
		// The review ran in Edit intent, so the move is the note's own.
		await suggestBlockAction(moveNote('intro'));
		interceptor = captureSuggestIntent();
	});

	it('puts the block back without Gutenberg recording the move back', async () => {
		expect(await supersedeSuggestions(13, [13], registry())).toEqual([13]);

		expect(order()).toEqual(['intro', 'bg', 'open', 'rep']);
		expect(
			select(blockEditorStore).getBlockAttributes(blocks.open.clientId)
				.metadata
		).toEqual({ noteId: 13 });
		expect(interceptor.captures.size).toBe(0);
		expect(pendingSuggestions(interceptor.captures)).toBe(0);
	});

	it('leaves a revised move as the only suggestion, from where the block started', async () => {
		await supersedeSuggestions(13, [13], registry());

		// A reply's revised move, applied from the sidebar.
		expect(
			applyBlockAction(
				select(blockEditorStore),
				dispatch(blockEditorStore),
				moveNote('rep', 'after').block_action,
				blocks.open.clientId
			)
		).toBe(true);

		expect(order()).toEqual(['intro', 'bg', 'rep', 'open']);
		expect(pendingSuggestions(interceptor.captures)).toBe(1);
		expect(interceptor.captures.get(blocks.open.clientId)).toMatchObject({
			type: 'block-move',
			fromIndex: 2,
			fromAnchorClientId: blocks.bg.clientId,
		});
	});
});
