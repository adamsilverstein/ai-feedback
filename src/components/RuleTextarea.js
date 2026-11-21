/**
 * RuleTextarea component.
 *
 * A text area for a house style rule that grows to fit the whole rule, so
 * nothing is saved that the author can't see. Rules are one line, so line
 * breaks become spaces, as the server would make them. Enter never adds a
 * line break; it submits only when the caller passes `onSubmit`.
 */
import { TextareaControl } from '@wordpress/components';

/**
 * Rough characters per line in the sidebar, for the row count.
 */
const CHARS_PER_LINE = 24;

/**
 * Most rows before the field scrolls; a 150 character rule fits.
 */
const MAX_ROWS = 10;

/**
 * Rows needed to show a rule in full.
 *
 * @param {string} text Rule text.
 * @return {number} Rows.
 */
export function getRuleRows(text) {
	return Math.min(
		MAX_ROWS,
		Math.max(2, Math.ceil((text || '').length / CHARS_PER_LINE) + 1)
	);
}

/**
 * Make typed or pasted text a single line.
 *
 * @param {string} text Text.
 * @return {string} Text without line breaks.
 */
export function toSingleLine(text) {
	return String(text || '').replace(/\s*[\r\n]+\s*/g, ' ');
}

/**
 * @param {Object}                  props
 * @param {string}                  props.label      Field label.
 * @param {string}                  props.value      Rule text.
 * @param {(text: string) => void}  props.onChange   Called with the new single-line text.
 * @param {number}                  props.maxLength  Most characters in a rule.
 * @param {(event: Object) => void} [props.onSubmit] Called when Enter is pressed.
 * @param {string}                  [props.help]     Help text.
 * @return {Element} Text area.
 */
export default function RuleTextarea({
	label,
	value,
	onChange,
	maxLength,
	onSubmit,
	help,
	...props
}) {
	return (
		<TextareaControl
			__nextHasNoMarginBottom
			className="ai-feedback-rule-textarea"
			label={label}
			help={help}
			value={value}
			rows={getRuleRows(value)}
			maxLength={maxLength}
			onChange={(text) => onChange(toSingleLine(text))}
			onKeyDown={(event) => {
				if (event.key === 'Enter' && !event.shiftKey) {
					event.preventDefault();
					onSubmit?.(event);
				}
			}}
			{...props}
		/>
	);
}
