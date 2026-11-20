/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { extractBlockData } from '../block-utils';

const block = (clientId, content, innerBlocks = []) => ({
	clientId,
	name: 'core/paragraph',
	originalContent: content,
	innerBlocks,
});

describe('extractBlockData', () => {
	it('gives inner blocks their parent’s client ID', () => {
		const data = extractBlockData([
			block('group', '<div>Inside.</div>', [
				block('inner', '<p>Inside.</p>'),
			]),
			block('after', '<p>After.</p>'),
		]);

		expect(data.map(({ clientId, parent }) => [clientId, parent])).toEqual([
			['group', undefined],
			['inner', 'group'],
			['after', undefined],
		]);
	});
});
