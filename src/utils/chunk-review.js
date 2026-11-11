/**
 * Helpers for reviewing large documents in several requests.
 */

/**
 * Default blocks per review request, matching the server default.
 */
export const DEFAULT_CHUNK_SIZE = 20;

/**
 * Split blocks into chunks of at most `size` blocks.
 *
 * @param {Array}  blocks Blocks to review.
 * @param {number} size   Blocks per chunk.
 * @return {Array<Array>} Chunks, at least one.
 */
export function chunkBlocks(blocks, size = DEFAULT_CHUNK_SIZE) {
	const chunkSize =
		Number.isInteger(size) && size > 0 ? size : DEFAULT_CHUNK_SIZE;

	if (blocks.length <= chunkSize) {
		return [blocks];
	}

	const chunks = [];
	for (let i = 0; i < blocks.length; i += chunkSize) {
		chunks.push(blocks.slice(i, i + chunkSize));
	}
	return chunks;
}

/**
 * Add two count maps, such as `by_category`.
 *
 * @param {Object} a First counts.
 * @param {Object} b Second counts.
 * @return {Object} Summed counts.
 */
function addCounts(a = {}, b = {}) {
	const sum = { ...a };
	Object.entries(b).forEach(([key, value]) => {
		sum[key] = (sum[key] || 0) + value;
	});
	return sum;
}

/**
 * Combine the responses of a chunked review into one review.
 *
 * The final chunk's summary text already covers the whole document, so it
 * wins; earlier summaries are only used when it is missing.
 *
 * @param {Array<Object>} responses Review responses, in chunk order.
 * @return {Object|null} Merged review, or null when there are no responses.
 */
export function mergeChunkResponses(responses) {
	if (!responses.length) {
		return null;
	}

	const last = responses[responses.length - 1];

	const merged = responses.reduce(
		(acc, response) => ({
			notes: [...acc.notes, ...(response.notes || [])],
			note_ids: [...acc.note_ids, ...(response.note_ids || [])],
			block_mapping: {
				...acc.block_mapping,
				...(response.block_mapping || {}),
			},
			note_count: acc.note_count + (response.note_count || 0),
			summary: {
				total_notes:
					acc.summary.total_notes +
					(response.summary?.total_notes || 0),
				by_category: addCounts(
					acc.summary.by_category,
					response.summary?.by_category
				),
				by_severity: addCounts(
					acc.summary.by_severity,
					response.summary?.by_severity
				),
				has_critical:
					acc.summary.has_critical ||
					!!response.summary?.has_critical,
			},
		}),
		{
			notes: [],
			note_ids: [],
			block_mapping: {},
			note_count: 0,
			summary: {
				total_notes: 0,
				by_category: {},
				by_severity: {},
				has_critical: false,
			},
		}
	);

	const summaryText =
		last.summary_text ||
		responses
			.map((response) => response.summary_text)
			.filter(Boolean)
			.join(' ');

	return {
		...last,
		...merged,
		summary_text: summaryText,
		chunks: responses.length,
	};
}
