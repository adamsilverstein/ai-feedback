/**
 * Tests for structural edits that become Gutenberg suggestions.
 *
 * External dependencies
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * WordPress dependencies
 */
import { dispatch, select } from '@wordpress/data';

/**
 * Internal dependencies
 */
import {
	buildStructuralPayload,
	isStoredStructuralPayload,
	canSuggestBlockAction,
	hasSuggestionMarker,
	suggestBlockAction,
	suggestQuickFixes,
	supersedeSuggestions,
} from '../suggestions';

const mocks = vi.hoisted(() => ({
	order: {},
	blocks: {},
	saveEntityRecord: vi.fn(),
	updateBlockAttributes: vi.fn(),
	moveBlocksToPosition: vi.fn(),
	setSuggestionStatus: vi.fn(),
	createInfoNotice: vi.fn(),
	notes: null,
}));

vi.mock('@wordpress/api-fetch', () => ({ default: vi.fn() }));
vi.mock('@wordpress/block-editor', () => ({ store: 'core/block-editor' }));
vi.mock('@wordpress/notices', () => ({ store: 'core/notices' }));
vi.mock('@wordpress/blocks', () => ({
	store: 'core/blocks',
	createBlock: vi.fn(),
	serialize: (block) => `<p>${block.attributes.content ?? ''}</p>`,
}));

/**
 * Parent of a block in the mock tree.
 *
 * @param {string} id Client ID.
 * @return {string} Parent client ID, '' for the root.
 */
const parentOf = (id) =>
	Object.keys(mocks.order).find((parent) =>
		mocks.order[parent].includes(id)
	) ?? '';

vi.mock('@wordpress/data', async (importOriginal) => {
	const actual = await importOriginal();
	return {
		...actual,
		select: (store) => {
			switch (store) {
				case 'core/block-editor':
					return {
						getBlock: (id) => mocks.blocks[id] ?? null,
						getBlockAttributes: (id) =>
							mocks.blocks[id]?.attributes ?? null,
						getClientIdsWithDescendants: () =>
							Object.values(mocks.order).flat(),
						getBlockRootClientId: (id) => parentOf(id) || null,
						getBlockIndex: (id) =>
							mocks.order[parentOf(id)].indexOf(id),
						getBlockOrder: (parent = '') =>
							mocks.order[parent || ''] ?? [],
					};
				case 'core/blocks':
					return {
						getBlockType: () => ({
							attributes: { content: { type: 'rich-text' } },
						}),
					};
				case 'core/editor':
					return { getCurrentPostId: () => 42 };
				case 'core':
					return { getEntityRecords: () => mocks.notes };
				default:
					return actual.select(store);
			}
		},
		dispatch: (store) => {
			switch (store) {
				case 'core/block-editor':
					return {
						updateBlockAttributes: (id, attributes) => {
							mocks.updateBlockAttributes(id, attributes);
							mocks.blocks[id] = {
								...mocks.blocks[id],
								attributes: {
									...mocks.blocks[id].attributes,
									...attributes,
								},
							};
						},
						moveBlocksToPosition: (ids, from, to, index) => {
							mocks.moveBlocksToPosition(ids, from, to, index);
							mocks.order[from] = mocks.order[from].filter(
								(id) => !ids.includes(id)
							);
							mocks.order[to].splice(index, 0, ...ids);
						},
					};
				case 'core/notices':
					return { createInfoNotice: mocks.createInfoNotice };
				case 'ai-feedback/store':
					return { setSuggestionStatus: mocks.setSuggestionStatus };
				case 'core':
					return { saveEntityRecord: mocks.saveEntityRecord };
				default:
					return actual.dispatch(store);
			}
		},
	};
});

/**
 * Build a paragraph block for the mock tree.
 *
 * @param {string} clientId Client ID.
 * @param {string} content  Text.
 * @param {Object} metadata Block metadata.
 * @return {Object} Block.
 */
const paragraph = (clientId, content, metadata) => ({
	clientId,
	name: 'core/paragraph',
	attributes: { content, ...(metadata ? { metadata } : {}) },
	innerBlocks: [],
});

const savedRecord = async ({ id, meta }) => ({
	id,
	author: 0,
	meta: {
		_wp_suggestion: meta._wp_suggestion ?? '',
		_wp_suggestion_status: meta._wp_suggestion_status ?? '',
	},
});

const removeNote = {
	id: 12,
	block_id: 'rep',
	block_action: { type: 'remove_block' },
};
const moveNote = {
	id: 13,
	block_id: 'open',
	block_action: {
		type: 'move_block',
		position: 'before',
		target_block_id: 'intro',
		target_name: 'core/paragraph',
		target_text: 'Intro.',
	},
};

beforeEach(() => {
	window.__experimentalSuggestionMode = true;
	window.aiFeedbackData = { suggestions: true, structuralSuggestions: true };
	mocks.order = { '': ['intro', 'bg', 'open', 'rep'] };
	mocks.blocks = {
		intro: paragraph('intro', 'Intro.'),
		bg: paragraph('bg', 'Background.'),
		open: paragraph('open', 'The real opening.', { noteId: 13 }),
		rep: paragraph('rep', 'Repeats.', { noteId: 12 }),
	};
	mocks.notes = null;
	mocks.saveEntityRecord.mockImplementation((kind, name, record) =>
		savedRecord(record)
	);
});

afterEach(() => {
	delete window.__experimentalSuggestionMode;
	delete window.aiFeedbackData;
	vi.clearAllMocks();
});

describe('canSuggestBlockAction', () => {
	it('allows a review note that removes or moves its block', () => {
		expect(canSuggestBlockAction(removeNote)).toBe(true);
		expect(canSuggestBlockAction(moveNote)).toBe(true);
	});

	it('leaves inserts, replies, groups and decided notes to the sidebar', () => {
		expect(
			canSuggestBlockAction({
				...removeNote,
				block_action: {
					type: 'insert_block',
					position: 'after',
					block: { name: 'core/paragraph', content: 'x' },
				},
			})
		).toBe(false);
		expect(canSuggestBlockAction({ ...removeNote, parent: 5 })).toBe(false);
		expect(canSuggestBlockAction({ ...removeNote, is_group: true })).toBe(
			false
		);
		expect(
			canSuggestBlockAction({
				...removeNote,
				suggestion_status: 'pending',
			})
		).toBe(false);
		expect(
			canSuggestBlockAction({ ...removeNote, is_resolved: true })
		).toBe(false);
		expect(
			canSuggestBlockAction({ ...removeNote, block_action: null })
		).toBe(false);
	});
});

describe('suggestBlockAction', () => {
	it('tags a removal and stores its operation on the note', async () => {
		expect(await suggestBlockAction(removeNote)).toBe(true);

		const [record] = mocks.saveEntityRecord.mock.calls[0].slice(2);
		expect(record.id).toBe(12);
		expect(JSON.parse(record.meta._wp_suggestion)).toEqual(
			buildStructuralPayload('core/paragraph', {
				type: 'block-remove',
				clientId: 'rep',
				blockName: 'core/paragraph',
			})
		);
		expect(record.meta._wp_suggestion_status).toBe('pending');
		expect(mocks.blocks.rep.attributes.metadata).toEqual({
			noteId: 12,
			suggestion: { type: 'pending-remove', commentId: 12, authorId: 0 },
		});
		// The block stays until the suggestion is accepted.
		expect(mocks.order['']).toContain('rep');
		expect(mocks.setSuggestionStatus).toHaveBeenCalledWith(12, 'pending');
	});

	it('moves the block and records where it came from', async () => {
		expect(await suggestBlockAction(moveNote)).toBe(true);

		expect(mocks.moveBlocksToPosition).toHaveBeenCalledWith(
			['open'],
			'',
			'',
			0
		);
		expect(mocks.order['']).toEqual(['open', 'intro', 'bg', 'rep']);
		// Gutenberg's Reject reads the origin from the stored operation.
		const [record] = mocks.saveEntityRecord.mock.calls[0].slice(2);
		expect(JSON.parse(record.meta._wp_suggestion).operations).toEqual([
			{
				type: 'block-move',
				clientId: 'open',
				blockName: 'core/paragraph',
				fromParentClientId: null,
				fromIndex: 2,
				fromAnchorClientId: 'bg',
			},
		]);
		expect(mocks.blocks.open.attributes.metadata.suggestion).toEqual({
			type: 'pending-move',
			commentId: 13,
			authorId: 0,
			fromParentClientId: null,
			fromIndex: 2,
			fromAnchorClientId: 'bg',
		});
	});

	it('records the parent of a move inside a container', async () => {
		mocks.order = {
			'': ['group'],
			group: ['intro', 'bg', 'open', 'rep'],
		};
		mocks.blocks.group = paragraph('group', '');

		expect(await suggestBlockAction(moveNote)).toBe(true);

		expect(mocks.order.group).toEqual(['open', 'intro', 'bg', 'rep']);
		const [record] = mocks.saveEntityRecord.mock.calls[0].slice(2);
		const origin = {
			fromParentClientId: 'group',
			fromIndex: 2,
			fromAnchorClientId: 'bg',
		};
		expect(JSON.parse(record.meta._wp_suggestion).operations[0]).toEqual(
			expect.objectContaining(origin)
		);
		expect(mocks.blocks.open.attributes.metadata.suggestion).toEqual(
			expect.objectContaining(origin)
		);
	});

	it('turns the note back into a plain note when the server drops the payload', async () => {
		mocks.saveEntityRecord.mockImplementation(
			async (kind, name, { id }) => ({
				id,
				meta: { _wp_suggestion: '' },
			})
		);

		expect(await suggestBlockAction(removeNote)).toBe(false);
		expect(mocks.saveEntityRecord).toHaveBeenLastCalledWith(
			'root',
			'comment',
			{
				id: 12,
				meta: { _wp_suggestion: null, _wp_suggestion_status: null },
			},
			{ throwOnError: true }
		);
		expect(mocks.updateBlockAttributes).not.toHaveBeenCalled();
	});

	it('does nothing without Gutenberg’s structural suggestions', async () => {
		window.aiFeedbackData.structuralSuggestions = false;

		expect(await suggestBlockAction(removeNote)).toBe(false);
		expect(mocks.saveEntityRecord).not.toHaveBeenCalled();
	});

	it('falls back when the stored payload is not the one sent', async () => {
		mocks.saveEntityRecord.mockImplementation(
			async (kind, name, { id, meta }) => ({
				id,
				author: 0,
				meta: {
					_wp_suggestion: JSON.stringify({
						...JSON.parse(meta._wp_suggestion ?? '{}'),
						operations: [{ type: 'block-delete', clientId: 'rep' }],
					}),
				},
			})
		);

		expect(await suggestBlockAction(removeNote)).toBe(false);
		expect(mocks.saveEntityRecord).toHaveBeenLastCalledWith(
			'root',
			'comment',
			{
				id: 12,
				meta: { _wp_suggestion: null, _wp_suggestion_status: null },
			},
			{ throwOnError: true }
		);
		expect(mocks.updateBlockAttributes).not.toHaveBeenCalled();
	});

	it('leaves a block that already carries a structural suggestion alone', async () => {
		mocks.blocks.rep.attributes.metadata.suggestion = {
			type: 'pending-move',
		};

		expect(await suggestBlockAction(removeNote)).toBe(false);
		expect(mocks.saveEntityRecord).not.toHaveBeenCalled();
	});

	it('leaves a move into another parent block to Preview and Apply', async () => {
		mocks.blocks.group = { ...paragraph('group', ''), name: 'core/group' };
		mocks.blocks.inner = paragraph('inner', 'Inside.');
		mocks.order[''].push('group');
		mocks.order.group = ['inner'];

		expect(
			await suggestBlockAction({
				...moveNote,
				block_action: {
					...moveNote.block_action,
					target_block_id: 'inner',
					target_text: 'Inside.',
				},
			})
		).toBe(false);
		expect(mocks.saveEntityRecord).not.toHaveBeenCalled();
		expect(mocks.moveBlocksToPosition).not.toHaveBeenCalled();
	});

	it('skips a move whose target is gone', async () => {
		delete mocks.blocks.intro;
		mocks.order[''] = ['bg', 'open', 'rep'];

		expect(await suggestBlockAction(moveNote)).toBe(false);
		expect(mocks.saveEntityRecord).not.toHaveBeenCalled();
	});
});

describe('suggestQuickFixes', () => {
	it('suggests structural edits along with quick fixes', async () => {
		expect(await suggestQuickFixes([removeNote, moveNote])).toBe(2);
		expect(mocks.createInfoNotice).toHaveBeenCalledWith(
			'2 AI edits were added as suggestions. Save the post to keep them.',
			expect.anything()
		);
	});
});

describe('supersedeSuggestions', () => {
	// Stand-in for the registry `useRegistry()` gives the caller.
	const registry = {
		select,
		dispatch,
		batch: vi.fn((callback) => callback()),
	};

	beforeEach(() => {
		registry.batch.mockClear();
	});

	it('moves a superseded structural suggestion back and rejects it', async () => {
		await suggestBlockAction(moveNote);
		mocks.saveEntityRecord.mockClear();
		mocks.updateBlockAttributes.mockClear();
		mocks.moveBlocksToPosition.mockClear();
		// Both writes land inside the one batch.
		registry.batch.mockImplementationOnce((callback) => {
			expect(mocks.updateBlockAttributes).not.toHaveBeenCalled();
			expect(mocks.moveBlocksToPosition).not.toHaveBeenCalled();
			callback();
			expect(mocks.updateBlockAttributes).toHaveBeenCalledTimes(1);
			expect(mocks.moveBlocksToPosition).toHaveBeenCalledTimes(1);
		});

		expect(await supersedeSuggestions(13, [13], registry)).toEqual([13]);
		expect(registry.batch).toHaveBeenCalledTimes(1);
		expect(mocks.order['']).toEqual(['intro', 'bg', 'open', 'rep']);
		expect(mocks.blocks.open.attributes.metadata).toEqual({ noteId: 13 });
		expect(mocks.saveEntityRecord).toHaveBeenCalledWith(
			'root',
			'comment',
			{ id: 13, meta: { _wp_suggestion_status: 'rejected-unsaved' } },
			{ throwOnError: true }
		);
	});

	it('moves a superseded move back after the block it followed', async () => {
		await suggestBlockAction(moveNote);
		// The author adds a block at the top meanwhile.
		mocks.blocks.x = paragraph('x', 'New.');
		mocks.order[''].unshift('x');

		await supersedeSuggestions(13, [13], registry);
		expect(mocks.order['']).toEqual(['x', 'intro', 'bg', 'open', 'rep']);
	});

	it('moves a superseded move back within its parent when the recorded one is gone', async () => {
		await suggestBlockAction(moveNote);
		// A marker from an earlier session names a parent by a stale id.
		mocks.blocks.open.attributes.metadata.suggestion.fromParentClientId =
			'gone';

		expect(await supersedeSuggestions(13, [13], registry)).toEqual([13]);
		expect(registry.batch).toHaveBeenCalledTimes(1);
		expect(mocks.order['']).toEqual(['intro', 'bg', 'open', 'rep']);
		expect(mocks.blocks.open.attributes.metadata).toEqual({ noteId: 13 });
	});

	it('clears a superseded removal in the batch and keeps the block', async () => {
		await suggestBlockAction(removeNote);
		mocks.updateBlockAttributes.mockClear();
		mocks.moveBlocksToPosition.mockClear();
		registry.batch.mockImplementationOnce((callback) => {
			expect(mocks.updateBlockAttributes).not.toHaveBeenCalled();
			callback();
			expect(mocks.updateBlockAttributes).toHaveBeenCalledTimes(1);
		});

		expect(await supersedeSuggestions(12, [12], registry)).toEqual([12]);
		expect(registry.batch).toHaveBeenCalledTimes(1);
		expect(mocks.moveBlocksToPosition).not.toHaveBeenCalled();
		expect(mocks.blocks.rep.attributes.metadata).toEqual({ noteId: 12 });
		expect(mocks.order['']).toContain('rep');
	});
});

describe('hasSuggestionMarker', () => {
	it('finds a structural marker in serialized block attributes', () => {
		const html =
			'<!-- wp:paragraph {"metadata":{"suggestion":{"type":"pending-remove","commentId":12}}} --><p>x</p><!-- /wp:paragraph -->';
		expect(hasSuggestionMarker(html, 12)).toBe(true);
		expect(hasSuggestionMarker(html, 1)).toBe(false);
	});
});

describe('isStoredStructuralPayload', () => {
	const operation = {
		type: 'block-move',
		clientId: 'a',
		blockName: 'core/paragraph',
	};

	it('accepts the payload as sent and nothing else', () => {
		expect(
			isStoredStructuralPayload(
				JSON.stringify(
					buildStructuralPayload('core/paragraph', operation)
				),
				operation
			)
		).toBe(true);
		expect(isStoredStructuralPayload('', operation)).toBe(false);
		expect(isStoredStructuralPayload('{nope', operation)).toBe(false);
		expect(
			isStoredStructuralPayload(
				JSON.stringify({ schemaVersion: 3, operations: [operation] }),
				operation
			)
		).toBe(false);
	});
});
