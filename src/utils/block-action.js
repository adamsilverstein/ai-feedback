/**
 * Structural edits: removing a note's block, moving it next to another
 * block, or inserting a new block next to it.
 *
 * A review note carries one as `block_action`, and an AI reply can propose
 * one as its `edit`. Response_Parser validates them on the server:
 *
 * - `{ type: 'remove_block' }`
 * - `{ type: 'move_block', position, target_block_id, target_name, target_text }`
 * - `{ type: 'insert_block', position, block: { name, content, level } }`, or
 *   `block: { name: 'core/list', items, ordered }` for a list.
 *
 * `position` is `before` or `after`. A move's target is found by its client
 * ID while that still exists, then by its block name and the start of its
 * text, which is how it is found after a reload or from a reply, where the
 * server never knew client IDs.
 *
 * Helpers take the block editor's selectors and actions as arguments, so
 * they work with any registry.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { createBlock, serialize } from '@wordpress/blocks';
import { extractEditorText, extractTextContent } from './block-utils';
import { isBlockAction } from './is-reply-edit';

export { isBlockAction };

/**
 * Length of block text shown in descriptions and previews.
 */
const EXCERPT_LENGTH = 40;

/**
 * Collapse whitespace so texts compare the same however they wrap.
 *
 * @param {string} text Text.
 * @return {string} Normalized text.
 */
function normalizeText(text) {
	return (text || '').replace(/\s+/gu, ' ').trim();
}

/**
 * Shorten text for a description.
 *
 * @param {string} text Text.
 * @return {string} At most EXCERPT_LENGTH characters, with an ellipsis when cut.
 */
export function excerpt(text) {
	const normalized = normalizeText(text);
	return normalized.length > EXCERPT_LENGTH
		? `${normalized.slice(0, EXCERPT_LENGTH - 1).trimEnd()}…`
		: normalized;
}

/**
 * Plain text of an HTML string, read as the editor reads it, so a line
 * break separates words as it does in the text the server stores.
 *
 * @param {string} html HTML.
 * @return {string} Text.
 */
function htmlText(html) {
	return normalizeText(extractEditorText(html || ''));
}

/**
 * Plain text of a block, including its inner blocks.
 *
 * @param {Object|null} block Block from the block editor store.
 * @return {string} Whitespace-collapsed text.
 */
export function getBlockText(block) {
	if (!block) {
		return '';
	}
	try {
		return htmlText(serialize(block));
	} catch {
		return '';
	}
}

/**
 * Every reading of a block's text the server may have stored for it.
 *
 * Besides getBlockText(): without inline images, as a reply reads the
 * block, and with line breaks dropped, as reviews read it before.
 *
 * @param {Object|null} block Block from the block editor store.
 * @return {string[]} Whitespace-collapsed texts, getBlockText() first.
 */
export function getBlockTexts(block) {
	const text = getBlockText(block);
	if (!block) {
		return [text];
	}
	let legacy = '';
	try {
		legacy = normalizeText(extractTextContent(serialize(block)));
	} catch {}
	return [
		...new Set([text, normalizeText(text.replace(/\ufffc/g, '')), legacy]),
	];
}

/**
 * Every reading of a block's own text, without its inner blocks, as the
 * server keeps a move target's text: a quote's citation, a details
 * block's summary, a list item's text without its nested list.
 *
 * @param {Object|null} block Block from the block editor store.
 * @return {string[]} Whitespace-collapsed texts, as getBlockTexts().
 */
export function getOwnBlockTexts(block) {
	return getBlockTexts(
		block?.innerBlocks?.length ? { ...block, innerBlocks: [] } : block
	);
}

/**
 * Text of the block an insert adds.
 *
 * @param {Object} spec The edit's `block`.
 * @return {string} Text.
 */
function getInsertedText(spec) {
	return Array.isArray(spec?.items)
		? spec.items.map(htmlText).join(' ')
		: htmlText(spec?.content);
}

/**
 * Describe a structural edit in one line, eg. "Move above “Why this matters”".
 *
 * @param {Object} action     Structural edit.
 * @param {number} [inside=0] Blocks inside the block a removal takes with it.
 * @return {string} Description.
 */
export function describeBlockAction(action, inside = 0) {
	const isBefore = action?.position === 'before';
	if (action?.type === 'remove_block') {
		return inside > 0
			? sprintf(
					/* translators: %d: number of blocks inside the block to remove. */
					_n(
						'Remove this block and the %d block inside it',
						'Remove this block and the %d blocks inside it',
						inside,
						'fresh-eyes'
					),
					inside
				)
			: __('Remove this block', 'fresh-eyes');
	}
	if (action?.type === 'move_block') {
		const target = excerpt(action.target_text);
		return isBefore
			? /* translators: %s: start of the text of the block to move next to. */
				sprintf(__('Move above “%s”', 'fresh-eyes'), target)
			: /* translators: %s: start of the text of the block to move next to. */
				sprintf(__('Move below “%s”', 'fresh-eyes'), target);
	}
	if (action?.type !== 'insert_block') {
		return '';
	}

	const text = excerpt(getInsertedText(action.block));
	switch (action.block?.name) {
		case 'core/heading':
			return isBefore
				? /* translators: %s: text of the heading to add. */
					sprintf(__('Add a heading above: “%s”', 'fresh-eyes'), text)
				: sprintf(
						/* translators: %s: text of the heading to add. */
						__('Add a heading below: “%s”', 'fresh-eyes'),
						text
					);
		case 'core/list':
			return isBefore
				? /* translators: %s: text of the list to add. */
					sprintf(__('Add a list above: “%s”', 'fresh-eyes'), text)
				: /* translators: %s: text of the list to add. */
					sprintf(__('Add a list below: “%s”', 'fresh-eyes'), text);
		default:
			return isBefore
				? /* translators: %s: text of the block to add. */
					sprintf(__('Add a block above: “%s”', 'fresh-eyes'), text)
				: /* translators: %s: text of the block to add. */
					sprintf(__('Add a block below: “%s”', 'fresh-eyes'), text);
	}
}

/**
 * Whether a block links a note through `metadata.noteId`.
 *
 * @param {Object|null} block  Block.
 * @param {number}      noteId Note ID.
 * @return {boolean} True when linked.
 */
function linksNote(block, noteId) {
	const linked = block?.attributes?.metadata?.noteId;
	return (Array.isArray(linked) ? linked : [linked]).some(
		(id) => id !== undefined && id !== null && Number(id) === Number(noteId)
	);
}

/**
 * Every block inside a block, at any depth.
 *
 * @param {Object} selectors Block editor selectors.
 * @param {string} clientId  Client ID.
 * @return {string[]} Client IDs of the inner blocks.
 */
export function getInnerBlockIds(selectors, clientId) {
	return selectors
		.getBlockOrder(clientId)
		.flatMap((id) => [id, ...getInnerBlockIds(selectors, id)]);
}

/**
 * Finds the block a note is attached to.
 *
 * @param {Object}           selectors Block editor selectors.
 * @param {string}           clientId  Client ID stored with the note.
 * @param {number|undefined} noteId    Note ID, used once the client ID is stale.
 * @return {string|null} Client ID, or null when the block is gone.
 */
export function findNoteBlock(selectors, clientId, noteId) {
	if (clientId && selectors.getBlock(clientId)) {
		return clientId;
	}
	if (!noteId) {
		return null;
	}
	return (
		selectors
			.getClientIdsWithDescendants()
			.find((id) => linksNote(selectors.getBlock(id), noteId)) ?? null
	);
}

/**
 * Length of a move target's text kept on the server.
 */
const TARGET_TEXT_LENGTH = 80;

/**
 * Whether a block's text is the one a move's `target_text` was kept from.
 *
 * Shorter text than the kept length is the whole text, so it must match
 * exactly; otherwise the block's text must start with it. Matches
 * Response_Parser::matches_target_text().
 *
 * @param {string} blockText  Block text.
 * @param {string} targetText Kept target text.
 * @return {boolean} True when they match.
 */
export function matchesTargetText(blockText, targetText) {
	const text = normalizeText(blockText);
	// Counted in characters, as the server cuts it, not UTF-16 units.
	return Array.from(targetText).length < TARGET_TEXT_LENGTH
		? text === targetText
		: text.startsWith(targetText);
}

/**
 * Finds the block a move puts the note's block next to.
 *
 * Its client ID wins while it still names a block of the same type.
 * Otherwise the one block of that type whose own text starts with the
 * stored text is used, preferring the note block's own siblings when there are
 * several.
 *
 * @param {Object} selectors Block editor selectors.
 * @param {Object} action    `move_block` edit.
 * @param {string} clientId  Client ID of the note's block.
 * @return {string|null} Target client ID, or null when it can't be found for sure.
 */
export function findMoveTarget(selectors, action, clientId) {
	const stored = action.target_block_id;
	if (
		stored &&
		stored !== clientId &&
		selectors.getBlock(stored)?.name === action.target_name
	) {
		return stored;
	}

	const text = normalizeText(action.target_text);
	if (!text) {
		return null;
	}

	const candidates = selectors
		.getClientIdsWithDescendants()
		.map((id) => ({ id, block: selectors.getBlock(id) }))
		.filter(
			({ id, block }) =>
				id !== clientId && block?.name === action.target_name
		);
	const matching = (read) =>
		candidates
			.filter(({ block }) =>
				read(block).some((blockText) =>
					matchesTargetText(blockText, text)
				)
			)
			.map(({ id }) => id);
	// A block's own text first, as the server keeps it now. Moves saved
	// before kept a parent's text with its inner blocks', so that reading
	// is only tried when no block's own text matches.
	let matches = matching(getOwnBlockTexts);
	if (matches.length === 0) {
		matches = matching(getBlockTexts);
	}
	if (matches.length === 1) {
		return matches[0];
	}

	const parent = selectors.getBlockRootClientId(clientId);
	const siblings = matches.filter(
		(id) => selectors.getBlockRootClientId(id) === parent
	);
	return siblings.length === 1 ? siblings[0] : null;
}

/**
 * Whether a suggestion marker is a move recorded for someone other than a
 * note: Gutenberg's own, in Suggest intent. Gutenberg adds its note's id as
 * `commentId` once its auto-save has created the note.
 *
 * @param {Object|undefined} marker `metadata.suggestion` marker.
 * @param {number}           noteId The note the move belongs to.
 * @return {boolean} True for a `pending-move` that does not carry the note's id.
 */
export function isCapturedMoveMarker(marker, noteId) {
	return (
		marker?.type === 'pending-move' &&
		Number(marker.commentId ?? 0) !== Number(noteId)
	);
}

/**
 * Whether Gutenberg holds a note's move as its own pending suggestion.
 *
 * Gutenberg marks the moved block, or for a swap of two neighbors the block
 * it moved past, which is the move's target. Other blocks in the parent may
 * hold moves that have nothing to do with this one, and a marker carrying
 * another AI note's id is that note's own suggestion.
 *
 * @param {Object}      selectors   Block editor selectors.
 * @param {string|null} clientId    Client ID of the note's block.
 * @param {string|null} targetId    Client ID of the block it moves next to.
 * @param {number}      noteId      The note the move belongs to.
 * @param {number[]}    [aiNoteIds] IDs of the review's AI notes.
 * @return {boolean} True when the move is pending in Gutenberg.
 */
export function hasCapturedMove(
	selectors,
	clientId,
	targetId,
	noteId,
	aiNoteIds = []
) {
	return [clientId, targetId].some((id) => {
		const marker = id
			? selectors.getBlockAttributes(id)?.metadata?.suggestion
			: null;
		return (
			isCapturedMoveMarker(marker, noteId) &&
			!aiNoteIds.some(
				(aiNoteId) => Number(aiNoteId) === Number(marker.commentId)
			)
		);
	});
}

/**
 * Where a move would put the block, as `moveBlocksToPosition()` needs it.
 *
 * The index counts the parent's blocks once the moved block has left it.
 *
 * @param {Object} selectors Block editor selectors.
 * @param {Object} action    `move_block` edit.
 * @param {string} clientId  Client ID of the block to move.
 * @param {string} targetId  Client ID of the block to move next to.
 * @return {{fromRootClientId: string, toRootClientId: string, index: number}} Move arguments.
 */
export function getMovePosition(selectors, action, clientId, targetId) {
	const fromRootClientId = selectors.getBlockRootClientId(clientId) ?? '';
	const toRootClientId = selectors.getBlockRootClientId(targetId) ?? '';
	let index = selectors.getBlockIndex(targetId);
	if (
		fromRootClientId === toRootClientId &&
		selectors.getBlockIndex(clientId) < index
	) {
		index -= 1;
	}
	return {
		fromRootClientId,
		toRootClientId,
		index: index + (action.position === 'after' ? 1 : 0),
	};
}

/**
 * The block next to another one on the given side.
 *
 * @param {Object} selectors Block editor selectors.
 * @param {string} clientId  Client ID.
 * @param {number} offset    -1 for the block before, 1 for the block after.
 * @return {Object|null} Neighboring block, or null at either end.
 */
function getNeighbor(selectors, clientId, offset) {
	const order = selectors.getBlockOrder(
		selectors.getBlockRootClientId(clientId) ?? ''
	);
	const id = order[order.indexOf(clientId) + offset];
	return id ? selectors.getBlock(id) : null;
}

/**
 * Where a structural edit stands against the blocks in the editor.
 *
 * - `missing`: the note's block is gone, which an applied removal also leaves.
 * - `outdated`: a move's target can't be found.
 * - `applied`: the post already reads as the edit would leave it.
 * - `blocked`: a removal would take blocks linked to other notes with it.
 * - `ready`: the edit can be applied.
 *
 * A removal also reports how many blocks are `inside` the block.
 *
 * @param {Object}      selectors Block editor selectors.
 * @param {Object}      action    Structural edit.
 * @param {string|null} clientId  Client ID of the note's block, from findNoteBlock().
 * @return {{state: string, targetId: (string|null), inside: (number|undefined)}} State, a move's target, and a removal's inner block count.
 */
export function getBlockActionState(selectors, action, clientId) {
	if (!clientId || !selectors.getBlock(clientId)) {
		return { state: 'missing', targetId: null };
	}

	const offset = action.position === 'before' ? -1 : 1;
	if (action.type === 'move_block') {
		const targetId = findMoveTarget(selectors, action, clientId);
		// A block can't move next to one of its own inner blocks: the block
		// editor would put it inside itself and never return.
		if (
			!targetId ||
			(selectors.getBlockParents?.(targetId) ?? []).includes(clientId)
		) {
			return { state: 'outdated', targetId: null };
		}
		// Moved before a block, the block's next neighbor is the target.
		const neighbor = getNeighbor(selectors, clientId, -offset);
		return {
			state: neighbor?.clientId === targetId ? 'applied' : 'ready',
			targetId,
		};
	}

	if (action.type === 'insert_block') {
		const neighbor = getNeighbor(selectors, clientId, offset);
		const spec = action.block;
		// A heading's level and a list's type are part of the edit; both
		// blocks default to an h2 and a bulleted list.
		const isThere =
			neighbor?.name === spec.name &&
			(neighbor.attributes?.level ?? 2) === (spec.level ?? 2) &&
			!!neighbor.attributes?.ordered === !!spec.ordered &&
			getBlockText(neighbor) === getInsertedText(spec);
		return { state: isThere ? 'applied' : 'ready', targetId: null };
	}

	// A removal takes every inner block with it. Removing one linked to
	// another note would leave that note pointing at nothing.
	const inside = getInnerBlockIds(selectors, clientId);
	const linksOtherNote = inside.some(
		(id) =>
			(selectors.getBlock(id)?.attributes?.metadata?.noteId ?? null) !==
			null
	);
	return {
		state: linksOtherNote ? 'blocked' : 'ready',
		targetId: null,
		inside: inside.length,
	};
}

/**
 * Build the block an insert adds.
 *
 * Paragraphs, headings and lists are built from their fields; any other
 * allowed type gets its text as its `content` attribute.
 *
 * @param {Object} spec The edit's `block`.
 * @return {Object|null} New block, or null when it can't be built.
 */
export function buildInsertedBlock(spec) {
	try {
		if (spec.name === 'core/list') {
			return createBlock(
				'core/list',
				{ ordered: !!spec.ordered },
				spec.items.map((content) =>
					createBlock('core/list-item', { content })
				)
			);
		}
		if (spec.name === 'core/heading') {
			return createBlock('core/heading', {
				content: spec.content,
				level: spec.level ?? 2,
			});
		}
		return typeof spec.content === 'string'
			? createBlock(spec.name, { content: spec.content })
			: null;
	} catch {
		// An unregistered block type.
		return null;
	}
}

/**
 * Apply a structural edit as one undo step.
 *
 * The undo level is closed before and after, so the change neither merges
 * with typing before it nor with typing after it.
 *
 * @param {Object} selectors Block editor selectors.
 * @param {Object} actions   Block editor actions.
 * @param {Object} action    Structural edit.
 * @param {string} clientId  Client ID of the note's block.
 * @return {boolean} Whether the blocks now read as the edit leaves them.
 */
export function applyBlockAction(selectors, actions, action, clientId) {
	// Only an edit that is ready right now, whoever calls this.
	const { state: before, targetId } = getBlockActionState(
		selectors,
		action,
		clientId
	);
	if (before !== 'ready') {
		return false;
	}
	let run = null;
	if (action.type === 'remove_block') {
		run = () => actions.removeBlocks([clientId], false);
	} else if (action.type === 'move_block' && targetId) {
		const { fromRootClientId, toRootClientId, index } = getMovePosition(
			selectors,
			action,
			clientId,
			targetId
		);
		run = () =>
			actions.moveBlocksToPosition(
				[clientId],
				fromRootClientId,
				toRootClientId,
				index
			);
	} else if (action.type === 'insert_block') {
		const block = buildInsertedBlock(action.block);
		if (block) {
			const rootClientId = selectors.getBlockRootClientId(clientId) ?? '';
			const index =
				selectors.getBlockIndex(clientId) +
				(action.position === 'after' ? 1 : 0);
			run = () =>
				actions.insertBlocks([block], index, rootClientId, false);
		}
	}

	if (!run) {
		return false;
	}
	actions.__unstableMarkLastChangeAsPersistent?.();
	run();
	// The editor can refuse a change, eg. inside a locked template, without
	// an error, so check the blocks now read as the edit leaves them.
	const { state } = getBlockActionState(selectors, action, clientId);
	const isDone =
		action.type === 'remove_block'
			? state === 'missing'
			: state === 'applied';
	if (isDone) {
		actions.__unstableMarkLastChangeAsPersistent?.();
	}
	return isDone;
}

/**
 * One line of a structural edit's preview.
 *
 * @typedef {Object} OutlineLine
 * @property {string} key    Stable key.
 * @property {string} label  Block type and the start of its text.
 * @property {string} [mark] `removed`, `moved` or `added` for the lines the edit changes.
 */

/**
 * Label a block for the preview outline.
 *
 * @param {(name: string) => (string|undefined)} getTitle Returns a block type's title from its name.
 * @param {string}                               name     Block name.
 * @param {string}                               text     Block text.
 * @return {string} Label.
 */
function outlineLabel(getTitle, name, text) {
	const title = getTitle(name) || name;
	return text ? `${title}: ${excerpt(text)}` : title;
}

/**
 * Keep the lines around the changed ones, with an ellipsis for the rest.
 *
 * @param {OutlineLine[]} lines   Lines.
 * @param {number[]}      [focus] Other indexes to keep around.
 * @return {OutlineLine[]} Lines near a change.
 */
function aroundChanges(lines, focus = []) {
	const marked = lines
		.map((line, index) => (line.mark ? index : -1))
		.filter((index) => index !== -1);
	const changed = [...marked, ...focus].filter(
		(index) => index >= 0 && index < lines.length
	);
	if (changed.length === 0) {
		return lines;
	}
	const first = Math.max(0, Math.min(...changed) - 1);
	const last = Math.min(lines.length - 1, Math.max(...changed) + 1);
	const kept = lines.slice(first, last + 1);
	if (first > 0) {
		kept.unshift({ key: 'start', label: '…' });
	}
	if (last < lines.length - 1) {
		kept.push({ key: 'end', label: '…' });
	}
	return kept;
}

/**
 * Before and after outlines of the blocks a structural edit changes.
 *
 * Shows the blocks at the level the edit lands on: the note block's
 * siblings, or a move target's siblings when it moves to another parent.
 *
 * @param {Object}                               selectors Block editor selectors.
 * @param {(name: string) => (string|undefined)} getTitle  Returns a block type's title from its name.
 * @param {Object}                               action    Structural edit.
 * @param {string}                               clientId  Client ID of the note's block.
 * @param {string}                               targetId  A move's target.
 * @return {{before: OutlineLine[], after: OutlineLine[]}} Outlines.
 */
export function getBlockActionOutline(
	selectors,
	getTitle,
	action,
	clientId,
	targetId
) {
	const anchorId = action.type === 'move_block' ? targetId : clientId;
	const rootClientId = selectors.getBlockRootClientId(anchorId) ?? '';
	const line = (id, mark) => {
		const block = selectors.getBlock(id);
		return {
			key: `${id}-${mark ?? ''}`,
			label: outlineLabel(getTitle, block?.name, getBlockText(block)),
			mark,
		};
	};

	const order = selectors.getBlockOrder(rootClientId);
	const before = order.map((id) =>
		line(
			id,
			id === clientId && action.type !== 'insert_block'
				? 'moved'
				: undefined
		)
	);
	if (action.type === 'remove_block') {
		const removed = before[order.indexOf(clientId)];
		const inside = getInnerBlockIds(selectors, clientId).length;
		removed.mark = 'removed';
		if (inside > 0) {
			removed.label = sprintf(
				/* translators: 1: block label, 2: number of blocks inside it. */
				_n(
					'%1$s, with %2$d block inside',
					'%1$s, with %2$d blocks inside',
					inside,
					'fresh-eyes'
				),
				removed.label,
				inside
			);
		}
	}

	let after = order.map((id) => line(id));
	if (action.type === 'remove_block') {
		after = after.filter((_, index) => order[index] !== clientId);
	} else if (action.type === 'move_block') {
		after = order.filter((id) => id !== clientId).map((id) => line(id));
		const { index } = getMovePosition(
			selectors,
			action,
			clientId,
			targetId
		);
		after.splice(index, 0, line(clientId, 'moved'));
	} else if (action.type === 'insert_block') {
		const index =
			order.indexOf(clientId) + (action.position === 'after' ? 1 : 0);
		after.splice(index, 0, {
			key: 'inserted',
			label: outlineLabel(
				getTitle,
				action.block.name,
				getInsertedText(action.block)
			),
			mark: 'added',
		});
	}

	// Keep a move's target in view, and the neighbors a removal leaves.
	let beforeFocus = [];
	let afterFocus = [];
	if (action.type === 'move_block') {
		beforeFocus = [order.indexOf(targetId)];
		afterFocus = [after.findIndex((item) => item.key === `${targetId}-`)];
	} else if (action.type === 'remove_block') {
		afterFocus = [order.indexOf(clientId)];
	}
	return {
		before: aroundChanges(before, beforeFocus),
		after: aroundChanges(after, afterFocus),
	};
}
