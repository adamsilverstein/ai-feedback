/**
 * Proofreading fixes and Accept all, against the real block editor store and
 * core blocks.
 *
 * External dependencies
 */
import { afterAll, beforeAll, describe, expect, it } from 'vitest';

/**
 * WordPress dependencies
 */
import { createRegistry } from '@wordpress/data';
import {
	createBlock,
	getBlockTypes,
	store as blocksStore,
} from '@wordpress/blocks';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { registerCoreBlocks } from '@wordpress/block-library';
import {
	RichTextData,
	registerFormatType,
	unregisterFormatType,
} from '@wordpress/rich-text';

/**
 * Internal dependencies
 */
import {
	acceptAllFixes,
	applyFixesToContent,
	isOpenFix,
	isProofreadingItem,
	planAcceptAll,
	splitProofreading,
	undoAcceptAll,
} from '../proofreading';
import {
	registerEditorFormats,
	unregisterEditorFormats,
} from '../../../tests/js/editor-formats';

// Gutenberg registers its suggestion markers as formats when Suggestion
// Mode is on; without them rich text drops the marker attributes.
const markerFormat = (className) => ({
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

beforeAll(() => {
	if (!getBlockTypes().some((type) => type.name === 'core/paragraph')) {
		registerCoreBlocks();
	}
	registerFormatType(
		'core/suggestion-add',
		markerFormat('wp-suggestion-add')
	);
	registerFormatType(
		'core/suggestion-del',
		markerFormat('wp-suggestion-del')
	);
});

afterAll(() => {
	unregisterFormatType('core/suggestion-add');
	unregisterFormatType('core/suggestion-del');
});

const note = (id, blockId, find, replace, extra = {}) => ({
	id,
	block_id: blockId,
	category: 'grammar',
	severity: 'suggestion',
	title: `${find} → ${replace}`,
	quick_fix: { find, replace },
	...extra,
});

/**
 * A registry holding the given paragraphs.
 *
 * @param {string[]} paragraphs HTML of each paragraph.
 * @return {{registry: Object, blocks: Object[]}} Registry and blocks.
 */
function setUp(paragraphs) {
	const registry = createRegistry();
	registry.register(blocksStore);
	registry.register(blockEditorStore);
	// The core blocks live in the default registry's blocks store.
	registry.dispatch(blocksStore).addBlockTypes(getBlockTypes());
	const blocks = paragraphs.map((content) =>
		createBlock('core/paragraph', { content })
	);
	registry.dispatch(blockEditorStore).resetBlocks(blocks);
	return { registry, blocks };
}

const html = (registry, block) =>
	String(
		registry.select(blockEditorStore).getBlock(block.clientId).attributes
			.content
	);

describe('isProofreadingItem / splitProofreading', () => {
	it('picks single-block grammar notes with a fix', () => {
		const items = [
			note(1, 'a', 'teh', 'the'),
			{ id: 2, category: 'content', block_id: 'a' },
			note(3, 'a', 'x', 'y', { is_group: true }),
			{ id: 4, category: 'grammar', block_id: 'a', quick_fix: null },
			note(5, 'b', 'alot', 'a lot', { parent: 1 }),
		];

		expect(items.map(isProofreadingItem)).toEqual([
			true,
			false,
			false,
			false,
			true,
		]);
		const { proofreading, others } = splitProofreading(items);
		expect(proofreading.map((item) => item.id)).toEqual([1, 5]);
		expect(
			splitProofreading([
				note(9, 'a', 'x', 'y', { block_index: 2 }),
				note(8, 'b', 'x', 'y', { block_index: 0 }),
				note(7, 'a', 'x', 'y', { block_index: 2 }),
			]).proofreading.map((item) => item.id)
		).toEqual([8, 7, 9]);
		expect(others.map((item) => item.id)).toEqual([2, 3, 4]);
	});

	it('treats dismissed, applied, accepted and rejected fixes as done', () => {
		const item = note(1, 'a', 'teh', 'the');
		expect(isOpenFix(item, {})).toBe(true);
		expect(isOpenFix(item, { suggestionStatus: 'pending' })).toBe(true);
		expect(isOpenFix({ ...item, is_resolved: true }, {})).toBe(false);
		expect(isOpenFix(item, { isApplied: true })).toBe(false);
		expect(isOpenFix(item, { suggestionStatus: 'applied-unsaved' })).toBe(
			false
		);
		expect(isOpenFix(item, { suggestionStatus: 'rejected' })).toBe(false);
	});
});

describe('applyFixesToContent', () => {
	it('applies two typos in one paragraph, keeping links and formatting', () => {
		const result = applyFixesToContent(
			'Teh <a href="https://example.com">quick brwon</a> fox <strong>jumpd</strong> over.',
			[
				{ find: 'Teh', replace: 'The' },
				{ find: 'brwon', replace: 'brown' },
				{ find: 'jumpd', replace: 'jumped' },
			]
		);

		expect(result.content).toBe(
			'The <a href="https://example.com">quick brown</a> fox <strong>jumped</strong> over.'
		);
		expect(result.applied).toEqual([0, 1, 2]);
		expect(result.skipped).toEqual([]);
	});

	it('replaces each fix where it was found, so one fix never moves another', () => {
		// Applied one after the other, "dgo" → "cat" would make "cat"
		// ambiguous for the second fix.
		const result = applyFixesToContent('A dgo and a cat.', [
			{ find: 'dgo', replace: 'cat' },
			{ find: 'cat', replace: 'kitten' },
		]);

		expect(result.content).toBe('A cat and a kitten.');
		expect(result.applied).toEqual([0, 1]);
	});

	it('skips fixes that no longer match, are ambiguous, overlap or change nothing', () => {
		const result = applyFixesToContent('teh cat sat on teh mat.', [
			{ find: 'cat sat', replace: 'cat sits' },
			{ find: 'teh', replace: 'the' },
			{ find: 'recieve', replace: 'receive' },
			{ find: 'sat on', replace: 'is on' },
			{ find: 'mat', replace: 'mat' },
		]);

		expect(result.content).toBe('teh cat sits on teh mat.');
		expect(result.applied).toEqual([0]);
		expect(result.skipped).toEqual([1, 2, 3, 4]);
	});

	it('returns null content when nothing applies', () => {
		expect(
			applyFixesToContent('Fine text.', [{ find: 'teh', replace: 'the' }])
		).toEqual({ content: null, applied: [], skipped: [0] });
	});

	it('keeps rich text data as rich text data, with offsets past astral characters', () => {
		const result = applyFixesToContent(
			RichTextData.fromHTMLString('😀 teh café is naïve 😀 alot'),
			[
				{ find: 'teh', replace: 'the' },
				{ find: 'alot', replace: 'a lot' },
			]
		);

		expect(result.content).toBeInstanceOf(RichTextData);
		expect(result.content.toHTMLString()).toBe(
			'😀 the café is naïve 😀 a lot'
		);
	});
});

describe('Accept all with the real block editor', () => {
	it('applies every fix that still matches across blocks in one update, and reports the rest', () => {
		const { registry, blocks } = setUp([
			'Teh <a href="https://example.com">quick brwon</a> fox.',
			'You <em>recieve</em> it alot.',
		]);
		const [first, second] = blocks;
		const items = [
			note(1, first.clientId, 'Teh', 'The'),
			note(2, first.clientId, 'brwon', 'brown'),
			note(3, second.clientId, 'recieve', 'receive'),
			note(4, second.clientId, 'alot', 'a lot'),
			// The author already fixed this one by hand.
			note(5, second.clientId, 'You', 'Yuo'),
			note(6, 'missing-block', 'teh', 'the'),
		];
		// Typed after the review.
		registry
			.dispatch(blockEditorStore)
			.updateBlockAttributes(second.clientId, {
				content: 'Yuo <em>recieve</em> it alot.',
			});

		const plan = acceptAllFixes(registry, items);

		expect(html(registry, first)).toBe(
			'The <a href="https://example.com">quick brown</a> fox.'
		);
		expect(html(registry, second)).toBe('Yuo <em>receive</em> it a lot.');
		expect(plan.applied.sort()).toEqual([1, 2, 3, 4]);
		expect(plan.skipped.sort()).toEqual([5, 6]);
		expect(plan.accepted).toEqual([]);

		// One attribute update covered both blocks, so it is one undo step.
		expect(
			Object.keys(
				registry
					.select(blockEditorStore)
					.__experimentalGetLastBlockAttributeChanges()
			).sort()
		).toEqual([first.clientId, second.clientId].sort());
	});

	it('undoes the whole batch, unless a block changed since', () => {
		const { registry, blocks } = setUp(['Teh cat.', 'A dgo.']);
		const [first, second] = blocks;
		const items = [
			note(1, first.clientId, 'Teh', 'The'),
			note(2, second.clientId, 'dgo', 'dog'),
		];

		const plan = acceptAllFixes(registry, items);
		expect(html(registry, first)).toBe('The cat.');
		expect(undoAcceptAll(registry, plan)).toBe(true);
		expect(html(registry, first)).toBe('Teh cat.');
		expect(html(registry, second)).toBe('A dgo.');

		const again = acceptAllFixes(registry, items);
		registry
			.dispatch(blockEditorStore)
			.updateBlockAttributes(second.clientId, { content: 'A dog!' });
		expect(undoAcceptAll(registry, again)).toBe(false);
		expect(html(registry, first)).toBe('The cat.');
	});

	it('does nothing when no fix applies', () => {
		const { registry, blocks } = setUp(['Clean text.']);
		const plan = acceptAllFixes(registry, [
			note(1, blocks[0].clientId, 'teh', 'the'),
		]);

		expect(plan).toEqual({
			updates: {},
			previous: {},
			blocks: {},
			applied: [],
			accepted: [],
			skipped: [1],
		});
		expect(html(registry, blocks[0])).toBe('Clean text.');
	});
});

describe('Fixes already in place', () => {
	it('never applies a fix that adds words twice, after a reload', () => {
		// The fix was applied before the reload, so nothing in this session
		// remembers it; its note was reopened.
		const { registry, blocks } = setUp(['Read the data now.']);
		const items = [note(1, blocks[0].clientId, 'data', 'the data')];

		const plan = acceptAllFixes(registry, items);

		expect(plan.skipped).toEqual([1]);
		expect(html(registry, blocks[0])).toBe('Read the data now.');
		expect(
			applyFixesToContent('Read the data now.', [
				{ find: 'data', replace: 'the data' },
			]).content
		).toBeNull();
	});
});

describe('Accept all with Suggestion Mode', () => {
	const marker = (type, id, text) =>
		`<mark class="wp-suggestion-${type}" data-suggestion-id="${id}" data-suggestion-type="${type}" data-author="0">${text}</mark>`;

	it('accepts pending suggestions in the text, then applies the other fixes in that block', () => {
		const { registry, blocks } = setUp([
			`${marker('add', 7, 'The')}${marker('del', 7, 'Teh')} cat ate alot.`,
		]);
		const [block] = blocks;
		const items = [
			note(7, block.clientId, 'Teh', 'The'),
			note(8, block.clientId, 'alot', 'a lot'),
		];

		const plan = acceptAllFixes(registry, items, { 7: 'pending' });

		expect(html(registry, block)).toBe('The cat ate a lot.');
		expect(plan.accepted).toEqual([7]);
		expect(plan.applied).toEqual([8]);
		expect(plan.skipped).toEqual([]);
	});

	it('skips plain fixes in a block that still holds another suggestion', () => {
		const { registry, blocks } = setUp([
			`We met ${marker('add', 40, 'to')}${marker('del', 40, 'in order to')} plan teh trip.`,
		]);
		const [block] = blocks;

		const plan = acceptAllFixes(registry, [
			note(9, block.clientId, 'teh', 'the'),
		]);

		expect(plan.skipped).toEqual([9]);
		expect(html(registry, block)).toContain('teh trip');
	});

	it('skips a pending suggestion whose markers are gone', () => {
		const plan = planAcceptAll({
			items: [note(7, 'a', 'Teh', 'The')],
			getBlock: () => ({
				name: 'core/paragraph',
				attributes: { content: 'Teh cat.' },
			}),
			getBlockType: () => ({
				attributes: { content: { type: 'rich-text' } },
			}),
			statuses: { 7: 'pending' },
		});

		expect(plan.accepted).toEqual([]);
		expect(plan.skipped).toEqual([7]);
		expect(plan.updates).toEqual({});
	});
});

describe('Accept all beside a footnote', () => {
	// With core's formats, as in the editor, a footnote is one object.
	beforeAll(registerEditorFormats);
	afterAll(unregisterEditorFormats);

	const FOOTNOTE =
		'<sup data-fn="f1" class="fn"><a href="#f1" id="f1-link">1</a></sup>';

	it('applies fixes on both sides and keeps the footnote once', () => {
		const { registry, blocks } = setUp([
			`Teh growth${FOOTNOTE} was strnog.`,
		]);
		const [block] = blocks;

		const plan = acceptAllFixes(registry, [
			note(1, block.clientId, 'Teh', 'The'),
			note(2, block.clientId, 'strnog', 'strong'),
			// Across the footnote's number, which the editor never reads.
			note(3, block.clientId, 'growth1', 'growth'),
		]);

		const result = html(registry, block);
		expect(result).toContain('The growth<sup');
		expect(result).toContain('</sup> was strong.');
		expect(result.split('data-fn="f1"')).toHaveLength(2);
		expect(result.split('>1</a>')).toHaveLength(2);
		expect(plan.applied.sort()).toEqual([1, 2]);
		expect(plan.skipped).toEqual([3]);
	});
});
