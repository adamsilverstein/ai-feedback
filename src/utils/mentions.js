/**
 * Mentions of Fresh Eyes in notes.
 *
 * Mirrors AI_Feedback\Mentions on the server, which decides whether a note
 * is answered. The editor only uses this to know when to look for an answer.
 */
import { __ } from '@wordpress/i18n';

/**
 * Handle typed after `@` in a note to ask Fresh Eyes.
 */
export const MENTION_HANDLE = '@fresh-eyes';

/**
 * Most characters of a note scanned for a mention, as on the server.
 */
const MAX_SCAN_LENGTH = 5000;

const QUOTED_TAGS = 'blockquote|q|code|pre';

/**
 * Build the pattern that finds a mention.
 *
 * @return {RegExp} Pattern.
 */
function getMentionPattern() {
	const names = [
		'fresh eyes',
		__('Fresh Eyes', 'fresh-eyes').toLowerCase(),
	].map((handle) =>
		handle
			.trim()
			.split(/[\s_-]+/u)
			.map((word) => word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
			.join('[\\s_-]?')
	);
	return new RegExp(
		`(?<![\\p{L}\\p{N}_.@/-])@(?:${[...new Set(names)].join('|')})(?![\\p{L}\\p{N}_-])(?!\\.[\\p{L}\\p{N}])`,
		'iu'
	);
}

/**
 * Get a note's own text, without tags or quoted elements.
 *
 * @param {string} content Note HTML.
 * @return {string} Text.
 */
export function getNoteText(content) {
	let html = String(content || '').slice(0, MAX_SCAN_LENGTH * 2);
	const quoted = new RegExp(
		`<(${QUOTED_TAGS})\\b[^>]*>(?:(?!<(?:${QUOTED_TAGS})\\b)[\\s\\S])*?</\\1\\s*>`,
		'gi'
	);
	for (let pass = 0; pass < 5; pass++) {
		const stripped = html.replace(quoted, ' ');
		if (stripped === html) {
			break;
		}
		html = stripped;
	}
	html = html.replace(
		new RegExp(`<(?:${QUOTED_TAGS})\\b[\\s\\S]*$`, 'i'),
		' '
	);

	const text = html
		.replace(/<[^>]*>/g, ' ')
		.replace(/&#64;|&commat;/gi, '@')
		.replace(/&nbsp;/gi, ' ')
		.replace(/&amp;/gi, '&');
	return text.replace(/\s+/gu, ' ').trim().slice(0, MAX_SCAN_LENGTH);
}

/**
 * Whether note content mentions Fresh Eyes.
 *
 * @param {string} content Note HTML.
 * @return {boolean} True when the note asks Fresh Eyes.
 */
export function mentionsFreshEyes(content) {
	const text = getNoteText(content);
	return text.includes('@') && getMentionPattern().test(text);
}

/**
 * Get the HTML of a note record from the REST API.
 *
 * @param {Object} note Note record.
 * @return {string} Note HTML.
 */
export function getNoteContent(note) {
	const content = note?.content;
	if (typeof content === 'string') {
		return content;
	}
	return content?.raw ?? content?.rendered ?? '';
}

/**
 * Whether a note the editor loaded may be waiting on Fresh Eyes: any reply,
 * or a new thread that mentions it.
 *
 * @param {Object} note Note record.
 * @return {boolean} True when the reply threads should be checked.
 */
export function mayAwaitAnswer(note) {
	return note?.parent > 0 || mentionsFreshEyes(getNoteContent(note));
}
