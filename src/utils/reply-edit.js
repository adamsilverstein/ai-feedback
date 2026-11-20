/**
 * Edits that AI replies propose for their note's block.
 *
 * An edit is either `{ type: 'replace_text', find, replace }`, applied like a
 * quick fix, or `{ type: 'rewrite_block', content, original }`, which
 * replaces the block's whole text. `original` is the block text the AI saw,
 * so a rewrite is outdated once the block no longer reads the same. The AI
 * only sees plain text, so a rewrite also never applies to a block with
 * links, formatting or line breaks, which it would drop.
 * Response_Parser validates both on the server.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	create,
	RichTextData,
	slice,
	toHTMLString,
} from '@wordpress/rich-text';
import {
	applyFixToContent,
	findMatches,
	findUniqueOffset,
	getFixPreview,
	toHtml,
} from './quick-fix';
import { isReplyEdit } from './is-reply-edit';

export { isReplyEdit };

/**
 * Most token pairs compared when diffing a rewrite. Above this the rewrite is
 * shown as one replacement instead of a word diff.
 */
export const MAX_DIFF_CELLS = 250000;

/**
 * Collapse whitespace so texts compare the same however they wrap.
 *
 * @param {string} text Text.
 * @return {string} Normalized text.
 */
export function normalizeText(text) {
	return (text || '').replace(/\s+/gu, ' ').trim();
}

/**
 * Whether an edit still applies to the block content.
 *
 * Quick fixes (`{ find, replace }` without a type) are checked like a
 * `replace_text` edit.
 *
 * @param {string|RichTextData} content Current attribute value.
 * @param {Object}              edit    Edit or quick fix.
 * @return {boolean} True when the edit can be applied.
 */
export function isEditCurrent(content, edit) {
	const html = toHtml(content);
	const value = create({ html });
	if (edit?.type === 'rewrite_block') {
		return (
			!html.includes('<') &&
			normalizeText(value.text) === normalizeText(edit.original)
		);
	}
	return findUniqueOffset(value, edit) !== -1;
}

/**
 * Whether the block already reads as an edit would leave it.
 *
 * Applied state is only kept for the session, so after a reload this tells
 * an edit the author applied from one that went out of date. A deletion
 * can't be told apart, so it reads as not applied.
 *
 * @param {string|RichTextData} content Current attribute value.
 * @param {Object}              edit    Edit or quick fix.
 * @return {boolean} True when the block holds the edit's result.
 */
export function isEditApplied(content, edit) {
	const value = create({ html: toHtml(content) });
	if (edit?.type === 'rewrite_block') {
		const after = create({ html: edit.content });
		return normalizeText(value.text) === normalizeText(after.text);
	}
	if (!edit?.replace) {
		return false;
	}
	return (
		findMatches(value.text, edit.find).length === 0 &&
		findMatches(value.text, edit.replace).length === 1
	);
}

/**
 * Apply an edit to block content.
 *
 * @param {string|RichTextData} content Current attribute value.
 * @param {Object}              edit    Edit or quick fix.
 * @return {string|RichTextData|null} New value, or null if the edit no longer applies.
 */
export function applyEditToContent(content, edit) {
	if (edit?.type !== 'rewrite_block') {
		return applyFixToContent(content, edit);
	}
	if (!isEditCurrent(content, edit)) {
		return null;
	}
	return content instanceof RichTextData
		? RichTextData.fromHTMLString(edit.content)
		: edit.content;
}

/**
 * Split text into words, runs of whitespace and single other characters.
 *
 * @param {string} text Text.
 * @return {string[]} Tokens.
 */
function tokenize(text) {
	return text.match(/\s+|[\p{L}\p{N}_'’-]+|[^\s\p{L}\p{N}_'’-]/gu) || [];
}

/**
 * Describe the formatting of a range so formatting-only changes show up.
 *
 * @param {Object} value Rich text value.
 * @param {number} start Start offset.
 * @param {number} end   End offset.
 * @return {string} Formatting key.
 */
function formatKey(value, start, end) {
	let key = '';
	for (let i = start; i < end; i++) {
		key +=
			(value.formats[i] || [])
				.map(
					(format) =>
						format.type + JSON.stringify(format.attributes || {})
				)
				.join(',') + '|';
	}
	return key;
}

/**
 * Tokenize a rich text value, keeping each token's offsets.
 *
 * @param {Object} value Rich text value.
 * @return {Array<{start: number, end: number, key: string}>} Tokens.
 */
function tokenizeValue(value) {
	const tokens = [];
	let start = 0;
	for (const text of tokenize(value.text)) {
		const end = start + text.length;
		tokens.push({ start, end, key: text + formatKey(value, start, end) });
		start = end;
	}
	return tokens;
}

/**
 * Word diff of two rich text values.
 *
 * Returns runs of unchanged text and changed hunks, with offsets into each
 * value. A hunk replaces `[from, to)` of the old value with `[newFrom, newTo)`
 * of the new one. Text whose formatting changed counts as changed. Above
 * MAX_DIFF_CELLS, or when most of the text changed, the whole text is one
 * hunk, which reads better than a diff that is mostly markers.
 *
 * @param {Object} before Old rich text value.
 * @param {Object} after  New rich text value.
 * @return {Array<Object>} `{ type: 'equal', from, to, newFrom, newTo }` and `{ type: 'change', from, to, newFrom, newTo }` runs in order.
 */
export function diffValues(before, after) {
	const whole = [
		{
			type: 'change',
			from: 0,
			to: before.text.length,
			newFrom: 0,
			newTo: after.text.length,
		},
	];
	const a = tokenizeValue(before);
	const b = tokenizeValue(after);

	if (a.length * b.length > MAX_DIFF_CELLS) {
		return whole;
	}

	// Longest common subsequence table, filled from the end.
	const width = b.length + 1;
	const table = new Uint32Array((a.length + 1) * width);
	for (let i = a.length - 1; i >= 0; i--) {
		for (let j = b.length - 1; j >= 0; j--) {
			table[i * width + j] =
				a[i].key === b[j].key
					? table[(i + 1) * width + j + 1] + 1
					: Math.max(
							table[(i + 1) * width + j],
							table[i * width + j + 1]
						);
		}
	}

	const runs = [];
	const push = (type, i0, i1, j0, j1) => {
		const run = {
			type,
			from: i0 < a.length ? a[i0].start : before.text.length,
			to: i1 > 0 ? a[i1 - 1].end : 0,
			newFrom: j0 < b.length ? b[j0].start : after.text.length,
			newTo: j1 > 0 ? b[j1 - 1].end : 0,
		};
		if (i0 === i1) {
			run.to = run.from;
		}
		if (j0 === j1) {
			run.newTo = run.newFrom;
		}
		runs.push(run);
	};

	let i = 0;
	let j = 0;
	let changedChars = 0;
	while (i < a.length || j < b.length) {
		const i0 = i;
		const j0 = j;
		if (i < a.length && j < b.length && a[i].key === b[j].key) {
			while (i < a.length && j < b.length && a[i].key === b[j].key) {
				i++;
				j++;
			}
			push('equal', i0, i, j0, j);
			continue;
		}
		while (
			(i < a.length || j < b.length) &&
			!(i < a.length && j < b.length && a[i].key === b[j].key)
		) {
			if (
				j >= b.length ||
				(i < a.length &&
					table[(i + 1) * width + j] >= table[i * width + j + 1])
			) {
				i++;
			} else {
				j++;
			}
		}
		push('change', i0, i, j0, j);
		const last = runs[runs.length - 1];
		changedChars += last.to - last.from + (last.newTo - last.newFrom);
	}

	const total = before.text.length + after.text.length;
	if (total > 0 && changedChars / total > 0.5) {
		return whole;
	}

	return mergeChanges(runs, before);
}

/**
 * Join changes separated only by whitespace, so "last year" to "in 2025"
 * reads as one change instead of two around a shared space.
 *
 * @param {Array<Object>} runs   Diff runs.
 * @param {Object}        before Old rich text value.
 * @return {Array<Object>} Runs with those changes merged.
 */
function mergeChanges(runs, before) {
	const merged = [];
	for (let index = 0; index < runs.length; index++) {
		const run = runs[index];
		const previous = merged[merged.length - 1];
		const next = runs[index + 1];
		if (
			run.type === 'equal' &&
			previous?.type === 'change' &&
			next?.type === 'change' &&
			!before.text.slice(run.from, run.to).trim()
		) {
			merged[merged.length - 1] = {
				...previous,
				to: next.to,
				newTo: next.newTo,
			};
			index++;
			continue;
		}
		if (run.type === 'change' && previous?.type === 'change') {
			merged[merged.length - 1] = {
				...previous,
				to: run.to,
				newTo: run.newTo,
			};
			continue;
		}
		merged.push(run);
	}
	return merged;
}

/**
 * Build a before/after preview of an edit.
 *
 * @param {string|RichTextData} content Current attribute value.
 * @param {Object}              edit    Edit or quick fix.
 * @return {Array<{type: string, text: string}>|null} Parts typed `equal`, `removed` or `added`, or null if the edit no longer applies.
 */
export function getEditPreview(content, edit) {
	if (edit?.type !== 'rewrite_block') {
		const preview = getFixPreview(content, edit);
		if (!preview) {
			return null;
		}
		return [
			{ type: 'equal', text: preview.before },
			{ type: 'removed', text: preview.removed },
			{ type: 'added', text: preview.added },
			{ type: 'equal', text: preview.after },
		].filter((part) => part.text);
	}

	if (!isEditCurrent(content, edit)) {
		return null;
	}

	const before = create({ html: toHtml(content) });
	const after = create({ html: edit.content });
	const parts = [];
	for (const run of diffValues(before, after)) {
		if (run.type === 'equal') {
			parts.push({
				type: 'equal',
				text: before.text.slice(run.from, run.to),
			});
			continue;
		}
		parts.push({
			type: 'removed',
			text: before.text.slice(run.from, run.to),
		});
		parts.push({
			type: 'added',
			text: after.text.slice(run.newFrom, run.newTo),
		});
	}
	return parts.filter((part) => part.text);
}

/**
 * Describe a quick fix or `replace_text` edit in one short sentence.
 *
 * @param {Object} fix Quick fix with `find` and `replace`.
 * @return {string} Description.
 */
export function describeFix(fix) {
	if (!fix.replace) {
		return sprintf(
			/* translators: %s: text to delete */
			__('Delete “%s”', 'fresh-eyes'),
			fix.find
		);
	}
	return sprintf(
		/* translators: 1: text to replace, 2: replacement text */
		__('Replace “%1$s” with “%2$s”', 'fresh-eyes'),
		fix.find,
		fix.replace
	);
}

/**
 * Describe an edit in one short sentence.
 *
 * @param {Object} edit Edit or quick fix.
 * @return {string} Description.
 */
export function describeEdit(edit) {
	if (edit?.type === 'rewrite_block') {
		return __('Rewrite this block', 'fresh-eyes');
	}
	return describeFix(edit);
}

/**
 * Get the rich text slices of a rewrite's word diff, for building markers.
 *
 * @param {string|RichTextData} content Current attribute value.
 * @param {Object}              edit    `rewrite_block` edit.
 * @return {Array<{type: string, html: string, removedHtml?: string}>|null} `equal` runs carry the old HTML, `change` runs the added and removed HTML, or null if the edit no longer applies.
 */
export function getRewriteRuns(content, edit) {
	if (edit?.type !== 'rewrite_block' || !isEditCurrent(content, edit)) {
		return null;
	}

	const before = create({ html: toHtml(content) });
	const after = create({ html: edit.content });

	return diffValues(before, after).map((run) => {
		const old = toHTMLString({ value: slice(before, run.from, run.to) });
		if (run.type === 'equal') {
			return { type: 'equal', html: old };
		}
		return {
			type: 'change',
			html: toHTMLString({
				value: slice(after, run.newFrom, run.newTo),
			}),
			removedHtml: old,
		};
	});
}
