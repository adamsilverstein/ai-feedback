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
import {
	createBlock,
	getBlockType,
	getBlockTypes,
	parse,
	registerBlockType,
	unregisterBlockType,
} from '@wordpress/blocks';
import { RawHTML } from '@wordpress/element';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { registerCoreBlocks } from '@wordpress/block-library';

/**
 * Internal dependencies
 */
import {
	extractBlockData,
	extractEditorText,
	extractTextContent,
	getBlockAnchor,
	getBlockOwnHTML,
	hasTextContent,
} from '../block-utils';

// Shared with ReplyServiceTest, where the server reads a block's own text
// from its `innerContent` strings with Reply_Service::editor_text().
const EDITOR_TEXT = require('../../../tests/fixtures/editor-text.json');

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
				'<!-- wp:heading --><h2>Kept</h2><p>as saved.</p><!-- /wp:heading -->'
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

	it('gives a dynamic block no text of its own, as before', () => {
		const [block] = loadEditor(
			parse('<!-- wp:latest-posts {"postsToShow":3} /-->')
		)
			.select(blockEditorStore)
			.getBlocks();

		expect(block.originalContent).toBe('');
		expect(extractBlockData([block])).toEqual([]);
		expect(hasTextContent([block])).toBe(false);
	});
});

describe('anchors of loaded blocks', () => {
	/**
	 * Anchors as reviews made them before blocks were read as edited: from
	 * the markup each block was parsed from.
	 *
	 * @param {Array} blocks Blocks.
	 * @return {Object} Anchor by client ID, for blocks with text.
	 */
	function loadedAnchors(blocks) {
		const anchors = {};
		for (const block of blocks) {
			const text = extractTextContent(block.originalContent).trim();
			if (text) {
				anchors[block.clientId] = getBlockAnchor(block.name, text);
			}
			Object.assign(anchors, loadedAnchors(block.innerBlocks));
		}
		return anchors;
	}

	it('are unchanged for unedited blocks, so earlier notes still relink', () => {
		const blocks = parse(
			[
				'<!-- wp:paragraph --><p>Plain, <strong>bold</strong> and <a href="https://example.com">linked</a> &amp; escaped.</p><!-- /wp:paragraph -->',
				'<!-- wp:paragraph --><p>Two<br>lines.</p><!-- /wp:paragraph -->',
				'<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">A heading</h3><!-- /wp:heading -->',
				'<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>One</li><!-- /wp:list-item --><!-- wp:list-item --><li>Two</li><!-- /wp:list-item --></ul><!-- /wp:list -->',
				'<!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>Quoted.</p><!-- /wp:paragraph --><cite>Someone</cite></blockquote><!-- /wp:quote -->',
				'<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>In a group.</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
				'<!-- wp:preformatted --><pre class="wp-block-preformatted">Line one\nLine  two</pre><!-- /wp:preformatted -->',
				'<!-- wp:code --><pre class="wp-block-code"><code>if ( a &lt; b ) {}</code></pre><!-- /wp:code -->',
			].join('\n\n')
		);
		const blockList = loadEditor(blocks)
			.select(blockEditorStore)
			.getBlocks();

		const anchors = Object.fromEntries(
			extractBlockData(blockList).map(({ clientId, anchor }) => [
				clientId,
				anchor,
			])
		);

		// Valid blocks, so the new anchors come from their save output.
		const flat = (list) =>
			list.flatMap((block) => [block, ...flat(block.innerBlocks)]);
		expect(flat(blockList).every(({ isValid }) => isValid)).toBe(true);
		expect(Object.keys(anchors)).toHaveLength(10);
		expect(anchors).toEqual(loadedAnchors(blockList));
		// Parents with only inner blocks had no text of their own and still
		// have none, so they are not sent and have no anchor.
		const group = blockList.find(({ name }) => name === 'core/group');
		expect(anchors[group.clientId]).toBeUndefined();
	});
});

describe('Custom HTML and blocks that save nothing', () => {
	const htmlText = (registry) =>
		extractBlockData(registry.select(blockEditorStore).getBlocks()).map(
			({ content }) => content
		);

	it('reads a loaded Custom HTML block, and its text once edited', () => {
		const registry = loadEditor(
			parse('<!-- wp:html --><p>Custom text</p><!-- /wp:html -->')
		);
		expect(htmlText(registry)).toEqual(['Custom text']);
		expect(
			hasTextContent(registry.select(blockEditorStore).getBlocks())
		).toBe(true);

		// As the block's editor saves an edit.
		const [{ clientId }] = registry.select(blockEditorStore).getBlocks();
		registry
			.dispatch(blockEditorStore)
			.updateBlock(clientId, { innerContent: ['<p>Edited custom</p>'] });
		expect(htmlText(registry)).toEqual(['Edited custom']);

		registry
			.dispatch(blockEditorStore)
			.updateBlock(clientId, { innerContent: [] });
		expect(
			hasTextContent(registry.select(blockEditorStore).getBlocks())
		).toBe(false);
	});

	it('reads a Custom HTML block made with the content attribute', () => {
		const registry = loadEditor([
			createBlock('core/html', { content: '<p>Made with content</p>' }),
		]);
		expect(htmlText(registry)).toEqual(['Made with content']);
	});

	it('reads the older Custom HTML block that saves its content attribute', () => {
		const current = getBlockType('core/html');
		unregisterBlockType('core/html');
		registerBlockType('core/html', {
			...current,
			save: ({ attributes }) => <RawHTML>{attributes.content}</RawHTML>,
		});
		try {
			const registry = loadEditor([
				{
					...createBlock('core/html', {
						content: '<p>Older shape</p>',
					}),
					originalContent: '<p>Older shape</p>',
				},
			]);
			const [{ clientId }] = registry
				.select(blockEditorStore)
				.getBlocks();
			registry
				.dispatch(blockEditorStore)
				.updateBlockAttributes(clientId, {
					content: '<p>Older shape, edited</p>',
				});
			expect(htmlText(registry)).toEqual(['Older shape, edited']);
		} finally {
			unregisterBlockType('core/html');
			registerBlockType('core/html', current);
		}
	});

	it('keeps the loaded text of a dynamic block saved with markup', () => {
		// Its save renders nothing, so markup saved inside it fails validation
		// and is read from the loaded markup, as before.
		const [block] = loadEditor(
			parse(
				'<!-- wp:latest-posts --><p>Saved inside.</p><!-- /wp:latest-posts -->'
			)
		)
			.select(blockEditorStore)
			.getBlocks();

		expect(block.isValid).toBe(false);
		expect(extractBlockData([block])[0].content).toBe('Saved inside.');
	});

	it.each([
		[
			'a table with its rows and caption deleted',
			'<!-- wp:table --><figure class="wp-block-table"><table class="has-fixed-layout"><tbody><tr><td>Old cell</td></tr></tbody></table><figcaption class="wp-element-caption">Old caption</figcaption></figure><!-- /wp:table -->',
			{ head: [], body: [], foot: [], caption: '' },
		],
		[
			'an embed with its URL removed',
			'<!-- wp:embed {"url":"https://www.youtube.com/watch?v=old","type":"video","providerNameSlug":"youtube","responsive":true} --><figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube"><div class="wp-block-embed__wrapper">\nhttps://www.youtube.com/watch?v=old\n</div><figcaption class="wp-element-caption">Old caption</figcaption></figure><!-- /wp:embed -->',
			{ url: undefined, caption: '' },
		],
	])(
		'reads no text from %s, which then saves nothing',
		(_name, markup, emptied) => {
			const registry = loadEditor(parse(markup));
			const [block] = registry.select(blockEditorStore).getBlocks();
			expect(block.isValid).toBe(true);
			expect(extractBlockData([block])[0].content).toContain('Old');

			registry
				.dispatch(blockEditorStore)
				.updateBlockAttributes(block.clientId, emptied);
			const blocks = registry.select(blockEditorStore).getBlocks();

			expect(getBlockOwnHTML(blocks[0])).toBe('');
			expect(extractBlockData(blocks)).toEqual([]);
			expect(hasTextContent(blocks)).toBe(false);
		}
	);
});

// The editor's notion of a block's own text, from its save output without
// inner blocks, must match the server's, from its `innerContent` strings.
describe('a block’s own text, shared with the server', () => {
	it.each(EDITOR_TEXT.blocks.map((item) => [item.name, item]))(
		'reads the own text of a %s as the server does, loaded and edited',
		(_name, item) => {
			const registry = loadEditor(
				parse(
					`<!-- wp:list --><ul class="wp-block-list"><!-- wp:${item.block.replace(
						'core/',
						''
					)} -->${item.before}<!-- wp:list -->${item.inner}<!-- /wp:list -->${item.after}<!-- /wp:${item.block.replace(
						'core/',
						''
					)} --></ul><!-- /wp:list -->`
				)
			);
			const ownText = () => {
				const [list] = registry.select(blockEditorStore).getBlocks();
				const [block] = list.innerBlocks;
				expect(block.name).toBe(item.block);
				expect(block.innerBlocks).toHaveLength(1);
				const sent = extractBlockData([list]).find(
					({ clientId }) => clientId === block.clientId
				);
				return [
					extractEditorText(getBlockOwnHTML(block)).trim(),
					sent.content,
				];
			};

			expect(ownText()).toEqual([item.text.trim(), item.text.trim()]);

			const [list] = registry.select(blockEditorStore).getBlocks();
			registry
				.dispatch(blockEditorStore)
				.updateBlockAttributes(list.innerBlocks[0].clientId, {
					content: 'Edited<br>own text.',
				});
			expect(ownText()).toEqual([
				'Edited\nown text.',
				'Edited\nown text.',
			]);
		}
	);
});

// Shared with ReplyServiceTest::test_editor_text_reads_a_wrapper_as_the_editor_does().
describe('a parent block’s own text, shared with the server', () => {
	it.each(EDITOR_TEXT.wrappers.map((item) => [item.name, item]))(
		'reads the own text of a %s as the server does',
		(_name, item) => {
			const [block] = loadEditor(parse(item.markup))
				.select(blockEditorStore)
				.getBlocks();
			expect(block.isValid).toBe(true);
			expect(extractTextContent(getBlockOwnHTML(block)).trim()).toBe(
				item.text
			);

			const sent = extractBlockData([block]).find(
				({ clientId }) => clientId === block.clientId
			);
			expect(sent?.content ?? '').toBe(item.text);
		}
	);
});
