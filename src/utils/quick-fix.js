/**
 * Apply AI quick fixes (plain text find/replace) to block content.
 *
 * Kept self-contained so it can be swapped for core's note suggestions once
 * Gutenberg ships them (see #145).
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
function toHtml(value) {
	if (value === undefined || value === null) {
		return '';
	}
	return typeof value === 'string' ? value : value.toHTMLString();
}

/**
 * Locate the fix's text in rich text content.
 *
 * @param {Object} value Rich text value.
 * @param {Object} fix   Quick fix with `find` and `replace`.
 * @return {number} Start offset, or -1 unless the text appears exactly once.
 */
function findUniqueOffset(value, fix) {
	if (!fix?.find) {
		return -1;
	}

	const start = value.text.indexOf(fix.find);
	if (start === -1 || value.text.indexOf(fix.find, start + 1) !== -1) {
		return -1;
	}

	return start;
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
		replacements: Array.from({ length }),
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
