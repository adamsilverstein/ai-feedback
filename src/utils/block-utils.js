/**
 * Utility functions for block content extraction.
 */
import { getBlockType, getSaveContent } from '@wordpress/blocks';
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
function getOwnHTML(block) {
	const saved = block.originalContent || '';
	if (block.isValid === false || !getBlockType(block.name)) {
		return saved;
	}
	try {
		return getSaveContent(block.name, block.attributes ?? {}, []);
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
		const content = extractTextContent(getOwnHTML(block));

		// Only include blocks with actual content, and images, whose alt
		// text is reviewed even without a caption.
		const attributes = getFormattingAttributes(block);
		const isImage = block.name === 'core/image' && !!attributes?.url;
		if (content.trim() || isImage) {
			result.push({
				clientId: block.clientId,
				name: block.name,
				content: content.trim(),
				// A captionless image is told apart by its URL.
				anchor: getBlockAnchor(
					block.name,
					content.trim() || (isImage ? attributes.url : '')
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
			const text = extractTextContent(getOwnHTML(block));
			hasTextCache.set(
				block,
				text.trim().length > 0 ||
					hasTextContent(block.innerBlocks ?? [])
			);
		}
		return hasTextCache.get(block);
	});
}
