/**
 * Helpers for the strengths a review returns: what the draft does well.
 */
import { select } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { extractBlockData } from './block-utils';

/**
 * Most strengths shown, matching Response_Parser::MAX_STRENGTHS.
 */
export const MAX_STRENGTHS = 3;

/**
 * Keep the well-formed strengths of a review.
 *
 * The server validates them already; this guards the sidebar against
 * anything else in the response.
 *
 * @param {unknown} strengths Raw `strengths` value.
 * @return {Array<Object>} Strengths with a block ID and text.
 */
export function getStrengths(strengths) {
	return mergeStrengths(Array.isArray(strengths) ? strengths : []);
}

/**
 * Merge the strengths of a chunked review's parts, in order, one per block,
 * capped. Matches Review_History::sanitize_strengths().
 *
 * @param {...Array} lists Strength lists, in part order.
 * @return {Array<Object>} Merged strengths.
 */
export function mergeStrengths(...lists) {
	const seen = new Set();
	const merged = [];
	for (const strength of lists.flat()) {
		if (
			!strength ||
			typeof strength.block_id !== 'string' ||
			!strength.block_id ||
			typeof strength.text !== 'string' ||
			!strength.text.trim() ||
			seen.has(strength.block_id)
		) {
			continue;
		}
		seen.add(strength.block_id);
		merged.push(strength);
		if (merged.length >= MAX_STRENGTHS) {
			break;
		}
	}
	return merged;
}

/**
 * Find the block a strength is about.
 *
 * Client IDs change on every editor load, so after a reload the block is
 * found by its name and the fingerprint of its text. A block edited since the
 * review no longer matches, and the strength is shown without a link.
 *
 * @param {Object} strength Strength with `block_id`, `block_name` and `block_anchor`.
 * @return {string|null} Client ID of the block in this editor, or null.
 */
export function findStrengthBlock(strength) {
	const { getBlock, getBlocks } = select(blockEditorStore) || {};
	if (!getBlock) {
		return null;
	}
	if (strength.block_id && getBlock(strength.block_id)) {
		return strength.block_id;
	}
	if (!strength.block_anchor || !strength.block_name) {
		return null;
	}

	const match = extractBlockData(getBlocks()).find(
		(block) =>
			block.name === strength.block_name &&
			block.anchor === strength.block_anchor
	);
	return match?.clientId ?? null;
}
