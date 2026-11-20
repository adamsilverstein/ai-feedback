/**
 * Tests for relinking notes to blocks loaded into a real block editor store.
 */

/**
 * External dependencies
 */
import { beforeAll, describe, expect, it } from 'vitest';

/**
 * WordPress dependencies
 */
import { createRegistry } from '@wordpress/data';
import { getBlockTypes, parse } from '@wordpress/blocks';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { registerCoreBlocks } from '@wordpress/block-library';

/**
 * Internal dependencies
 */
import { getNoteRelinks } from '../relink-notes';
import { extractBlockData, getBlockAnchor } from '../block-utils';

beforeAll(() => {
	if (!getBlockTypes().some((type) => type.name === 'core/quote')) {
		registerCoreBlocks();
	}
});

describe('getNoteRelinks on loaded blocks', () => {
	it('relinks a note made before its block was migrated from a deprecated format', () => {
		const registry = createRegistry();
		registry.register(blockEditorStore);
		// A quote in the old format, with its text in the quote's own markup.
		registry
			.dispatch(blockEditorStore)
			.resetBlocks(
				parse(
					'<!-- wp:quote --><blockquote class="wp-block-quote"><p>Old text.</p><cite>Someone</cite></blockquote><!-- /wp:quote -->'
				)
			);
		const blocks = registry.select(blockEditorStore).getBlocks();
		const [quote] = blocks;

		// Migrated: the text moved to an inner paragraph, so the quote's own
		// text, and so its current anchor, is the citation alone.
		expect(quote.innerBlocks).toHaveLength(1);
		expect(
			extractBlockData(blocks).find(
				({ clientId }) => clientId === quote.clientId
			).content
		).toBe('Someone');

		const note = {
			id: 7,
			parent: 0,
			block_name: 'core/quote',
			block_anchor: getBlockAnchor('core/quote', 'Old text.Someone'),
			review_id: 'review-1',
		};
		expect(getNoteRelinks(blocks, [note])).toEqual({
			[quote.clientId]: 7,
		});
	});

	it('links a block once when its current and loaded anchors both match notes', () => {
		const registry = createRegistry();
		registry.register(blockEditorStore);
		registry
			.dispatch(blockEditorStore)
			.resetBlocks(
				parse(
					'<!-- wp:quote --><blockquote class="wp-block-quote"><p>Old text.</p><cite>Someone</cite></blockquote><!-- /wp:quote -->'
				)
			);
		const blocks = registry.select(blockEditorStore).getBlocks();
		const [quote] = blocks;
		const note = (id, text) => ({
			id,
			parent: 0,
			block_name: 'core/quote',
			block_anchor: getBlockAnchor('core/quote', text),
			review_id: `review-${id}`,
		});

		const relinks = getNoteRelinks(blocks, [
			note(1, 'Old text.Someone'),
			note(2, 'Someone'),
		]);
		expect(Object.keys(relinks)).toEqual([quote.clientId]);
	});
});
