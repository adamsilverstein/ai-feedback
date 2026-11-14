/**
 * Internal dependencies
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';

const blocks = {};

vi.mock('@wordpress/block-editor', () => ({ store: 'core/block-editor' }));
vi.mock('@wordpress/data', () => ({
	dispatch: () => ({}),
	select: () => ({
		getBlock: (id) => blocks[id],
		getClientIdsWithDescendants: () => Object.keys(blocks),
	}),
}));

import { resolveBlockClientId } from '../navigate-to-block';

describe('resolveBlockClientId', () => {
	beforeEach(() => {
		Object.keys(blocks).forEach((id) => delete blocks[id]);
		blocks.a = { attributes: { content: 'Plain text.' } };
		blocks.b = { attributes: { metadata: { noteId: 12 } } };
		blocks.c = {
			attributes: {
				content:
					'<mark class="wp-suggestion-add" data-suggestion-id="14">To</mark> start',
			},
		};
	});

	it('keeps a client ID that still exists', () => {
		expect(resolveBlockClientId('a', 12)).toBe('a');
	});

	it('finds the block by note ID once the client ID is stale', () => {
		expect(resolveBlockClientId('old-id', 12)).toBe('b');
	});

	it('finds the block holding a suggestion marker', () => {
		expect(resolveBlockClientId('old-id', 14)).toBe('c');
	});

	it('returns null when nothing matches', () => {
		expect(resolveBlockClientId('old-id', 99)).toBeNull();
		expect(resolveBlockClientId('old-id')).toBeNull();
	});
});
