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
 * Recursively extract blocks with their clientIds and content.
 *
 * @param {Array} blocks Array of blocks from the editor.
 * @return {Array} Simplified block data for API.
 */
export function extractBlockData(blocks) {
	const result = [];

	for (const block of blocks) {
		// Get the serialized content for this block.
		// Use originalContent if available (parsed from existing HTML),
		// otherwise serialize the current block state.
		const rawContent = block.originalContent || serialize(block);
		const content = extractTextContent(rawContent);

		// Only include blocks with actual content
		if (content.trim()) {
			result.push({
				clientId: block.clientId,
				name: block.name,
				content: content.trim(),
			});
		}

		// Recursively process inner blocks
		if (block.innerBlocks && block.innerBlocks.length > 0) {
			result.push(...extractBlockData(block.innerBlocks));
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
		return text.length > 0;
	});
}
