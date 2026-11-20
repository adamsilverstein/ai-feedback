/**
 * Internal dependencies
 */
import { describe, it, expect } from 'vitest';
import { describeSkippedImages } from '../skipped-images';

describe('describeSkippedImages', () => {
	it('says nothing when no image was left out', () => {
		expect(describeSkippedImages(undefined)).toEqual([]);
		expect(describeSkippedImages([])).toEqual([]);
	});

	it('gives one line per reason, with a count', () => {
		const lines = describeSkippedImages([
			{ block_id: 'a', reason: 'type' },
			{ block_id: 'b', reason: 'type' },
			{ block_id: 'c', reason: 'dimensions' },
		]);

		expect(lines).toHaveLength(2);
		expect(lines[0]).toMatch(/^2 images were not shown to the AI: the AI provider does not take their file type/);
		expect(lines[1]).toMatch(/^1 image was not shown to the AI: it is too large in pixels/);
	});

	it('explains a refusal and a remembered refusal', () => {
		const lines = describeSkippedImages([
			{ block_id: 'a', reason: 'refused' },
			{ block_id: 'b', reason: 'refused_recently' },
		]);

		expect(lines[0]).toMatch(/refused 1 image, so the review ran on the text alone/);
		expect(lines[1]).toMatch(/recently refused images/);
	});

	it('ignores reasons it does not know', () => {
		expect(
			describeSkippedImages([{ block_id: 'a', reason: 'later' }])
		).toEqual([]);
	});
});
