/**
 * Structural edits against the real block editor store and core blocks.
 *
 * A move into the moved block's own children never returns from the block
 * editor, so these tests have a timeout and fail instead of hanging.
 *
 * External dependencies
 */
import { beforeAll, describe, expect, it } from 'vitest';

/**
 * WordPress dependencies
 */
import { createRegistry } from '@wordpress/data';
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
import { applyBlockAction, getBlockActionState } from '../block-action';

beforeAll(() => {
	if (!getBlockTypes().some((type) => type.name === 'core/group')) {
		registerCoreBlocks();
	}
});

/**
 * A post with a group holding two paragraphs, then a paragraph.
 *
 * @return {{registry: Object, group: Object, first: Object, after: Object}} Registry and blocks.
 */
function setUp() {
	const registry = createRegistry();
	registry.register(blocksStore);
	registry.register(blockEditorStore);
	const first = createBlock('core/paragraph', { content: 'First inside.' });
	const second = createBlock('core/paragraph', {
		content: 'Second inside.',
	});
	const group = createBlock('core/group', {}, [first, second]);
	const after = createBlock('core/paragraph', { content: 'After.' });
	registry.dispatch(blockEditorStore).resetBlocks([group, after]);
	// The editor sets this once the group's inner blocks render; without
	// it the block editor refuses any move into the group anyway.
	registry
		.dispatch(blockEditorStore)
		.updateBlockListSettings(group.clientId, {});
	return { registry, group, first, after };
}

const moveNextTo = (target, position) => ({
	type: 'move_block',
	position,
	target_block_id: target.clientId,
	target_name: target.name,
	target_text: target.attributes.content,
});

describe('structural edits with the real block editor', () => {
	it('never moves a block into its own children', () => {
		const { registry, group, first } = setUp();
		const selectors = registry.select(blockEditorStore);
		const action = moveNextTo(first, 'after');

		expect(getBlockActionState(selectors, action, group.clientId)).toEqual({
			state: 'outdated',
			targetId: null,
		});
		expect(
			applyBlockAction(
				selectors,
				registry.dispatch(blockEditorStore),
				action,
				group.clientId
			)
		).toBe(false);
		expect(selectors.getBlockOrder()).toEqual([
			group.clientId,
			expect.any(String),
		]);
	}, 10000);

	it('moves a block out of its group, next to the group', () => {
		const { registry, group, first, after } = setUp();
		const selectors = registry.select(blockEditorStore);
		const action = moveNextTo(after, 'before');
		const { state } = getBlockActionState(
			selectors,
			action,
			first.clientId
		);

		expect(state).toBe('ready');
		expect(
			applyBlockAction(
				selectors,
				registry.dispatch(blockEditorStore),
				action,
				first.clientId
			)
		).toBe(true);
		expect(selectors.getBlockOrder()).toEqual([
			group.clientId,
			first.clientId,
			after.clientId,
		]);
	}, 10000);

	it('keeps a group whose inner block is linked to another note', () => {
		const { registry, group, first } = setUp();
		const editor = registry.dispatch(blockEditorStore);
		editor.updateBlockAttributes(first.clientId, {
			metadata: { noteId: 99 },
		});
		const selectors = registry.select(blockEditorStore);

		expect(
			getBlockActionState(
				selectors,
				{ type: 'remove_block' },
				group.clientId
			)
		).toMatchObject({ state: 'blocked', inside: 2 });
		expect(
			applyBlockAction(
				selectors,
				editor,
				{ type: 'remove_block' },
				group.clientId
			)
		).toBe(false);
		expect(selectors.getBlock(first.clientId)).not.toBeNull();
	}, 10000);
});
