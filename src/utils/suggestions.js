/**
 * Add AI quick fixes as Gutenberg suggestions (the Suggestion Mode experiment).
 *
 * A suggestion is the AI note plus `<mark>` markers in the block content that
 * carry the note's id: an `add` run with the new text followed by a `del` run
 * over the old text. Authors accept or reject it from the Notes sidebar.
 *
 * The note's `_wp_suggestion` payload and `_wp_suggestion_status` are written
 * through core-data, the same way Gutenberg's own suggester writes them.
 */
import apiFetch from '@wordpress/api-fetch';
import {
	dispatch as dataDispatch,
	select as dataSelect,
} from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as blocksStore } from '@wordpress/blocks';
import { store as noticesStore } from '@wordpress/notices';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	concat,
	create,
	RichTextData,
	slice,
	toHTMLString,
} from '@wordpress/rich-text';
import { findUniqueOffset, getRichTextAttributeKey, toHtml } from './quick-fix';
import { getRewriteRuns } from './reply-edit';
import { resolveBlockClientId } from './navigate-to-block';

const STORE_NAME = 'ai-feedback/store';

// Named by string so the store's tests don't have to load @wordpress/editor.
const EDITOR_STORE = 'core/editor';
const CORE_STORE = 'core';

/**
 * Payload schema version Gutenberg's suggestions use.
 */
const SCHEMA_VERSION = 2;

/**
 * Checks whether quick fixes should become suggestions.
 *
 * @return {boolean} True when the Gutenberg experiment and server support are on.
 */
export function isSuggestionModeAvailable() {
	return (
		typeof window !== 'undefined' &&
		!!window.__experimentalSuggestionMode &&
		!!window.aiFeedbackData?.suggestions
	);
}

/**
 * Checks whether content already holds suggestion markers.
 *
 * @param {string|RichTextData} content Attribute value.
 * @return {boolean} True if any suggestion marker is present.
 */
export function hasSuggestionMarkers(content) {
	// Matches the class on a `<mark>` tag, not the same words in the text.
	return /<mark\b[^>]*\bclass="[^"]*\bwp-suggestion-(?:add|del|format)\b/.test(
		toHtml(content)
	);
}

/**
 * Checks whether HTML holds a marker for a suggestion id.
 *
 * @param {string} html HTML to search.
 * @param {number} id   Suggestion (note) id.
 * @return {boolean} True if a marker carries the id.
 */
export function hasSuggestionMarker(html, id) {
	return (html || '').includes(`data-suggestion-id="${id}"`);
}

/**
 * Builds a marker's opening tag.
 *
 * @param {string}      type     Marker kind, `add` or `del`.
 * @param {number}      id       Suggestion (note) id.
 * @param {number|null} authorId Suggester's user id, omitted when null.
 * @return {string} Opening `<mark>` tag.
 */
function openMarker(type, id, authorId) {
	const author =
		authorId === null || authorId === undefined
			? ''
			: ` data-author="${Number(authorId)}"`;
	return `<mark class="wp-suggestion-${type}" data-suggestion-id="${Number(
		id
	)}" data-suggestion-type="${type}"${author}>`;
}

/**
 * Wraps a quick fix's text in suggestion markers.
 *
 * Markers are built as HTML and parsed back, so the result is the same
 * whether or not Gutenberg has registered its marker formats yet. The new
 * text takes on the formatting of the first replaced character.
 *
 * @param {string|RichTextData} content          Current attribute value.
 * @param {Object}              fix              Quick fix with `find` and `replace`.
 * @param {Object}              options          Marker options.
 * @param {number}              options.id       Suggestion (note) id.
 * @param {number|null}         options.authorId Suggester's user id.
 * @return {string|RichTextData|null} New value, or null if the fix no longer applies or the block already holds suggestions.
 */
export function buildSuggestionContent(content, fix, { id, authorId = null }) {
	if (!fix?.find || hasSuggestionMarkers(content)) {
		return null;
	}

	const value = create({ html: toHtml(content) });
	const start = findUniqueOffset(value, fix);
	if (start === -1) {
		return null;
	}
	const end = start + fix.find.length;

	let markup = '';
	if (fix.replace) {
		const length = fix.replace.length;
		const added = toHTMLString({
			value: {
				text: fix.replace,
				formats: Array.from({ length }, () => value.formats[start]),
				replacements: new Array(length),
			},
		});
		markup += `${openMarker('add', id, authorId)}${added}</mark>`;
	}
	const removed = toHTMLString({ value: slice(value, start, end) });
	markup += `${openMarker('del', id, authorId)}${removed}</mark>`;

	const updated = concat(
		slice(value, 0, start),
		create({ html: markup }),
		slice(value, end, value.text.length)
	);

	return content instanceof RichTextData
		? new RichTextData(updated)
		: toHTMLString({ value: updated });
}

/**
 * Wraps a `rewrite_block` edit's word diff in suggestion markers.
 *
 * Each changed run becomes an `add` run with the new text followed by a
 * `del` run over the old text, all under the one suggestion id, so the
 * author accepts or rejects the rewrite as a whole.
 *
 * @param {string|RichTextData} content          Current attribute value.
 * @param {Object}              edit             `rewrite_block` edit.
 * @param {Object}              options          Marker options.
 * @param {number}              options.id       Suggestion (note) id.
 * @param {number|null}         options.authorId Suggester's user id.
 * @return {string|RichTextData|null} New value, or null if the edit no longer applies or the block already holds suggestions.
 */
export function buildRewriteSuggestionContent(
	content,
	edit,
	{ id, authorId = null }
) {
	if (hasSuggestionMarkers(content)) {
		return null;
	}

	const runs = getRewriteRuns(content, edit);
	if (!runs) {
		return null;
	}

	const markup = runs
		.map((run) => {
			if (run.type === 'equal') {
				return run.html;
			}
			const added = run.html
				? `${openMarker('add', id, authorId)}${run.html}</mark>`
				: '';
			const removed = run.removedHtml
				? `${openMarker('del', id, authorId)}${run.removedHtml}</mark>`
				: '';
			return added + removed;
		})
		.join('');
	const updated = create({ html: markup });

	return content instanceof RichTextData
		? new RichTextData(updated)
		: toHTMLString({ value: updated });
}

/**
 * Wraps a quick fix or reply edit in suggestion markers.
 *
 * @param {string|RichTextData} content Current attribute value.
 * @param {Object}              edit    Quick fix, `replace_text` or `rewrite_block` edit.
 * @param {Object}              options Marker options, see buildSuggestionContent().
 * @return {string|RichTextData|null} New value, or null if the edit can't be suggested.
 */
export function buildEditSuggestionContent(content, edit, options) {
	return edit?.type === 'rewrite_block'
		? buildRewriteSuggestionContent(content, edit, options)
		: buildSuggestionContent(content, edit, options);
}

/**
 * Reads a block's rich text attribute.
 *
 * @param {string} clientId Block client id.
 * @return {{block: Object, key: string, content: (string|RichTextData)}|null} Block, attribute key and value, or null.
 */
function getBlockContent(clientId) {
	const block = dataSelect(blockEditorStore).getBlock(clientId);
	if (!block) {
		return null;
	}
	const key = getRichTextAttributeKey(
		dataSelect(blocksStore).getBlockType(block.name)
	);
	return key ? { block, key, content: block.attributes[key] } : null;
}

/**
 * Builds the `_wp_suggestion` payload for a quick fix.
 *
 * An empty replacement is a deletion; anything else is a replacement, which
 * Gutenberg stores as an `add` run followed by a `del` run under one id. A
 * rewrite is a replacement made of several such pairs.
 *
 * @param {string} blockName Block name, eg. `core/paragraph`.
 * @param {string} attribute Rich text attribute holding the markers.
 * @param {Object} fix       Quick fix or reply edit.
 * @return {Object} Suggestion payload.
 */
export function buildSuggestionPayload(blockName, attribute, fix) {
	return {
		schemaVersion: SCHEMA_VERSION,
		blockName,
		baseRevision: null,
		operations: [
			{
				type: 'inline-suggestion',
				attribute,
				suggestionType:
					fix.type === 'rewrite_block' || fix.replace
						? 'replace'
						: 'del',
			},
		],
	};
}

/**
 * Saves a note's meta through core-data.
 *
 * @param {number} noteId Note ID.
 * @param {Object} meta   Meta values; null deletes a key.
 * @return {Promise<Object>} The saved comment record.
 */
function saveNoteMeta(noteId, meta) {
	return dataDispatch(CORE_STORE).saveEntityRecord(
		'root',
		'comment',
		{ id: noteId, meta },
		{ throwOnError: true }
	);
}

/**
 * Turns an edit into a suggestion on the note that proposed it.
 *
 * The payload is stored first; if the server dropped it, or the block
 * changed while the request ran, it is removed again. A reload between the
 * two steps leaves a pending suggestion without markers, which
 * `removeOrphanedSuggestions()` later turns back into a plain note.
 *
 * @param {Object} options         Suggestion options.
 * @param {number} options.noteId  Note that owns the suggestion.
 * @param {string} options.blockId Client id of the block to edit.
 * @param {Object} options.edit    Quick fix or reply edit.
 * @return {Promise<boolean>} Whether the suggestion was added.
 */
export async function suggestEdit({ noteId, blockId, edit }) {
	const target = getBlockContent(blockId);
	if (
		!target ||
		!buildEditSuggestionContent(target.content, edit, { id: noteId })
	) {
		return false;
	}

	const saved = await saveNoteMeta(noteId, {
		_wp_suggestion: JSON.stringify(
			buildSuggestionPayload(target.block.name, target.key, edit)
		),
		_wp_suggestion_status: 'pending',
	});

	const current = getBlockContent(blockId);
	// Gutenberg's sanitize callback stores '' for a payload it rejects.
	// Markers carry the note's author (0 for AI notes): Gutenberg tints and
	// names markers by matching it, and lets a user grow or remove markers
	// carrying their own id, so the reviewer's id would let their edits
	// rewrite the AI's suggestion.
	const updated =
		!!saved?.meta?._wp_suggestion &&
		current &&
		buildEditSuggestionContent(current.content, edit, {
			id: noteId,
			authorId: saved.author ?? null,
		});

	if (!updated) {
		await removeSuggestion(noteId);
		return false;
	}

	dataDispatch(blockEditorStore).updateBlockAttributes(blockId, {
		[current.key]: updated,
	});
	dataDispatch(STORE_NAME).setSuggestionStatus(noteId, 'pending');
	return true;
}

/**
 * Turns one note's quick fix into a suggestion.
 *
 * @param {Object} note Feedback note with `id`, `block_id` and `quick_fix`.
 * @return {Promise<boolean>} Whether the suggestion was added.
 */
export function suggestQuickFix(note) {
	return suggestEdit({
		noteId: note.id,
		blockId: note.block_id,
		edit: note.quick_fix,
	});
}

/**
 * Turns the edit an AI reply proposed into a suggestion on the reply.
 *
 * The block is the one the thread's note is attached to, found by the note
 * id in its metadata when the stored client id is stale.
 *
 * @param {Object}      reply     AI reply with `id` and `edit`.
 * @param {number}      threadId  Root note of the reply's thread.
 * @param {string|null} [blockId] Client id stored with the root note, if known.
 * @return {Promise<boolean>} Whether the suggestion was added.
 */
export async function suggestReplyEdit(reply, threadId, blockId = null) {
	if (!isSuggestionModeAvailable() || !reply?.id || !reply.edit) {
		return false;
	}
	const clientId = resolveBlockClientId(blockId, threadId);
	if (!clientId) {
		return false;
	}

	try {
		if (
			!(await suggestEdit({
				noteId: reply.id,
				blockId: clientId,
				edit: reply.edit,
			}))
		) {
			return false;
		}
	} catch {
		// The edit stays available to preview and apply in the sidebar.
		return false;
	}

	dataDispatch(noticesStore).createInfoNotice(
		__(
			'The AI reply’s edit was added as a suggestion. Save the post to keep it.',
			'fresh-eyes'
		),
		{ type: 'snackbar', id: `ai-feedback-reply-suggestion-${reply.id}` }
	);
	return true;
}

/**
 * Turns a pending suggestion back into a plain note.
 *
 * @param {number} noteId Note ID.
 * @return {Promise<void>}
 */
async function removeSuggestion(noteId) {
	try {
		await saveNoteMeta(noteId, {
			_wp_suggestion: null,
			_wp_suggestion_status: null,
		});
		dataDispatch(STORE_NAME).setSuggestionStatus(noteId, null);
	} catch (error) {
		// The note stays a suggestion until the orphan cleanup removes it.
		// eslint-disable-next-line no-console
		console.warn(
			'[AI-Feedback] Could not remove suggestion:',
			noteId,
			error
		);
	}
}

/**
 * Checks whether a note's quick fix can become a suggestion.
 *
 * @param {Object} note Feedback note.
 * @return {boolean} True for top-level, single-block notes with a quick fix.
 */
export function canSuggest(note) {
	return (
		!!note?.id &&
		!!note.quick_fix &&
		!!note.block_id &&
		!note.is_group &&
		!note.parent &&
		!note.is_resolved &&
		!note.suggestion_status
	);
}

/**
 * Turns every eligible quick fix from a review into a suggestion.
 *
 * @param {Object[]} notes Notes from a review response.
 * @return {Promise<number>} Number of suggestions added.
 */
export async function suggestQuickFixes(notes) {
	if (!isSuggestionModeAvailable()) {
		return 0;
	}

	let added = 0;
	// One at a time, so each write sees the block as the last one left it.
	for (const note of (notes || []).filter(canSuggest)) {
		try {
			if (await suggestQuickFix(note)) {
				added++;
			}
		} catch {
			// The quick fix stays available in the sidebar.
		}
	}

	if (added > 0) {
		dataDispatch(noticesStore).createInfoNotice(
			sprintf(
				/* translators: %d: number of suggested edits */
				_n(
					'%d AI edit was added as a suggestion. Save the post to keep it.',
					'%d AI edits were added as suggestions. Save the post to keep them.',
					added,
					'fresh-eyes'
				),
				added
			),
			{ type: 'snackbar', id: 'ai-feedback-suggestions-added' }
		);
	}

	return added;
}

/**
 * Turns pending suggestions whose markers are gone back into plain notes.
 *
 * Markers live in the post content, so they are lost if the editor reloads
 * before the post is saved. The server lists only pending AI suggestions
 * that neither the saved post nor its autosave anchors and that are older
 * than its cleanup delay, so markers held by another editor's unsaved
 * content are left alone. Any the edited or saved content still marks are
 * kept too.
 *
 * @param {number} postId Post ID.
 * @return {Promise<number>} Number of suggestions removed.
 */
export async function removeOrphanedSuggestions(postId) {
	if (!postId || !isSuggestionModeAvailable()) {
		return 0;
	}

	const response = await apiFetch({
		path: `/ai-feedback/v1/notes/post/${postId}/saved-pending-suggestions`,
	});
	const ids = response?.note_ids || [];
	if (ids.length === 0) {
		return 0;
	}

	const editor = dataSelect(EDITOR_STORE);
	const html = editor.getEditedPostContent() || '';
	// Content that hasn't loaded yet would make every suggestion look
	// orphaned. Once the editor is ready, an empty post really has no markers.
	const isReady =
		typeof editor.__unstableIsEditorReady === 'function'
			? editor.__unstableIsEditorReady()
			: !!html.trim();
	if (!isReady) {
		return 0;
	}
	// A marker removed without saving can still come back with undo, so keep
	// any suggestion the saved content still marks.
	const savedHtml = editor.getCurrentPostAttribute('content');
	const orphaned = ids.filter(
		(id) =>
			!hasSuggestionMarker(html, id) &&
			!hasSuggestionMarker(
				typeof savedHtml === 'string' ? savedHtml : '',
				id
			)
	);

	await Promise.all(orphaned.map((id) => removeSuggestion(id)));

	return orphaned.length;
}
