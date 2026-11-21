/**
 * Utility functions for block content extraction.
 */
import {
	getBlockType,
	getSaveContent,
	getSaveElement,
} from '@wordpress/blocks';
import { create } from '@wordpress/rich-text';
import { debugLog } from './debug';

/**
 * Extract text content from a block's innerHTML.
 *
 * @param {string} innerHTML The block's innerHTML.
 * @return {string} Plain text content.
 */
export function extractTextContent(innerHTML) {
	if (!innerHTML) {
		return '';
	}
	// Create a temporary element to strip HTML tags
	const temp = document.createElement('div');
	temp.innerHTML = innerHTML;
	return temp.textContent || temp.innerText || '';
}

/**
 * Extract a block's text as the editor's rich text reads it.
 *
 * A quick fix's `find` is matched against this text on the server and
 * against `create( { html } ).text` in the editor, so a line break is "\n",
 * whitespace is kept as written and a footnote, an inline image or a
 * comment is U+FFFC. Block delimiters mark blocks, not text, so they are
 * dropped. Shares its cases with Reply_Service::editor_text() through
 * tests/fixtures/editor-text.json.
 *
 * @param {string} innerHTML The block's HTML.
 * @return {string} Plain text content.
 */
export function extractEditorText(innerHTML) {
	if (!innerHTML) {
		return '';
	}
	return create({ html: innerHTML.replace(/<!--\s+\/?wp:[\s\S]*?-->/g, '') })
		.text;
}

/**
 * Text of a block a review's quick fixes are matched against.
 *
 * A block with inner blocks and its own rich text, like a list item with
 * a nested list, is edited in its `content` alone, so that is its text.
 *
 * @param {Object} block      Block.
 * @param {string} rawContent The block's own HTML, without its inner blocks.
 * @return {string} Text.
 */
function getReviewText(block, rawContent) {
	const own = block.attributes?.content;
	if (block.innerBlocks?.length && own !== undefined && own !== null) {
		return extractEditorText(
			typeof own === 'string' ? own : own.toHTMLString()
		);
	}
	// An image block's <img> is the block, not an inline image in its text.
	return block.name === 'core/image'
		? extractTextContent(rawContent)
		: extractEditorText(rawContent);
}

/**
 * Fingerprint a block's text so a note can find the block again after a
 * reload, when its clientId has changed.
 *
 * @param {string} name    Block name.
 * @param {string} content Block text content.
 * @return {string} FNV-1a hash of the name and content, in base 36.
 */
/* eslint-disable no-bitwise -- FNV-1a needs 32-bit integer math. */
export function getBlockAnchor(name, content) {
	const text = `${name}\n${content}`;
	let hash = 0x811c9dc5;
	for (let index = 0; index < text.length; index++) {
		hash ^= text.charCodeAt(index);
		hash = Math.imul(hash, 0x01000193);
	}
	return (hash >>> 0).toString(36);
}
/* eslint-enable no-bitwise */

/**
 * The attributes formatting fixes depend on, for the server to check them
 * against (see Block_Change::get_block_attributes()).
 *
 * @param {Object} block Block.
 * @return {Object|null} Attributes, or null for other block types.
 */
export function getFormattingAttributes(block) {
	const attributes = block.attributes || {};
	switch (block.name) {
		case 'core/heading':
			return { level: attributes.level ?? 2 };
		case 'core/list':
			return { ordered: !!attributes.ordered };
		case 'core/image':
			return {
				alt: typeof attributes.alt === 'string' ? attributes.alt : '',
				...(Number.isInteger(attributes.id) && attributes.id > 0
					? { id: attributes.id }
					: {}),
				...(typeof attributes.url === 'string' &&
				!attributes.url.startsWith('blob:')
					? { url: attributes.url }
					: {}),
			};
		default:
			return null;
	}
}

/**
 * Get a block's own HTML as it is now, without its inner blocks.
 *
 * `originalContent` is the markup the block was parsed from and is not
 * updated when the block is edited, so it is only used for blocks that
 * failed validation, as core's serializer does, or cannot be saved here.
 *
 * @param {Object} block Block from the editor.
 * @return {string} Block HTML.
 */
export function getBlockOwnHTML(block) {
	// The Custom HTML block keeps its markup in `innerContent`, which is
	// updated as it is edited, and saves nothing. Its string fragments are
	// its own markup, the `null` ones mark inner blocks. Only that block:
	// the serializer ignores `innerContent` on any other.
	if (block.name === 'core/html' && Array.isArray(block.innerContent)) {
		return block.innerContent
			.filter((part) => typeof part === 'string')
			.join('');
	}

	const saved = block.originalContent || '';
	if (block.isValid === false || !getBlockType(block.name)) {
		return saved;
	}
	const attributes = block.attributes ?? {};
	try {
		const html = getSaveContent(block.name, attributes, []);
		// A save that renders nothing, like a table with its rows deleted or
		// an embed without a URL, has no text now. Its loaded markup would
		// bring deleted text back. A Custom HTML block made with its markup
		// in the deprecated `content` attribute, not yet moved to
		// `innerContent`, saves nothing but still has that markup.
		if (!html && getSaveElement(block.name, attributes, []) === null) {
			return block.name === 'core/html' &&
				typeof attributes.content === 'string'
				? attributes.content
				: '';
		}
		return html;
	} catch (error) {
		debugLog(
			`Could not save ${block.name}, using its loaded markup.`,
			error
		);
		return saved;
	}
}

/**
 * Recursively extract blocks with their clientIds and content.
 *
 * Inner blocks carry their parent's client ID, so the server can tell a
 * block from the blocks inside it.
 *
 * @param {Array}  blocks   Array of blocks from the editor.
 * @param {string} [parent] Client ID of the blocks' parent, '' at the top level.
 * @return {Array} Simplified block data for API.
 */
export function extractBlockData(blocks, parent = '') {
	const result = [];

	for (const block of blocks) {
		const rawContent = getBlockOwnHTML(block);
		const content = getReviewText(block, rawContent);
		// Objects alone, like a cover's image (U+FFFC), are no text to review.
		const hasText = content.replace(/[\uFFFC\s]/g, '') !== '';

		// Only include blocks with actual content, and images, whose alt
		// text is reviewed even without a caption.
		const attributes = getFormattingAttributes(block);
		const isImage = block.name === 'core/image' && !!attributes?.url;
		if (hasText || isImage) {
			result.push({
				clientId: block.clientId,
				name: block.name,
				content: content.trim(),
				// A captionless image is told apart by its URL. Anchors hash
				// the text read without line breaks, as for notes saved before.
				anchor: getBlockAnchor(
					block.name,
					extractTextContent(rawContent).trim() ||
						(isImage ? attributes.url : '')
				),
				...(parent ? { parent } : {}),
				...(attributes ? { attributes } : {}),
			});
		}

		// Recursively process inner blocks
		if (block.innerBlocks && block.innerBlocks.length > 0) {
			result.push(...extractBlockData(block.innerBlocks, block.clientId));
		}
	}

	return result;
}

/**
 * Whether each block, inner blocks included, has text, by block object.
 *
 * The editor replaces a block's object, and its parents', only when it
 * changes, so typing in one block re-reads just that block and its parents.
 * Block types register before editing starts, so a result read before a
 * type was registered is not expected to go stale.
 */
const hasTextCache = new WeakMap();

/**
 * Check if blocks array contains any text content.
 *
 * @param {Array} blocks Array of blocks from the editor.
 * @return {boolean} True if blocks contain text content.
 */
export function hasTextContent(blocks) {
	return blocks.some((block) => {
		if (!block.name) {
			return false;
		}
		if (!hasTextCache.has(block)) {
			const text = extractTextContent(getBlockOwnHTML(block));
			hasTextCache.set(
				block,
				text.trim().length > 0 ||
					hasTextContent(block.innerBlocks ?? [])
			);
		}
		return hasTextCache.get(block);
	});
}
