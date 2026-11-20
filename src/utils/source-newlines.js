/**
 * Keep a block's source newlines when an edit writes its HTML back.
 *
 * `create( { html } )` reads a newline typed in the HTML source the same
 * as a `<br>`, and `toHTMLString()` writes both back as `<br>`, so an edit
 * would add line breaks the author never made. The value an edit works on
 * holds a marker character in their place, which follows the text
 * through slices and inserts and is written back as a newline. Matching still
 * reads "\n" (see readValue()), so a `find` matches as it does on the
 * server.
 */
import { create, RichTextData, toHTMLString } from '@wordpress/rich-text';

/**
 * Private use characters to stand in for a source newline. The first one
 * the HTML and the edit's own text don't hold is used, so a character an
 * author pasted, such as an icon font glyph, is never read as a newline.
 */
const FIRST_MARKER = 0xe000;
const LAST_MARKER = 0xf8ff;

/**
 * Tags and comments, whose newlines aren't text.
 */
const MARKUP = /(<!--[\s\S]*?-->|<[^>]*>)/;

/**
 * Create a rich text value with source newlines replaced by a marker.
 *
 * @param {string} html   HTML.
 * @param {string} marker Stand-in character, absent from the HTML.
 * @return {Object|null} Marked value, or null when it doesn't line up with `create( { html } )` character for character.
 */
export function markSource(html, marker) {
	const marked = create({
		html: html
			.split(MARKUP)
			.map((part, index) =>
				index % 2 ? part : part.replaceAll('\n', marker)
			)
			.join(''),
	});
	return marked.text.replaceAll(marker, '\n') === create({ html }).text
		? marked
		: null;
}

/**
 * Read block HTML for an edit, with its source newlines marked.
 *
 * Without a source newline, or when no marker is free or the marked
 * reading doesn't line up, `marker` is null and `value` is plain
 * `create( { html } )`, so the edit works as it always has.
 *
 * @param {string}   html  HTML.
 * @param {string[]} texts Other text the edit brings in, such as its replacement.
 * @return {{value: Object, marker: (string|null)}} Value and the marker it uses.
 */
export function readSource(html, texts = []) {
	if (html.includes('\n')) {
		for (let code = FIRST_MARKER; code <= LAST_MARKER; code++) {
			const marker = String.fromCharCode(code);
			if (
				html.includes(marker) ||
				texts.some((text) => String(text).includes(marker))
			) {
				continue;
			}
			const value = markSource(html, marker);
			if (value?.text.includes(marker)) {
				return { value, marker };
			}
			break;
		}
	}
	return { value: create({ html }), marker: null };
}

/**
 * The value as the editor reads it, with source newlines as "\n".
 *
 * @param {Object}      value  Value from readSource().
 * @param {string|null} marker Its marker.
 * @return {Object} Rich text value to match against.
 */
export function readValue(value, marker) {
	return marker
		? { ...value, text: value.text.replaceAll(marker, '\n') }
		: value;
}

/**
 * Replacement text that keeps the source newlines of the text it replaces
 * where it reads the same, at its start or end.
 *
 * @param {string}      text    Marked text being replaced.
 * @param {string}      replace Replacement, as plain text.
 * @param {string|null} marker  Marker in `text`.
 * @return {string} Replacement with source newlines marked.
 */
export function markReplacement(text, replace, marker) {
	if (!marker || !text.includes(marker)) {
		return replace;
	}
	const find = text.replaceAll(marker, '\n');
	const chars = replace.split('');
	let head = 0;
	while (head < find.length && head < replace.length) {
		if (find[head] !== replace[head]) {
			break;
		}
		if (text[head] === marker) {
			chars[head] = marker;
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
		if (text[text.length - tail] === marker) {
			chars[replace.length - tail] = marker;
		}
	}
	return chars.join('');
}

/**
 * HTML of a value, with source newlines written back as newlines.
 *
 * @param {Object}      value  Rich text value.
 * @param {string|null} marker Its marker.
 * @return {string} HTML.
 */
export function toSourceHTML(value, marker) {
	const html = toHTMLString({ value });
	return marker ? html.replaceAll(marker, '\n') : html;
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
	if (!readSource(html).marker) {
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
 * Attribute value for an edited value from readSource().
 *
 * @param {string|RichTextData} content Current attribute value.
 * @param {Object}              value   Edited rich text value.
 * @param {string|null}         marker  Its marker.
 * @return {string|RichTextData} New attribute value.
 */
export function toContent(content, value, marker) {
	if (!marker || !value.text.includes(marker)) {
		return content instanceof RichTextData
			? new RichTextData(value)
			: toHTMLString({ value });
	}
	return fromSourceHTML(content, toSourceHTML(value, marker));
}
