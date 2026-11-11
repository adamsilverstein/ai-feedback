/**
 * Internal dependencies
 */
import {
	chunkBlocks,
	mergeChunkResponses,
	DEFAULT_CHUNK_SIZE,
} from '../chunk-review';

const makeBlocks = (count) =>
	Array.from({ length: count }, (_, i) => ({
		clientId: `block-${i}`,
		name: 'core/paragraph',
		content: `Paragraph ${i}`,
	}));

describe('chunkBlocks', () => {
	it('keeps small documents in one chunk', () => {
		const blocks = makeBlocks(5);
		expect(chunkBlocks(blocks, 20)).toEqual([blocks]);
	});

	it('splits large documents into chunks of the given size', () => {
		const chunks = chunkBlocks(makeBlocks(45), 20);

		expect(chunks.map((chunk) => chunk.length)).toEqual([20, 20, 5]);
		expect(chunks[1][0].clientId).toBe('block-20');
	});

	it.each([0, -1, 2.5, undefined, 'ten'])(
		'falls back to the default size for %p',
		(size) => {
			const chunks = chunkBlocks(
				makeBlocks(DEFAULT_CHUNK_SIZE + 1),
				size
			);
			expect(chunks).toHaveLength(2);
		}
	);
});

describe('mergeChunkResponses', () => {
	it('returns null without responses', () => {
		expect(mergeChunkResponses([])).toBeNull();
	});

	it('combines notes, mappings and counts across chunks', () => {
		const merged = mergeChunkResponses([
			{
				review_id: 'r1',
				notes: [{ id: 1 }],
				note_ids: [1],
				block_mapping: { a: 1 },
				note_count: 1,
				summary_text: 'Part one.',
				summary: {
					total_notes: 1,
					by_category: { content: 1, tone: 0 },
					by_severity: { suggestion: 1, critical: 0 },
					has_critical: false,
				},
			},
			{
				review_id: 'r1',
				notes: [{ id: 2 }, { id: 3 }],
				note_ids: [2, 3],
				block_mapping: { b: 2 },
				note_count: 2,
				summary_text: 'Whole document summary.',
				summary: {
					total_notes: 2,
					by_category: { content: 1, tone: 1 },
					by_severity: { suggestion: 1, critical: 1 },
					has_critical: true,
				},
			},
		]);

		expect(merged).toMatchObject({
			review_id: 'r1',
			notes: [{ id: 1 }, { id: 2 }, { id: 3 }],
			note_ids: [1, 2, 3],
			block_mapping: { a: 1, b: 2 },
			note_count: 3,
			summary_text: 'Whole document summary.',
			chunks: 2,
			summary: {
				total_notes: 3,
				by_category: { content: 2, tone: 1 },
				by_severity: { suggestion: 2, critical: 1 },
				has_critical: true,
			},
		});
	});

	it('joins earlier summaries when the last chunk has none', () => {
		const merged = mergeChunkResponses([
			{ summary_text: 'One.' },
			{ summary_text: 'Two.' },
			{ summary_text: '' },
		]);

		expect(merged.summary_text).toBe('One. Two.');
	});
});
