/**
 * House style rules: REST calls and a shared copy of the current rules.
 *
 * The settings list and any "Remember this" prompt in a thread read the
 * same copy, so a rule saved in one shows up in the other.
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

export const HOUSE_STYLE_PATH = '/ai-feedback/v1/house-style';
export const SCOPE_USER = 'user';
export const SCOPE_SITE = 'site';

/**
 * @typedef {Object} HouseStyleState
 * @property {Array<{id: string, text: string}>} site            Rules for the whole site.
 * @property {Array<{id: string, text: string}>} user            The current user's own rules.
 * @property {boolean}                           can_manage_site Whether the user may change site rules.
 * @property {number}                            max_rules       Most rules in each list.
 * @property {number}                            max_length      Most characters in a rule.
 */

let request = null;
const listeners = new Set();

/**
 * Load the rules, once per page unless forced.
 *
 * @param {Object}  [options]
 * @param {boolean} [options.force] Fetch again even when loaded.
 * @return {Promise<HouseStyleState>} Rules.
 */
export function loadHouseStyle({ force = false } = {}) {
	if (!request || force) {
		request = apiFetch({ path: HOUSE_STYLE_PATH }).catch((error) => {
			request = null;
			throw error;
		});
	}
	return request;
}

/**
 * Listen for changes to the rules.
 *
 * @param {(state: HouseStyleState) => void} listener Called with the new rules.
 * @return {() => boolean} Unsubscribe.
 */
export function subscribeHouseStyle(listener) {
	listeners.add(listener);
	return () => listeners.delete(listener);
}

/**
 * Share a new copy of the rules with every listener.
 *
 * @param {HouseStyleState} state Rules from the server.
 * @return {HouseStyleState} The same rules.
 */
function publish(state) {
	request = Promise.resolve(state);
	listeners.forEach((listener) => listener(state));
	return state;
}

/**
 * Add a rule.
 *
 * @param {Object} rule
 * @param {string} rule.text     Rule text.
 * @param {string} [rule.scope]  SCOPE_USER or SCOPE_SITE.
 * @param {number} [rule.noteId] AI reply that proposed the rule.
 * @return {Promise<HouseStyleState>} Rules after the change.
 */
export function addRule({ text, scope = SCOPE_USER, noteId }) {
	const data = { text, scope };
	if (noteId) {
		data.note_id = noteId;
	}
	return apiFetch({ path: HOUSE_STYLE_PATH, method: 'POST', data }).then(
		publish
	);
}

/**
 * Change a rule's text.
 *
 * @param {string} id    Rule ID.
 * @param {string} text  New text.
 * @param {string} scope SCOPE_USER or SCOPE_SITE.
 * @return {Promise<HouseStyleState>} Rules after the change.
 */
export function updateRule(id, text, scope) {
	return apiFetch({
		path: `${HOUSE_STYLE_PATH}/${encodeURIComponent(id)}`,
		method: 'PUT',
		data: { text, scope },
	}).then(publish);
}

/**
 * Remove a rule.
 *
 * @param {string} id    Rule ID.
 * @param {string} scope SCOPE_USER or SCOPE_SITE.
 * @return {Promise<HouseStyleState>} Rules after the change.
 */
export function deleteRule(id, scope) {
	return apiFetch({
		path: addQueryArgs(`${HOUSE_STYLE_PATH}/${encodeURIComponent(id)}`, {
			scope,
		}),
		method: 'DELETE',
	}).then(publish);
}

/**
 * Record that the author doesn't want to keep a proposed rule.
 *
 * @param {number} noteId AI reply that proposed the rule.
 * @return {Promise<Object>} Response.
 */
export function dismissProposal(noteId) {
	return apiFetch({
		path: `${HOUSE_STYLE_PATH}/proposals/${Number(noteId)}/dismiss`,
		method: 'POST',
	});
}

/**
 * Key for comparing rules: case, spacing and a closing full stop ignored,
 * like the server's duplicate check.
 *
 * @param {string} text Rule text.
 * @return {string} Comparison key.
 */
export function ruleKey(text) {
	return String(text || '')
		.trim()
		.replace(/\s+/g, ' ')
		.toLowerCase()
		.replace(/[\s.!]+$/, '');
}

/**
 * Whether the site or the user already has a rule with this text.
 *
 * @param {HouseStyleState|null} state Rules.
 * @param {string}               text  Rule text.
 * @return {boolean} Whether a matching rule exists.
 */
export function hasRule(state, text) {
	const key = ruleKey(text);
	return [...(state?.site || []), ...(state?.user || [])].some(
		(rule) => ruleKey(rule.text) === key
	);
}

/**
 * Forget the loaded rules and listeners. For tests.
 */
export function resetHouseStyle() {
	request = null;
	listeners.clear();
}
