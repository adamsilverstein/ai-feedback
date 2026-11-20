/**
 * Utility functions for block content extraction.
 */
import { serialize } from '@wordpress/blocks';

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
 * Extract a block's text as the editor's rich text reads it: wrapped
 * source text is a space and a line break is "\n".
 *
 * A quick fix's `find` is matched against this text on the server and
 * in the editor, so a `find` across a break never passes on one side only.
 *
 * @param {string} innerHTML The block's innerHTML.
 * @return {string} Plain text content.
 */
export function extractEditorText(innerHTML) {
	if (!innerHTML) {
		return '';
	}
	const temp = document.createElement('div');
	temp.innerHTML = innerHTML;
	const walker = document.createTreeWalker(temp, window.NodeFilter.SHOW_TEXT);
	for (let node = walker.nextNode(); node; node = walker.nextNode()) {
		node.nodeValue = node.nodeValue.replace(/[\n\t\r\f]+/g, ' ');
	}
	temp.querySelectorAll('br').forEach((br) => br.replaceWith('\n'));
	return temp.textContent || '';
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
		// Get the serialized content for this block.
		// Use originalContent if available (parsed from existing HTML),
		// otherwise serialize the current block state.
		const rawContent = block.originalContent || serialize(block);
		const content = extractEditorText(rawContent);

		// Only include blocks with actual content, and images, whose alt
		// text is reviewed even without a caption.
		const attributes = getFormattingAttributes(block);
		const isImage = block.name === 'core/image' && !!attributes?.url;
		if (content.trim() || isImage) {
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
		// Get text content from the block
		const content = block.originalContent || serialize(block);
		const text = extractTextContent(content);
		return text.trim().length > 0;
	});
}
