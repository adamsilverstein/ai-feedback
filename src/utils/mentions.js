/**
 * Mentions of Fresh Eyes in notes.
 *
 * Mirrors AI_Feedback\Mentions on the server, which decides whether a note
 * is answered. The editor only uses this to know when to look for an answer.
 */
import { __ } from '@wordpress/i18n';

import { getFreshEyesUserId } from './user-completer';

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
	// The server's list, which a filter can change; the defaults otherwise.
	const localized = window.aiFeedbackData?.mentionHandles;
	const handles =
		Array.isArray(localized) && localized.length
			? localized.filter((handle) => typeof handle === 'string')
			: ['fresh eyes', __('Fresh Eyes', 'fresh-eyes').toLowerCase()];
	const names = handles.map((handle) =>
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
	const html = stripQuoted(
		String(content || '').slice(0, MAX_SCAN_LENGTH * 2)
	);
	const text = html
		.replace(/<[^>]*>/g, ' ')
		.replace(/&#64;|&commat;/gi, '@')
		.replace(/&nbsp;/gi, ' ')
		.replace(/&amp;/gi, '&');
	return text.replace(/\s+/gu, ' ').trim().slice(0, MAX_SCAN_LENGTH);
}

/**
 * Remove quoted elements from note HTML.
 *
 * @param {string} html Note HTML, already bounded.
 * @return {string} HTML without quoted elements.
 */
function stripQuoted(html) {
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
	return html.replace(
		new RegExp(`<(?:${QUOTED_TAGS})\\b[\\s\\S]*$`, 'i'),
		' '
	);
}

/**
 * Whether note content holds a Notes `@` completer chip for the Fresh Eyes
 * user, `<span class="wp-note-mention user-N">`, outside quoted text.
 *
 * @param {string} content Note HTML.
 * @return {boolean} True when the note picked Fresh Eyes from the completer.
 */
export function hasUserChip(content) {
	const userId = getFreshEyesUserId();
	const html = String(content || '');
	if (!userId || !html.includes('wp-note-mention')) {
		return false;
	}

	const tags = stripQuoted(html.slice(0, MAX_SCAN_LENGTH * 2)).matchAll(
		/<span\b((?:[^>"']|"[^"]*"|'[^']*')*)>/gi
	);
	for (const [, attributes] of tags) {
		const match = attributes.match(
			/(?:^|[\s/"'])class\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+))/i
		);
		const tokens = (match?.[1] ?? match?.[2] ?? match?.[3] ?? '')
			.trim()
			.split(/\s+/);
		if (
			tokens.includes('wp-note-mention') &&
			tokens.includes(`user-${userId}`)
		) {
			return true;
		}
	}
	return false;
}

/**
 * Whether note content mentions Fresh Eyes.
 *
 * @param {string} content Note HTML.
 * @return {boolean} True when the note asks Fresh Eyes.
 */
export function mentionsFreshEyes(content) {
	if (hasUserChip(content)) {
		return true;
	}
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
