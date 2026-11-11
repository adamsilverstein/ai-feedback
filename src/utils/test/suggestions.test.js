/**
 * External dependencies
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * WordPress dependencies
 */
import {
	create,
	RichTextData,
	registerFormatType,
	unregisterFormatType,
} from '@wordpress/rich-text';

/**
 * Internal dependencies
 */
import {
	buildSuggestionContent,
	canSuggest,
	hasSuggestionMarker,
	hasSuggestionMarkers,
	isSuggestionModeAvailable,
	removeOrphanedSuggestions,
	suggestQuickFix,
	suggestQuickFixes,
} from '../suggestions';

const mocks = vi.hoisted(() => ({
	apiFetch: vi.fn(),
	blocks: {},
	postContent: '',
	updateBlockAttributes: vi.fn(),
	setSuggestionStatus: vi.fn(),
	createInfoNotice: vi.fn(),
}));

vi.mock('@wordpress/api-fetch', () => ({ default: mocks.apiFetch }));
vi.mock('@wordpress/block-editor', () => ({ store: 'core/block-editor' }));
vi.mock('@wordpress/blocks', () => ({ store: 'core/blocks' }));
vi.mock('@wordpress/notices', () => ({ store: 'core/notices' }));
vi.mock('../refresh-core-notes', () => ({ refreshCoreNotes: vi.fn() }));
vi.mock('@wordpress/data', async (importOriginal) => {
	const actual = await importOriginal();
	return {
		...actual,
		select: (store) => {
			switch (store) {
				case 'core/block-editor':
					return {
						getBlock: (clientId) => mocks.blocks[clientId] ?? null,
					};
				case 'core/blocks':
					return {
						getBlockType: () => ({
							attributes: { content: { type: 'rich-text' } },
						}),
					};
				case 'core/editor':
					return { getEditedPostContent: () => mocks.postContent };
				default:
					return actual.select(store);
			}
		},
		dispatch: (store) => {
			switch (store) {
				case 'core/block-editor':
					return {
						updateBlockAttributes: mocks.updateBlockAttributes,
					};
				case 'core/notices':
					return { createInfoNotice: mocks.createInfoNotice };
				case 'ai-feedback/store':
					return { setSuggestionStatus: mocks.setSuggestionStatus };
				default:
					return actual.dispatch(store);
			}
		},
	};
});

const fix = { find: 'in order to', replace: 'to' };

describe('buildSuggestionContent', () => {
	it('adds an add run followed by a del run under one id', () => {
		expect(
			buildSuggestionContent('We met in order to plan.', fix, {
				id: 12,
				authorId: 0,
			})
		).toBe(
			'We met <mark class="wp-suggestion-add" data-suggestion-id="12" data-suggestion-type="add" data-author="0">to</mark><mark class="wp-suggestion-del" data-suggestion-id="12" data-suggestion-type="del" data-author="0">in order to</mark> plan.'
		);
	});

	it('only marks a deletion when the replacement is empty', () => {
		expect(
			buildSuggestionContent(
				'This is very good.',
				{ find: 'very ', replace: '' },
				{ id: 3 }
			)
		).toBe(
			'This is <mark class="wp-suggestion-del" data-suggestion-id="3" data-suggestion-type="del">very </mark>good.'
		);
	});

	it('keeps formatting inside the markers', () => {
		expect(
			buildSuggestionContent(
				'We <strong>utilize</strong> tools.',
				{ find: 'utilize', replace: 'use' },
				{ id: 5 }
			)
		).toBe(
			'We <mark class="wp-suggestion-add" data-suggestion-id="5" data-suggestion-type="add"><strong>use</strong></mark><mark class="wp-suggestion-del" data-suggestion-id="5" data-suggestion-type="del"><strong>utilize</strong></mark> tools.'
		);
	});

	it('returns RichTextData for RichTextData input', () => {
		const result = buildSuggestionContent(
			RichTextData.fromHTMLString('We met in order to plan.'),
			fix,
			{ id: 12 }
		);

		expect(result).toBeInstanceOf(RichTextData);
		expect(result.text).toBe('We met toin order to plan.');
	});

	it.each([
		['missing text', 'We met to plan.'],
		['ambiguous text', 'in order to go, in order to stay'],
		[
			'existing suggestions',
			'We <mark class="wp-suggestion-del" data-suggestion-id="1">met</mark> in order to plan.',
		],
	])('refuses %s', (label, content) => {
		expect(buildSuggestionContent(content, fix, { id: 1 })).toBeNull();
	});

	describe('with Gutenberg marker formats registered', () => {
		const settings = (className) => ({
			title: 'Suggestion',
			tagName: 'mark',
			className,
			attributes: {
				'data-suggestion-id': 'data-suggestion-id',
				'data-suggestion-type': 'data-suggestion-type',
				'data-author': 'data-author',
			},
			edit: () => null,
		});

		beforeEach(() => {
			registerFormatType(
				'core/suggestion-add',
				settings('wp-suggestion-add')
			);
			registerFormatType(
				'core/suggestion-del',
				settings('wp-suggestion-del')
			);
		});

		afterEach(() => {
			unregisterFormatType('core/suggestion-add');
			unregisterFormatType('core/suggestion-del');
		});

		it('parses the markers as Gutenberg formats', () => {
			const html = buildSuggestionContent(
				'We met in order to plan.',
				fix,
				{
					id: 12,
				}
			);
			const value = create({ html });

			expect(value.formats[7][0].type).toBe('core/suggestion-add');
			expect(value.formats[7][0].attributes).toMatchObject({
				'data-suggestion-id': '12',
				'data-suggestion-type': 'add',
			});
			expect(value.formats[9][0].type).toBe('core/suggestion-del');
		});
	});
});

describe('marker helpers', () => {
	it('finds suggestion markers', () => {
		expect(hasSuggestionMarkers('<mark class="wp-suggestion-add">')).toBe(
			true
		);
		expect(hasSuggestionMarkers('<mark class="wp-note">')).toBe(false);
		expect(hasSuggestionMarker('<mark data-suggestion-id="12">', 12)).toBe(
			true
		);
		expect(hasSuggestionMarker('<mark data-suggestion-id="123">', 12)).toBe(
			false
		);
	});

	it.each([
		[{}, false],
		[{ id: 1, quick_fix: fix, block_id: 'a' }, true],
		[{ id: 1, quick_fix: fix, block_id: 'a', is_group: true }, false],
		[{ id: 1, quick_fix: fix, block_id: 'a', parent: 4 }, false],
		[{ id: 1, quick_fix: fix, block_id: 'a', is_resolved: true }, false],
		[
			{
				id: 1,
				quick_fix: fix,
				block_id: 'a',
				suggestion_status: 'pending',
			},
			false,
		],
	])('canSuggest(%j) is %s', (note, expected) => {
		expect(canSuggest(note)).toBe(expected);
	});
});

describe('isSuggestionModeAvailable', () => {
	afterEach(() => {
		delete window.__experimentalSuggestionMode;
		delete window.aiFeedbackData;
	});

	it('needs the Gutenberg experiment and server support', () => {
		expect(isSuggestionModeAvailable()).toBe(false);

		window.__experimentalSuggestionMode = true;
		expect(isSuggestionModeAvailable()).toBe(false);

		window.aiFeedbackData = { suggestions: true };
		expect(isSuggestionModeAvailable()).toBe(true);
	});
});

describe('suggestQuickFix', () => {
	const note = { id: 12, block_id: 'block-1', quick_fix: fix };

	beforeEach(() => {
		vi.clearAllMocks();
		mocks.blocks = {
			'block-1': {
				name: 'core/paragraph',
				attributes: { content: 'We met in order to plan.' },
			},
		};
	});

	it('stores the payload, then writes the markers', async () => {
		mocks.apiFetch.mockResolvedValue({ author: 0 });

		await expect(suggestQuickFix(note)).resolves.toBe(true);

		expect(mocks.apiFetch).toHaveBeenCalledWith({
			path: '/ai-feedback/v1/notes/12/suggestion',
			method: 'POST',
			data: {
				block_name: 'core/paragraph',
				text: 'We met in order to plan.',
			},
		});
		expect(mocks.updateBlockAttributes).toHaveBeenCalledWith('block-1', {
			content: expect.stringContaining(
				'<mark class="wp-suggestion-del" data-suggestion-id="12"'
			),
		});
		expect(mocks.setSuggestionStatus).toHaveBeenCalledWith(12, 'pending');
	});

	it('skips the request when the fix no longer applies', async () => {
		mocks.blocks['block-1'].attributes.content = 'We met to plan.';

		await expect(suggestQuickFix(note)).resolves.toBe(false);
		expect(mocks.apiFetch).not.toHaveBeenCalled();
	});

	it('removes the payload when the block changed during the request', async () => {
		mocks.apiFetch.mockImplementation(async ({ method }) => {
			if (method === 'POST') {
				mocks.blocks['block-1'].attributes.content = 'Rewritten.';
			}
			return {};
		});

		await expect(suggestQuickFix(note)).resolves.toBe(false);

		expect(mocks.apiFetch).toHaveBeenLastCalledWith({
			path: '/ai-feedback/v1/notes/12/suggestion',
			method: 'DELETE',
		});
		expect(mocks.updateBlockAttributes).not.toHaveBeenCalled();
		expect(mocks.setSuggestionStatus).toHaveBeenCalledWith(12, null);
	});
});

describe('suggestQuickFixes', () => {
	beforeEach(() => {
		vi.clearAllMocks();
		mocks.blocks = {
			'block-1': {
				name: 'core/paragraph',
				attributes: { content: 'We met in order to plan.' },
			},
		};
		mocks.apiFetch.mockResolvedValue({ author: 0 });
	});

	afterEach(() => {
		delete window.__experimentalSuggestionMode;
		delete window.aiFeedbackData;
	});

	it('does nothing without Suggestion Mode', async () => {
		await expect(
			suggestQuickFixes([{ id: 12, block_id: 'block-1', quick_fix: fix }])
		).resolves.toBe(0);
		expect(mocks.apiFetch).not.toHaveBeenCalled();
	});

	it('adds eligible fixes and announces them', async () => {
		window.__experimentalSuggestionMode = true;
		window.aiFeedbackData = { suggestions: true };

		await expect(
			suggestQuickFixes([
				{ id: 12, block_id: 'block-1', quick_fix: fix },
				{ id: 13, block_id: 'block-1' },
			])
		).resolves.toBe(1);
		expect(mocks.createInfoNotice).toHaveBeenCalled();
	});

	it('keeps going when one request fails', async () => {
		window.__experimentalSuggestionMode = true;
		window.aiFeedbackData = { suggestions: true };
		mocks.apiFetch.mockRejectedValue(new Error('nope'));

		await expect(
			suggestQuickFixes([{ id: 12, block_id: 'block-1', quick_fix: fix }])
		).resolves.toBe(0);
		expect(mocks.updateBlockAttributes).not.toHaveBeenCalled();
	});
});

describe('removeOrphanedSuggestions', () => {
	beforeEach(() => {
		vi.clearAllMocks();
		mocks.apiFetch.mockResolvedValue({});
	});

	it('removes pending suggestions whose markers are gone', async () => {
		mocks.postContent =
			'<!-- wp:paragraph --><p><mark class="wp-suggestion-del" data-suggestion-id="12">x</mark></p><!-- /wp:paragraph -->';

		await expect(
			removeOrphanedSuggestions([
				{ id: 12, suggestion_status: 'pending' },
				{ id: 13, suggestion_status: 'pending' },
				{ id: 14, suggestion_status: 'applied' },
				{ id: 15 },
			])
		).resolves.toBe(1);

		expect(mocks.apiFetch).toHaveBeenCalledTimes(1);
		expect(mocks.apiFetch).toHaveBeenCalledWith({
			path: '/ai-feedback/v1/notes/13/suggestion',
			method: 'DELETE',
		});
	});

	it('waits for content to load', async () => {
		mocks.postContent = '';

		await expect(
			removeOrphanedSuggestions([
				{ id: 13, suggestion_status: 'pending' },
			])
		).resolves.toBe(0);
		expect(mocks.apiFetch).not.toHaveBeenCalled();
	});
});
