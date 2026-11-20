/**
 * Formatting fixes: a review note's `block_change`.
 *
 * Block_Change validates them on the server:
 *
 * - `{ type: 'set_attributes', attributes, from }` sets a heading's `level`,
 *   a list's `ordered`, or an image's `alt` or `caption`. `from` holds the
 *   values the AI saw.
 * - `{ type: 'transform', to: 'core/list', items, ordered }` turns a
 *   paragraph of separated items into a list; `to` may also be
 *   `core/heading` (with `level`) or, from a heading, `core/paragraph`.
 * - `{ type: 'split', after, next }` splits a paragraph after a sentence;
 *   `next` is the start of the text after it.
 *
 * Alt text and captions keep the reviewed image's URL as `image`, and
 * heading and paragraph transforms the reviewed `text`, so a change to the
 * block reads as outdated.
 *
 * Each is checked again here against the live blocks, at render time and
 * again inside applyBlockChange(), and applied as one undo step. Text is
 * sliced from the block's rich text value, so links and formatting stay.
 *
 * Helpers take the block editor's selectors and actions as arguments, so
 * they work with any registry.
 */
import { __, sprintf } from '@wordpress/i18n';
import { createBlock, switchToBlockType } from '@wordpress/blocks';
import { create, slice, toHTMLString } from '@wordpress/rich-text';
import { toHtml } from './quick-fix';
import { excerpt, getBlockText } from './block-action';

/**
 * Formatting fix types.
 */
const TYPES = ['set_attributes', 'transform', 'split'];

/**
 * Attributes a `set_attributes` change may set, per block type. Matches
 * Block_Change::SETTABLE_ATTRIBUTES.
 */
export const SETTABLE_ATTRIBUTES = {
	'core/heading': ['level'],
	'core/list': ['ordered'],
	'core/image': ['alt', 'caption'],
};

/**
 * Block types a `transform` may turn a block into. Matches
 * Block_Change::TRANSFORMS.
 */
export const TRANSFORMS = {
	'core/paragraph': ['core/list', 'core/heading'],
	'core/heading': ['core/paragraph'],
};

/**
 * Text allowed between two list items. Matches Block_Change::LIST_GAP.
 */
const LIST_GAP =
	/^[\s,;、，；]+(?:(?:and|or|&|und|oder|et|ou|y|o|e|en)\s+)?$/iu;

/**
 * Text allowed after the last list item.
 */
const LIST_TAIL = /^[\s.,;!?…。]*$/u;

/**
 * Whether a value is a formatting fix this editor can act on.
 *
 * @param {Object|null} change Value from a note.
 * @return {boolean} True for a well-formed change.
 */
export function isBlockChange(change) {
	if (!change || !TYPES.includes(change.type)) {
		return false;
	}
	if (change.type === 'set_attributes') {
		return (
			!!change.attributes &&
			typeof change.attributes === 'object' &&
			Object.keys(change.attributes).length > 0 &&
			!!change.from &&
			Object.keys(change.attributes).every((key) => key in change.from)
		);
	}
	if (change.type === 'transform') {
		return change.to === 'core/list'
			? Array.isArray(change.items)
			: typeof change.to === 'string' && typeof change.text === 'string';
	}
	return (
		typeof change.after === 'string' &&
		change.after !== '' &&
		typeof change.next === 'string' &&
		change.next !== ''
	);
}

/**
 * Collapse whitespace, keeping where each character came from.
 *
 * @param {string} text Raw text.
 * @return {{text: string, map: number[]}} Normalized text, and for each of its characters (plus the end) the index in the raw text.
 */
export function normalizeWithMap(text) {
	let out = '';
	const map = [];
	let pendingSpace = -1;
	for (let index = 0; index < text.length; index++) {
		const char = text[index];
		if (/\s/u.test(char)) {
			if (out !== '' && pendingSpace === -1) {
				pendingSpace = index;
			}
			continue;
		}
		if (pendingSpace !== -1) {
			out += ' ';
			map.push(pendingSpace);
			pendingSpace = -1;
		}
		out += char;
		map.push(index);
	}
	// The end maps past the last kept character, before trailing space.
	map.push(map.length ? map[map.length - 1] + 1 : 0);
	return { text: out, map };
}

/**
 * Collapse whitespace, as the server does.
 *
 * @param {string} text Text.
 * @return {string} Normalized text.
 */
function normalize(text) {
	return normalizeWithMap(text || '').text;
}

/**
 * Find list items in a paragraph's text, as Block_Change::match_list_items()
 * does on the server.
 *
 * @param {string}   text  Normalized text.
 * @param {string[]} items Normalized items.
 * @return {{lead: [number, number], items: Array<[number, number]>}|null} Ranges of the introduction and of each item, or null when they don't match.
 */
export function matchListItems(text, items) {
	let position = 0;
	let lead = [0, 0];
	const ranges = [];
	for (let index = 0; index < items.length; index++) {
		const item = items[index];
		const found = item ? text.indexOf(item, position) : -1;
		if (found === -1) {
			return null;
		}
		const gap = text.slice(position, found);
		if (index === 0) {
			if (/[\p{L}\p{N}]$/u.test(gap)) {
				return null;
			}
			lead = [0, gap.trimEnd().length];
		} else if (!LIST_GAP.test(gap)) {
			return null;
		}
		ranges.push([found, found + item.length]);
		position = found + item.length;
	}
	return LIST_TAIL.test(text.slice(position))
		? { lead, items: ranges }
		: null;
}

/**
 * Find where a split falls, as Block_Change::validate_split() checks it.
 *
 * @param {string} text  Normalized text.
 * @param {string} after Normalized sentence ending.
 * @return {number} Index in `text` right after the sentence, or -1.
 */
export function findSplit(text, after) {
	const first = text.indexOf(after);
	if (!after || first === -1 || text.indexOf(after, first + 1) !== -1) {
		return -1;
	}
	const end = first + after.length;
	const rest = text.slice(end);
	return rest.startsWith(' ') && rest.trim() !== '' ? end : -1;
}

/**
 * Rich text value of a block's main text attribute.
 *
 * @param {Object} block Block.
 * @return {Object} Rich text value.
 */
function getValue(block) {
	return create({ html: toHtml(block?.attributes?.content) });
}

/**
 * Current value of an attribute a change sets, comparable with `from`.
 *
 * @param {Object} block Block.
 * @param {string} key   Attribute.
 * @return {number|boolean|string|undefined} Value.
 */
function currentValue(block, key) {
	const attributes = block?.attributes || {};
	switch (key) {
		case 'level':
			return attributes.level ?? 2;
		case 'ordered':
			return !!attributes.ordered;
		case 'alt':
			return normalize(attributes.alt ?? '');
		case 'caption':
			return normalize(create({ html: toHtml(attributes.caption) }).text);
		default:
			return undefined;
	}
}

/**
 * Text of each item of a list block.
 *
 * @param {Object} block List block.
 * @return {string[]} Item texts.
 */
function getListItems(block) {
	return (block?.innerBlocks || []).map((item) => getBlockText(item));
}

/**
 * The block after a block, among its siblings.
 *
 * @param {Object} selectors Block editor selectors.
 * @param {string} clientId  Client ID.
 * @return {Object|null} Next block.
 */
function getNextBlock(selectors, clientId) {
	const order = selectors.getBlockOrder(
		selectors.getBlockRootClientId(clientId) ?? ''
	);
	const id = order[order.indexOf(clientId) + 1];
	return id ? selectors.getBlock(id) : null;
}

/**
 * Whether the editor offers turning a block into another type, as the
 * block toolbar's transform menu does: the transform exists, and the type
 * may be inserted there.
 *
 * @param {Object} selectors Block editor selectors.
 * @param {string} clientId  Client ID.
 * @param {string} name      Target block type.
 * @return {boolean} True when the transform is offered.
 */
function canTransform(selectors, clientId, name) {
	const block = selectors.getBlock(clientId);
	const offered =
		selectors.getBlockTransformItems?.(
			[block],
			selectors.getBlockRootClientId(clientId) ?? ''
		) ?? [];
	return offered.some((item) => item?.name === name);
}

/**
 * Where a formatting fix stands against the blocks in the editor.
 *
 * - `missing`: the note's block is gone.
 * - `outdated`: the block changed since the review, or the editor doesn't
 *   offer the change.
 * - `applied`: the block already reads as the change leaves it.
 * - `ready`: the change can be applied.
 *
 * @param {Object}      selectors Block editor selectors.
 * @param {Object}      change    Formatting fix.
 * @param {string|null} clientId  Client ID of the note's block, from findNoteBlock().
 * @return {{state: string}} State.
 */
export function getBlockChangeState(selectors, change, clientId) {
	const block = clientId ? selectors.getBlock(clientId) : null;
	if (!block) {
		return { state: 'missing' };
	}

	if (change.type === 'set_attributes') {
		const keys = Object.keys(change.attributes);
		const allowed = SETTABLE_ATTRIBUTES[block.name] || [];
		if (
			!keys.every((key) => allowed.includes(key)) ||
			// Alt text or a caption written for another image.
			(change.image !== undefined &&
				block.attributes?.url !== change.image)
		) {
			return { state: 'outdated' };
		}
		if (
			keys.every(
				(key) => currentValue(block, key) === change.attributes[key]
			)
		) {
			return { state: 'applied' };
		}
		return {
			state: keys.every(
				(key) => currentValue(block, key) === change.from[key]
			)
				? 'ready'
				: 'outdated',
		};
	}

	if (change.type === 'transform') {
		if (block.name === change.to) {
			const isThere =
				change.to === 'core/list'
					? getListItems(block).join('\n') ===
							change.items.join('\n') &&
						!!block.attributes?.ordered === !!change.ordered
					: getBlockText(block) === change.text &&
						(change.to !== 'core/heading' ||
							(block.attributes?.level ?? 2) === change.level);
			return { state: isThere ? 'applied' : 'outdated' };
		}
		const isReady =
			(TRANSFORMS[block.name] || []).includes(change.to) &&
			canTransform(selectors, clientId, change.to) &&
			(change.to === 'core/list'
				? !!getListSplit(block, change.items)
				: getBlockText(block) === change.text);
		return { state: isReady ? 'ready' : 'outdated' };
	}

	if (change.type === 'split' && block.name === 'core/paragraph') {
		const text = normalize(getValue(block).text);
		if (findSplit(text, change.after) !== -1) {
			return { state: 'ready' };
		}
		// Applied when this block ends the sentence and the next one starts
		// with the text that followed it.
		const next = getNextBlock(selectors, clientId);
		return {
			state:
				text.endsWith(change.after) &&
				next?.name === 'core/paragraph' &&
				getBlockText(next).startsWith(change.next)
					? 'applied'
					: 'outdated',
		};
	}

	return { state: 'outdated' };
}

/**
 * Slice a rich text value between offsets of its normalized text.
 *
 * @param {Object}   value Rich text value.
 * @param {number[]} map   Normalized to raw offsets, from normalizeWithMap().
 * @param {number}   start Normalized start.
 * @param {number}   end   Normalized end.
 * @return {string} HTML of the slice, with its formatting.
 */
function sliceHtml(value, map, start, end) {
	return toHTMLString({ value: slice(value, map[start], map[end]) });
}

/**
 * Whether a range of a rich text value holds an object, such as an inline
 * image, that would be lost if the range were dropped.
 *
 * @param {Object} value Rich text value.
 * @param {number} start Raw start.
 * @param {number} end   Raw end.
 * @return {boolean} True when it holds one.
 */
function holdsObject(value, start, end) {
	for (let index = start; index < end; index++) {
		if (value.replacements?.[index]) {
			return true;
		}
	}
	return false;
}

/**
 * Split a paragraph's rich text into an introduction and list items.
 *
 * Only separators are dropped. If one holds an object, such as an inline
 * image, the change isn't offered.
 *
 * @param {Object}   block Paragraph block.
 * @param {string[]} items Items from the change.
 * @return {{lead: string, items: string[]}|null} HTML of the introduction ('' for none) and of each item, or null when the items don't match.
 */
export function getListSplit(block, items) {
	const value = getValue(block);
	const { text, map } = normalizeWithMap(value.text);
	const match = matchListItems(text, items.map(normalize));
	if (!match) {
		return null;
	}
	// Everything outside the introduction and the items is dropped.
	const [leadStart, leadEnd] = match.lead;
	const kept = [
		...(leadEnd > leadStart ? [[map[leadStart], map[leadEnd]]] : []),
		...match.items.map(([start, end]) => [map[start], map[end]]),
	];
	let cursor = 0;
	for (const [start, end] of kept) {
		if (holdsObject(value, cursor, start)) {
			return null;
		}
		cursor = end;
	}
	if (holdsObject(value, cursor, value.text.length)) {
		return null;
	}
	return {
		lead:
			leadEnd > leadStart
				? sliceHtml(value, map, leadStart, leadEnd)
				: '',
		items: match.items.map(([start, end]) =>
			sliceHtml(value, map, start, end)
		),
	};
}

/**
 * Split a paragraph's rich text after a sentence.
 *
 * @param {Object} block Paragraph block.
 * @param {string} after Sentence ending.
 * @return {[string, string]|null} HTML of both halves, or null when the split no longer applies.
 */
export function getParagraphSplit(block, after) {
	const value = getValue(block);
	const { text, map } = normalizeWithMap(value.text);
	const end = findSplit(text, normalize(after));
	if (end === -1 || holdsObject(value, map[end], map[end + 1])) {
		return null;
	}
	return [
		sliceHtml(value, map, 0, end),
		sliceHtml(value, map, end + 1, text.length),
	];
}

/**
 * Attribute updates for a `set_attributes` change.
 *
 * @param {Object} change Change.
 * @return {Object} Attributes to set. A caption is rich text, so it is escaped.
 */
function getAttributeUpdates(change) {
	const updates = { ...change.attributes };
	if (typeof updates.caption === 'string') {
		updates.caption = toHTMLString({
			value: create({ text: updates.caption }),
		});
	}
	return updates;
}

/**
 * Build the blocks a transform or split replaces the note's block with.
 *
 * The note's link (`metadata`) stays on the block that carries its text:
 * the new list or heading, or the first half of a split.
 *
 * @param {Object} block  Note's block.
 * @param {Object} change Transform or split.
 * @return {Object[]|null} Replacement blocks, or null when they can't be built.
 */
export function buildReplacementBlocks(block, change) {
	const metadata = block.attributes?.metadata;
	const withMetadata = (attributes) =>
		metadata ? { ...attributes, metadata } : attributes;

	try {
		if (change.type === 'split') {
			const halves = getParagraphSplit(block, change.after);
			if (!halves) {
				return null;
			}
			const { metadata: _meta, anchor, ...rest } = block.attributes;
			return [
				createBlock(
					'core/paragraph',
					withMetadata({ ...rest, anchor, content: halves[0] })
				),
				createBlock('core/paragraph', { ...rest, content: halves[1] }),
			];
		}

		if (change.to === 'core/list') {
			const split = getListSplit(block, change.items);
			if (!split) {
				return null;
			}
			// Links to the paragraph's anchor go to its introduction, or the list.
			const anchor = block.attributes?.anchor;
			const list = createBlock(
				'core/list',
				withMetadata({
					ordered: !!change.ordered,
					...(anchor && !split.lead ? { anchor } : {}),
				}),
				split.items.map((content) =>
					createBlock('core/list-item', { content })
				)
			);
			return split.lead
				? [
						createBlock('core/paragraph', {
							content: split.lead,
							...(anchor ? { anchor } : {}),
						}),
						list,
					]
				: [list];
		}

		const switched = switchToBlockType(block, change.to);
		if (!Array.isArray(switched) || switched.length !== 1) {
			return null;
		}
		const [result] = switched;
		const attributes = withMetadata({ ...result.attributes });
		if (change.to === 'core/heading') {
			attributes.level = change.level ?? 2;
		}
		return [{ ...result, attributes }];
	} catch {
		// An unregistered block type.
		return null;
	}
}

/**
 * Apply a formatting fix as one undo step.
 *
 * @param {Object} selectors Block editor selectors.
 * @param {Object} actions   Block editor actions.
 * @param {Object} change    Formatting fix.
 * @param {string} clientId  Client ID of the note's block.
 * @return {string|null} Client ID of the block that now carries the note, or null when the change wasn't made.
 */
export function applyBlockChange(selectors, actions, change, clientId) {
	// Only a change that is ready right now, whoever calls this.
	if (getBlockChangeState(selectors, change, clientId).state !== 'ready') {
		return null;
	}
	const block = selectors.getBlock(clientId);

	let run;
	let resultId = clientId;
	if (change.type === 'set_attributes') {
		run = () =>
			actions.updateBlockAttributes(
				clientId,
				getAttributeUpdates(change)
			);
	} else {
		const blocks = buildReplacementBlocks(block, change);
		if (!blocks) {
			return null;
		}
		// The block carrying the note: a split's first half, or the new
		// list or heading, after any introduction.
		resultId =
			change.type === 'split'
				? blocks[0].clientId
				: blocks[blocks.length - 1].clientId;
		run = () => actions.replaceBlocks(clientId, blocks);
	}

	actions.__unstableMarkLastChangeAsPersistent?.();
	run();
	// The editor can refuse a change, eg. inside a locked template, without
	// an error, so check the blocks now read as the change leaves them.
	if (getBlockChangeState(selectors, change, resultId).state !== 'applied') {
		return null;
	}
	actions.__unstableMarkLastChangeAsPersistent?.();
	return resultId;
}

/**
 * Describe a formatting fix in one line, eg. "Change to a level 3 heading".
 *
 * @param {Object} change Formatting fix.
 * @return {string} Description.
 */
export function describeBlockChange(change) {
	if (change?.type === 'split') {
		return sprintf(
			/* translators: %s: end of the sentence the paragraph is split after. */
			__('Split the paragraph after “%s”', 'fresh-eyes'),
			excerpt(change.after)
		);
	}
	if (change?.type === 'transform') {
		switch (change.to) {
			case 'core/list':
				return change.ordered
					? __('Turn into a numbered list', 'fresh-eyes')
					: __('Turn into a list', 'fresh-eyes');
			case 'core/heading':
				return sprintf(
					/* translators: %d: heading level. */
					__('Turn into a level %d heading', 'fresh-eyes'),
					change.level ?? 2
				);
			default:
				return __('Turn into a paragraph', 'fresh-eyes');
		}
	}

	const attributes = change?.attributes || {};
	if (attributes.level !== undefined) {
		return sprintf(
			/* translators: %d: heading level. */
			__('Change to a level %d heading', 'fresh-eyes'),
			attributes.level
		);
	}
	if (attributes.ordered !== undefined) {
		return attributes.ordered
			? __('Make it a numbered list', 'fresh-eyes')
			: __('Make it a bulleted list', 'fresh-eyes');
	}
	if (attributes.alt !== undefined) {
		return sprintf(
			/* translators: %s: proposed alt text. */
			__('Add alt text: “%s”', 'fresh-eyes'),
			attributes.alt
		);
	}
	return sprintf(
		/* translators: %s: proposed caption. */
		__('Add a caption: “%s”', 'fresh-eyes'),
		attributes.caption ?? ''
	);
}

/**
 * Short label of a block for the preview, eg. "H4 Setup".
 *
 * @param {string} name       Block name.
 * @param {Object} attributes Block attributes.
 * @param {string} text       Block text.
 * @return {string} Label.
 */
function previewLabel(name, attributes, text) {
	const shown = excerpt(text);
	switch (name) {
		case 'core/heading':
			return `H${attributes.level ?? 2} ${shown}`;
		case 'core/list':
			return attributes.ordered
				? sprintf(
						/* translators: %s: start of the list's text. */
						__('Numbered list: %s', 'fresh-eyes'),
						shown
					)
				: sprintf(
						/* translators: %s: start of the list's text. */
						__('List: %s', 'fresh-eyes'),
						shown
					);
		case 'core/image':
			return __('Image', 'fresh-eyes');
		default:
			return sprintf(
				/* translators: %s: start of the paragraph's text. */
				__('Paragraph: %s', 'fresh-eyes'),
				shown
			);
	}
}

/**
 * Before and after lines of a formatting fix, for its preview.
 *
 * @param {Object} block  Note's block.
 * @param {Object} change Formatting fix.
 * @return {{before: string[], after: string[]}} Lines.
 */
export function getBlockChangePreview(block, change) {
	const text = getBlockText(block);
	const attributes = block.attributes || {};
	const before = [previewLabel(block.name, attributes, text)];

	if (change.type === 'set_attributes') {
		const updated = { ...attributes, ...change.attributes };
		if (block.name === 'core/image') {
			const describe = (alt, caption) => [
				sprintf(
					/* translators: %s: alt text, or "none". */
					__('Alt text: %s', 'fresh-eyes'),
					alt ? `“${alt}”` : __('none', 'fresh-eyes')
				),
				...(caption
					? [
							sprintf(
								/* translators: %s: caption. */
								__('Caption: “%s”', 'fresh-eyes'),
								caption
							),
						]
					: []),
			];
			return {
				before: describe(
					currentValue(block, 'alt'),
					currentValue(block, 'caption')
				),
				after: describe(
					change.attributes.alt ?? currentValue(block, 'alt'),
					change.attributes.caption ?? currentValue(block, 'caption')
				),
			};
		}
		return { before, after: [previewLabel(block.name, updated, text)] };
	}

	if (change.type === 'split') {
		const halves = getParagraphSplit(block, change.after);
		return {
			before,
			after: halves
				? halves.map((html) =>
						previewLabel(
							'core/paragraph',
							{},
							create({ html }).text
						)
					)
				: [],
		};
	}

	if (change.to === 'core/list') {
		const split = getListSplit(block, change.items);
		const lines = (split?.items || []).map(
			(html, index) =>
				`${change.ordered ? `${index + 1}.` : '•'} ${create({ html }).text}`
		);
		return {
			before,
			after: split?.lead
				? [
						previewLabel(
							'core/paragraph',
							{},
							create({ html: split.lead }).text
						),
						...lines,
					]
				: lines,
		};
	}

	return {
		before,
		after: [previewLabel(change.to, { level: change.level ?? 2 }, text)],
	};
}
