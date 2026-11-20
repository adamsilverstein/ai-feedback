/**
 * Apply AI quick fixes (plain text find/replace) to block content.
 *
 * When Gutenberg's Suggestion Mode is available the same fixes become
 * suggestions instead (see suggestions.js).
 */
import {
	create,
	insert,
	RichTextData,
	toHTMLString,
} from '@wordpress/rich-text';

/**
 * Find the block's main rich text attribute.
 *
 * @param {Object|undefined} blockType Block type from the blocks store.
 * @return {string|null} Attribute name, or null if the block has none.
 */
export function getRichTextAttributeKey(blockType) {
	const attribute = blockType?.attributes?.content;

	if (
		attribute &&
		(attribute.type === 'rich-text' ||
			attribute.source === 'rich-text' ||
			attribute.source === 'html')
	) {
		return 'content';
	}

	return null;
}

/**
 * Convert a rich text attribute value to an HTML string.
 *
 * @param {string|RichTextData|undefined} value Attribute value.
 * @return {string} HTML string.
 */
export function toHtml(value) {
	if (value === undefined || value === null) {
		return '';
	}
	return typeof value === 'string' ? value : value.toHTMLString();
}

/**
 * Escape a string for use in a regular expression.
 *
 * @param {string} text Text to escape.
 * @return {string} Escaped text.
 */
function escapeRegExp(text) {
	return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * Find every match of a quick fix's text.
 *
 * Text that starts or ends with a letter or digit only matches at a word
 * boundary there, the same rule the server uses (Response_Parser).
 *
 * @param {string} text Plain text to search.
 * @param {string} find Text to find.
 * @return {number[]} Start offsets of each match.
 */
export function findMatches(text, find) {
	if (!find) {
		return [];
	}

	let pattern = escapeRegExp(find);
	if (/^[\p{L}\p{N}]/u.test(find)) {
		pattern = '(?<![\\p{L}\\p{N}])' + pattern;
	}
	if (/[\p{L}\p{N}]$/u.test(find)) {
		pattern += '(?![\\p{L}\\p{N}])';
	}

	return Array.from(text.matchAll(new RegExp(pattern, 'gu')), (match) => {
		return match.index;
	});
}

/**
 * Whether the text at a match of a fix's `find` is already part of its
 * replacement, as after applying "data" → "the data".
 *
 * @param {string} text  Plain text.
 * @param {Object} fix   Quick fix with `find` and `replace`.
 * @param {number} start Offset of a match of `find`.
 * @return {boolean} True when the replacement surrounds the match.
 */
export function isReplacementAt(text, fix, start) {
	if (!fix?.find || !fix.replace) {
		return false;
	}
	for (
		let at = fix.replace.indexOf(fix.find);
		at !== -1;
		at = fix.replace.indexOf(fix.find, at + 1)
	) {
		const from = start - at;
		if (
			from >= 0 &&
			text.slice(from, from + fix.replace.length) === fix.replace
		) {
			return true;
		}
	}
	return false;
}

/**
 * Whether a fix's change is already in content: its text appears once,
 * inside its replacement.
 *
 * @param {string|RichTextData} content Attribute value.
 * @param {Object}              fix     Quick fix with `find` and `replace`.
 * @return {boolean} True when applying the fix again would repeat it.
 */
export function isFixInPlace(content, fix) {
	const { text } = create({ html: toHtml(content) });
	const matches = findMatches(text, fix?.find);
	return matches.length === 1 && isReplacementAt(text, fix, matches[0]);
}

/**
 * Locate the fix's text in rich text content.
 *
 * A match that already sits inside the fix's replacement doesn't count, so
 * a fix that adds words ("data" → "the data") is never applied twice.
 *
 * @param {Object} value Rich text value.
 * @param {Object} fix   Quick fix with `find` and `replace`.
 * @return {number} Start offset, or -1 unless the text appears exactly once and not already replaced.
 */
export function findUniqueOffset(value, fix) {
	const matches = findMatches(value.text, fix?.find);
	return matches.length === 1 && !isReplacementAt(value.text, fix, matches[0])
		? matches[0]
		: -1;
}

/**
 * Apply a quick fix to rich text content.
 *
 * Formatting outside the replaced range is kept, and the new text takes on
 * the formatting at the start of the text it replaces.
 *
 * @param {string|RichTextData} content Current attribute value.
 * @param {Object}              fix     Quick fix with `find` and `replace`.
 * @return {string|RichTextData|null} New attribute value, or null if the fix no longer applies.
 */
export function applyFixToContent(content, fix) {
	const value = create({ html: toHtml(content) });
	const start = findUniqueOffset(value, fix);

	if (start === -1) {
		return null;
	}

	// Carry over the formatting of the replaced text's first character, so a
	// fix inside bold or a link stays bold or linked.
	const length = fix.replace.length;
	const replacement = {
		text: fix.replace,
		formats: Array.from({ length }, () => value.formats[start]),
		// Sparse, like the arrays rich text builds: core-data reads every
		// entry that exists when it orders footnotes.
		replacements: new Array(length),
	};
	const updated = insert(value, replacement, start, start + fix.find.length);

	return content instanceof RichTextData
		? new RichTextData(updated)
		: toHTMLString({ value: updated });
}

/**
 * Build a short before/after preview of a quick fix.
 *
 * @param {string|RichTextData} content Current attribute value.
 * @param {Object}              fix     Quick fix with `find` and `replace`.
 * @param {number}              context Characters of context on each side.
 * @return {{before: string, removed: string, added: string, after: string}|null} Preview parts, or null if the fix no longer applies.
 */
export function getFixPreview(content, fix, context = 60) {
	const value = create({ html: toHtml(content) });
	const start = findUniqueOffset(value, fix);

	if (start === -1) {
		return null;
	}

	const end = start + fix.find.length;
	const beforeStart = Math.max(0, start - context);
	const afterEnd = Math.min(value.text.length, end + context);

	return {
		before:
			(beforeStart > 0 ? '…' : '') + value.text.slice(beforeStart, start),
		removed: fix.find,
		added: fix.replace,
		after:
			value.text.slice(end, afterEnd) +
			(afterEnd < value.text.length ? '…' : ''),
	};
}
