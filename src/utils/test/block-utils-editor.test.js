/**
 * Tests for extractBlockData on blocks from a real block editor store.
 */

/**
 * External dependencies
 */
import { beforeAll, describe, expect, it } from 'vitest';

/**
 * WordPress dependencies
 */
import { createRegistry } from '@wordpress/data';
import { createBlock, getBlockTypes, parse } from '@wordpress/blocks';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { registerCoreBlocks } from '@wordpress/block-library';

/**
 * Internal dependencies
 */
import { extractBlockData, hasTextContent } from '../block-utils';

beforeAll(() => {
	if (!getBlockTypes().some((type) => type.name === 'core/paragraph')) {
		registerCoreBlocks();
	}
});

/**
 * Load blocks into a fresh block editor store, as the editor does for a
 * saved post.
 *
 * @param {Array} blocks Blocks.
 * @return {Object} Registry.
 */
function loadEditor(blocks) {
	const registry = createRegistry();
	registry.register(blockEditorStore);
	registry.dispatch(blockEditorStore).resetBlocks(blocks);
	return registry;
}

describe('extractBlockData on editor blocks', () => {
	it('sends a parsed block’s text as edited, not as loaded', () => {
		const registry = loadEditor(
			parse(
				'<!-- wp:paragraph --><p>Text as loaded.</p><!-- /wp:paragraph -->'
			)
		);
		const [{ clientId }] = registry.select(blockEditorStore).getBlocks();

		registry
			.dispatch(blockEditorStore)
			.updateBlockAttributes(clientId, { content: 'Text as edited.' });

		const [data] = extractBlockData(
			registry.select(blockEditorStore).getBlocks()
		);
		expect(data.content).toBe('Text as edited.');
	});

	it('sees a parsed block emptied in the editor as having no text', () => {
		const registry = loadEditor(
			parse(
				'<!-- wp:paragraph --><p>Gone soon.</p><!-- /wp:paragraph -->'
			)
		);
		const [{ clientId }] = registry.select(blockEditorStore).getBlocks();

		registry
			.dispatch(blockEditorStore)
			.updateBlockAttributes(clientId, { content: '' });

		expect(
			hasTextContent(registry.select(blockEditorStore).getBlocks())
		).toBe(false);
	});

	it('does not repeat inner blocks’ text on their parent', () => {
		const parsed = parse(
			'<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Parsed inner.</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
		);
		const created = createBlock('core/group', {}, [
			createBlock('core/paragraph', { content: 'New inner.' }),
		]);

		const data = extractBlockData(
			loadEditor([...parsed, created])
				.select(blockEditorStore)
				.getBlocks()
		);

		expect(data.map(({ name, content }) => [name, content])).toEqual([
			['core/paragraph', 'Parsed inner.'],
			['core/paragraph', 'New inner.'],
		]);
	});

	it('keeps the saved text of a block that failed validation', () => {
		const [block] = loadEditor(
			parse(
				'<!-- wp:heading --><h2 class="wp-block-heading">Kept</h2><p>as saved.</p><!-- /wp:heading -->'
			)
		)
			.select(blockEditorStore)
			.getBlocks();

		expect(block.isValid).toBe(false);
		expect(extractBlockData([block])[0].content).toBe('Keptas saved.');
	});

	it('finds text that is only in inner blocks', () => {
		const parsed = parse(
			'<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Inner only.</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
		);
		const created = createBlock('core/group', {}, [
			createBlock('core/paragraph', { content: 'Inner only.' }),
		]);

		expect(hasTextContent(parsed)).toBe(true);
		expect(hasTextContent([created])).toBe(true);
	});
});
