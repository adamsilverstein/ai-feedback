/**
 * Register the rich text formats core registers in the editor, so tests read
 * block HTML the way the editor does: a footnote or a formula is one object
 * character (U+FFFC), not its text.
 *
 * The list is shared with Editor_Text::FORMATS on the server through
 * tests/fixtures/editor-text.json.
 */
import { registerFormatType, unregisterFormatType } from '@wordpress/rich-text';

const { formats } = require('../fixtures/editor-text.json');

/**
 * Register the editor's formats.
 */
export function registerEditorFormats() {
	for (const format of formats) {
		registerFormatType(format.name, {
			title: format.name,
			tagName: format.tagName,
			className: format.className,
			attributes: Object.fromEntries(
				format.attributes.map((name) => [name, name])
			),
			...(format.contentEditable ? {} : { contentEditable: false }),
			// Rich text reads a format that only changes the editable tree
			// as plain text.
			...(format.asText
				? { __experimentalCreatePrepareEditableTree: () => (f) => f }
				: {}),
			edit: () => null,
		});
	}
}

/**
 * Unregister the editor's formats.
 */
export function unregisterEditorFormats() {
	for (const format of formats) {
		unregisterFormatType(format.name);
	}
}
