/**
 * Grammar & Spelling: proofreading notes, each a single quick fix, which the
 * sidebar groups under one row with Accept all.
 *
 * Accept all works on the blocks as they are when it runs: each fix's text
 * is found in the block's current text and replaced where it was found, so
 * applying one fix never moves another onto different text. A fix whose
 * text is gone, ambiguous or overlaps another fix is skipped. The server
 * only keeps fixes on a block that can be applied together
 * (Response_Parser::resolve_fix_conflicts()).
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as blocksStore } from '@wordpress/blocks';
import { insert, RichTextData } from '@wordpress/rich-text';
import { findUniqueOffset, getRichTextAttributeKey, toHtml } from './quick-fix';
import {
	markReplacement,
	readSource,
	readValue,
	toContent,
} from './source-newlines';
import { acceptSuggestionMarkers, hasSuggestionMarkers } from './suggestions';

/**
 * Category of proofreading notes.
 */
export const GRAMMAR_CATEGORY = 'grammar';

/**
 * Whether a note is a proofreading fix for one block.
 *
 * @param {Object} item Feedback note.
 * @return {boolean} True for single-block grammar notes with a quick fix.
 */
export function isProofreadingItem(item) {
	return (
		item?.category === GRAMMAR_CATEGORY &&
		!item.is_group &&
		!!item.block_id &&
		typeof item.quick_fix?.find === 'string' &&
		typeof item.quick_fix?.replace === 'string'
	);
}

/**
 * Split notes into proofreading fixes and everything else.
 *
 * @param {Object[]} items Feedback notes.
 * @return {{proofreading: Object[], others: Object[]}} Proofreading notes in document order, and the rest in their original order.
 */
export function splitProofreading(items = []) {
	const proofreading = [];
	const others = [];
	items.forEach((item) => {
		(isProofreadingItem(item) ? proofreading : others).push(item);
	});
	// Document order, then note order, however the notes arrived.
	const position = (item) =>
		typeof item.block_index === 'number' ? item.block_index : Infinity;
	proofreading.sort(
		(a, b) => position(a) - position(b) || (a.id ?? 0) - (b.id ?? 0)
	);
	return { proofreading, others };
}

/**
 * Whether a proofreading note still waits on the author.
 *
 * @param {Object}      item                   Feedback note.
 * @param {Object}      state                  Its state in the editor.
 * @param {boolean}     state.isApplied        Whether its fix was applied this session.
 * @param {string|null} state.suggestionStatus Its Gutenberg suggestion status.
 * @return {boolean} True unless it was dismissed, applied, accepted or rejected.
 */
export function isOpenFix(
	item,
	{ isApplied = false, suggestionStatus = null }
) {
	return (
		!item.is_resolved &&
		!isApplied &&
		(!suggestionStatus || suggestionStatus === 'pending')
	);
}

/**
 * Note saves in flight, so reopening a note never lands before resolving it.
 */
const pendingSaves = new Map();

/**
 * Remember the save that resolves some notes until it settles.
 *
 * @param {number[]}         noteIds Note ids.
 * @param {Promise<unknown>} saving  Save in flight.
 * @return {Promise<unknown>} The same save.
 */
export function trackSaves(noteIds, saving) {
	const settled = Promise.resolve(saving).catch(() => {});
	noteIds.forEach((id) => pendingSaves.set(id, settled));
	settled.then(() =>
		noteIds.forEach((id) => {
			if (pendingSaves.get(id) === settled) {
				pendingSaves.delete(id);
			}
		})
	);
	return saving;
}

/**
 * Wait for any tracked save of a note to settle.
 *
 * @param {number} noteId Note id.
 * @return {Promise<void>} Resolves once no save of the note is in flight.
 */
export function whenSaved(noteId) {
	return pendingSaves.get(noteId) ?? Promise.resolve();
}

/**
 * Block content around each fix applied this session, keyed by note id: the
 * HTML before and after, so the editor's Undo and Redo can be told apart
 * from any other edit.
 */
const appliedRecords = new Map();

/**
 * Remember the content a fix was applied to and the content it left.
 *
 * @param {number[]}            noteIds             Notes whose fixes the change applied.
 * @param {Object}              change              The change.
 * @param {string}              change.clientId     Block client id.
 * @param {string}              change.key          Rich text attribute.
 * @param {string|RichTextData} change.before       Value before.
 * @param {string|RichTextData} change.after        Value after.
 * @param {boolean}             [change.suggestion] Whether the notes were suggestions accepted in the text.
 */
export function recordApplied(
	noteIds,
	{ clientId, key, before, after, suggestion = false }
) {
	const record = {
		clientId,
		key,
		before: toHtml(before),
		after: toHtml(after),
		suggestion,
	};
	noteIds.forEach((id) => appliedRecords.set(id, record));
}

/**
 * Forget applied fixes, once Undo in the snackbar takes them back.
 *
 * @param {number[]} noteIds Note ids.
 */
export function forgetApplied(noteIds) {
	noteIds.forEach((id) => appliedRecords.delete(id));
}

/**
 * Where a fix applied this session stands in its block.
 *
 * @param {number}                                         noteId   Note id.
 * @param {(clientId: string) => string|RichTextData|null} getValue Returns a block's value for an attribute, given its record.
 * @return {string|null} `before` when the block reads as it did before the
 *                       fix (the editor's Undo), `after` when it reads as the
 *                       fix left it, `changed` otherwise, or null without a record.
 */
export function getAppliedState(noteId, getValue) {
	const record = appliedRecords.get(noteId);
	if (!record) {
		return null;
	}
	const html = toHtml(getValue(record));
	if (html === record.after) {
		return 'after';
	}
	return html === record.before ? 'before' : 'changed';
}

/**
 * Whether a recorded fix was a suggestion accepted in the text.
 *
 * @param {number} noteId Note id.
 * @return {boolean} True for accepted suggestions.
 */
export function isRecordedSuggestion(noteId) {
	return !!appliedRecords.get(noteId)?.suggestion;
}

/**
 * Apply several quick fixes to one block's content at once.
 *
 * Every fix is located in the content as it is; fixes whose text no longer
 * appears exactly once, or that overlap an earlier fix, are skipped. The
 * rest are replaced from the end of the text backwards, so each offset
 * still holds when its turn comes. Formatting outside the replaced text is
 * kept, and new text takes on the formatting at the start of what it
 * replaces, as with a single quick fix.
 *
 * @param {string|RichTextData} content Current attribute value.
 * @param {Object[]}            fixes   Quick fixes with `find` and `replace`.
 * @return {{content: (string|RichTextData|null), applied: number[], skipped: number[]}} New value (null when nothing applied) and the indexes of the fixes applied and skipped.
 */
export function applyFixesToContent(content, fixes) {
	const source = readSource(
		toHtml(content),
		(fixes || []).map((fix) => fix?.replace ?? '')
	);
	const { marker } = source;
	let value = source.value;
	const read = readValue(value, marker);
	const skipped = [];
	const located = [];

	fixes.forEach((fix, index) => {
		const usable =
			typeof fix?.find === 'string' &&
			typeof fix?.replace === 'string' &&
			fix.find !== '' &&
			fix.find !== fix.replace;
		const start = usable ? findUniqueOffset(read, fix) : -1;
		if (start === -1) {
			skipped.push(index);
		} else {
			located.push({ fix, index, start, end: start + fix.find.length });
		}
	});

	located.sort((a, b) => a.start - b.start);
	const kept = [];
	located.forEach((entry) => {
		const previous = kept[kept.length - 1];
		if (previous && entry.start < previous.end) {
			skipped.push(entry.index);
		} else {
			kept.push(entry);
		}
	});

	if (kept.length === 0) {
		return {
			content: null,
			applied: [],
			skipped: skipped.sort((a, b) => a - b),
		};
	}

	for (const { fix, start, end } of [...kept].reverse()) {
		const length = fix.replace.length;
		value = insert(
			value,
			{
				text: markReplacement(
					value.text.slice(start, end),
					fix.replace,
					marker
				),
				formats: Array.from({ length }, () => value.formats[start]),
				// Sparse, like the arrays rich text builds.
				replacements: new Array(length),
			},
			start,
			end
		);
	}

	return {
		content: toContent(content, value, marker),
		applied: kept.map((entry) => entry.index).sort((a, b) => a - b),
		skipped: skipped.sort((a, b) => a - b),
	};
}

/**
 * Work out what Accept all changes, from the blocks as they are now.
 *
 * Notes whose fix is a pending Gutenberg suggestion are accepted in the
 * text: their deleted runs go and their added runs stay. The other fixes
 * are applied with applyFixesToContent(), unless the block still holds
 * another suggestion's markers, whose text a fix could match inside.
 *
 * @param {Object}                             options              Options.
 * @param {Object[]}                           options.items        Open proofreading notes.
 * @param {(clientId: string) => ?Object}      options.getBlock     Returns a block by client id.
 * @param {(name: string) => Object|undefined} options.getBlockType Returns a block type by name.
 * @param {Object}                             [options.statuses]   Suggestion status by note id.
 * @return {{updates: Object, previous: Object, blocks: Object, applied: number[], accepted: number[], skipped: number[]}} Attribute updates, prior values and the notes applied, keyed by client id, and note ids by outcome.
 */
export function planAcceptAll({
	items,
	getBlock,
	getBlockType,
	statuses = {},
}) {
	const plan = {
		updates: {},
		previous: {},
		blocks: {},
		applied: [],
		accepted: [],
		skipped: [],
	};

	const byBlock = new Map();
	items.filter(isProofreadingItem).forEach((item) => {
		if (!byBlock.has(item.block_id)) {
			byBlock.set(item.block_id, []);
		}
		byBlock.get(item.block_id).push(item);
	});

	byBlock.forEach((blockItems, clientId) => {
		const block = getBlock(clientId);
		const key = block
			? getRichTextAttributeKey(getBlockType(block.name))
			: null;
		if (!key) {
			plan.skipped.push(...blockItems.map((item) => item.id));
			return;
		}

		const original = block.attributes[key];
		let content = original;
		const applied = [];
		const accepted = [];
		const skipped = [];

		const suggested = blockItems.filter(
			(item) => statuses[item.id] === 'pending'
		);
		const plain = blockItems.filter(
			(item) => statuses[item.id] !== 'pending'
		);

		suggested.forEach((item) => {
			const next = acceptSuggestionMarkers(content, item.id);
			if (next === null) {
				skipped.push(item.id);
			} else {
				content = next;
				accepted.push(item.id);
			}
		});

		if (plain.length > 0 && hasSuggestionMarkers(content)) {
			skipped.push(...plain.map((item) => item.id));
		} else if (plain.length > 0) {
			const result = applyFixesToContent(
				content,
				plain.map((item) => item.quick_fix)
			);
			result.applied.forEach((index) => applied.push(plain[index].id));
			result.skipped.forEach((index) => skipped.push(plain[index].id));
			if (result.content !== null) {
				content = result.content;
			}
		}

		// Only a block that really changed counts its fixes as done.
		if (toHtml(content) === toHtml(original)) {
			plan.skipped.push(...blockItems.map((item) => item.id));
			return;
		}

		plan.updates[clientId] = { [key]: content };
		plan.previous[clientId] = { [key]: original };
		plan.blocks[clientId] = { key, applied, accepted };
		plan.applied.push(...applied);
		plan.accepted.push(...accepted);
		plan.skipped.push(...skipped);
	});

	return plan;
}

/**
 * Apply Accept all to the editor as one undo step.
 *
 * The plan is worked out from the blocks at the moment this runs.
 *
 * @param {Object}   registry   Data registry.
 * @param {Object[]} items      Open proofreading notes.
 * @param {Object}   [statuses] Suggestion status by note id.
 * @return {Object} The plan that was applied, see planAcceptAll().
 */
export function acceptAllFixes(registry, items, statuses = {}) {
	const selectors = registry.select(blockEditorStore);
	const plan = planAcceptAll({
		items,
		getBlock: (clientId) => selectors.getBlock(clientId),
		getBlockType: (name) => registry.select(blocksStore).getBlockType(name),
		statuses,
	});

	updateAsOneStep(registry, plan.updates);
	Object.entries(plan.blocks).forEach(
		([clientId, { key, applied, accepted }]) => {
			const change = {
				clientId,
				key,
				before: plan.previous[clientId][key],
				after: plan.updates[clientId][key],
			};
			recordApplied(applied, change);
			recordApplied(accepted, { ...change, suggestion: true });
		}
	);
	return plan;
}

/**
 * Put the blocks Accept all changed back, if nothing changed them since.
 *
 * @param {Object} registry Data registry.
 * @param {Object} plan     Plan returned by acceptAllFixes().
 * @return {boolean} Whether the blocks were restored.
 */
export function undoAcceptAll(registry, plan) {
	const selectors = registry.select(blockEditorStore);
	const unchanged = Object.entries(plan.updates).every(
		([clientId, attributes]) =>
			Object.entries(attributes).every(
				([key, value]) =>
					toHtml(selectors.getBlock(clientId)?.attributes[key]) ===
					toHtml(value)
			)
	);
	if (!unchanged) {
		return false;
	}

	updateAsOneStep(registry, plan.previous);
	forgetApplied([...plan.applied, ...plan.accepted]);
	return true;
}

/**
 * Update several blocks' attributes in one undo level of its own.
 *
 * @param {Object} registry Data registry.
 * @param {Object} updates  Attributes keyed by client id.
 */
function updateAsOneStep(registry, updates) {
	const clientIds = Object.keys(updates);
	if (clientIds.length === 0) {
		return;
	}
	const {
		updateBlockAttributes,
		__unstableMarkLastChangeAsPersistent: markLastChangeAsPersistent,
	} = registry.dispatch(blockEditorStore);
	// Without the marks, the update would merge with the author's last
	// typing, or their next keystroke with it.
	markLastChangeAsPersistent?.();
	updateBlockAttributes(clientIds, updates, { uniqueByBlock: true });
	markLastChangeAsPersistent?.();
}
