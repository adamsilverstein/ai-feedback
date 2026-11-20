/**
 * Keep a block's source newlines when an edit writes its HTML back.
 *
 * `create( { html } )` reads a newline typed in the HTML source the same
 * as a `<br>`, and `toHTMLString()` writes both back as `<br>`, so an edit
 * would add line breaks the author never made. The value an edit works on
 * holds SOURCE_NEWLINE in their place, which follows the text through
 * slices and inserts and is written back as a newline. Matching still
 * reads "\n" (see readValue()), so a `find` matches as it does on the
 * server.
 */
import { create, RichTextData, toHTMLString } from '@wordpress/rich-text';

/**
 * Stands in for a source newline. A private use character, which block
 * text has no reason to hold.
 */
export const SOURCE_NEWLINE = '';

/**
 * Tags and comments, whose newlines aren't text.
 */
const MARKUP = /(<!--[\s\S]*?-->|<[^>]*>)/;

/**
 * Create a rich text value with source newlines marked.
 *
 * Falls back to plain `create( { html } )` when the marked reading doesn't
 * line up with it character for character.
 *
 * @param {string} html HTML.
 * @return {Object} Rich text value.
 */
export function createFromSource(html) {
	const value = create({ html });
	if (!html.includes('\n') || html.includes(SOURCE_NEWLINE)) {
		return value;
	}
	const marked = create({
		html: html
			.split(MARKUP)
			.map((part, index) =>
				index % 2 ? part : part.replaceAll('\n', SOURCE_NEWLINE)
			)
			.join(''),
	});
	return marked.text.replaceAll(SOURCE_NEWLINE, '\n') === value.text
		? marked
		: value;
}

/**
 * The value as the editor reads it, with source newlines as "\n".
 *
 * @param {Object} value Value from createFromSource().
 * @return {Object} Rich text value to match against.
 */
export function readValue(value) {
	return value.text.includes(SOURCE_NEWLINE)
		? { ...value, text: value.text.replaceAll(SOURCE_NEWLINE, '\n') }
		: value;
}

/**
 * Replacement text that keeps the source newlines of the text it replaces
 * where it reads the same, at its start or end.
 *
 * @param {string} text    Marked text being replaced.
 * @param {string} replace Replacement, as plain text.
 * @return {string} Replacement with source newlines marked.
 */
export function markReplacement(text, replace) {
	if (!text.includes(SOURCE_NEWLINE)) {
		return replace;
	}
	const find = text.replaceAll(SOURCE_NEWLINE, '\n');
	const chars = replace.split('');
	let head = 0;
	while (head < find.length && head < replace.length) {
		if (find[head] !== replace[head]) {
			break;
		}
		if (text[head] === SOURCE_NEWLINE) {
			chars[head] = SOURCE_NEWLINE;
		}
		head++;
	}
	for (
		let tail = 1;
		tail <= Math.min(find.length, replace.length) - head;
		tail++
	) {
		if (find[find.length - tail] !== replace[replace.length - tail]) {
			break;
		}
		if (text[text.length - tail] === SOURCE_NEWLINE) {
			chars[replace.length - tail] = SOURCE_NEWLINE;
		}
	}
	return chars.join('');
}

/**
 * HTML of a value, with source newlines written back as newlines.
 *
 * @param {Object} value Rich text value.
 * @return {string} HTML.
 */
export function toSourceHTML(value) {
	return toHTMLString({ value }).replaceAll(SOURCE_NEWLINE, '\n');
}

/**
 * Whether a block's rich text keeps whitespace as written, like verse,
 * preformatted and code do. The editor collapses it in other blocks when
 * it parses them, so their text no longer reads as their HTML does.
 *
 * @param {RichTextData} content Attribute value.
 * @return {boolean} True when whitespace is kept.
 */
function preservesWhiteSpace(content) {
	return (
		typeof content.originalHTML === 'string' &&
		create({ html: content.originalHTML }).text === content.text
	);
}

/**
 * Attribute value for new HTML, read the way the editor reads a block it
 * loads.
 *
 * With a source newline the HTML is kept as written and the text collapses
 * it to a space, as when the author edits the block by hand; verse,
 * preformatted and code keep it as a line. Otherwise the HTML is read as
 * before.
 *
 * @param {string|RichTextData} content Current attribute value.
 * @param {string}              html    New HTML.
 * @return {string|RichTextData} New attribute value.
 */
export function fromSourceHTML(content, html) {
	if (!(content instanceof RichTextData)) {
		return html;
	}
	if (!createFromSource(html).text.includes(SOURCE_NEWLINE)) {
		return new RichTextData(create({ html }));
	}
	// An inert document, so nothing in the content runs or loads.
	const { body } = document.implementation.createHTMLDocument('');
	body.innerHTML = html;
	return RichTextData.fromHTMLElement(body, {
		preserveWhiteSpace: preservesWhiteSpace(content),
	});
}

/**
 * Attribute value for an edited value from createFromSource().
 *
 * @param {string|RichTextData} content Current attribute value.
 * @param {Object}              value   Edited rich text value.
 * @return {string|RichTextData} New attribute value.
 */
export function toContent(content, value) {
	if (!value.text.includes(SOURCE_NEWLINE)) {
		return content instanceof RichTextData
			? new RichTextData(value)
			: toHTMLString({ value });
	}
	return fromSourceHTML(content, toSourceHTML(value));
}
