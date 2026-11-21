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
	resolveSelect,
	select as dataSelect,
} from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as blocksStore } from '@wordpress/blocks';
import { store as noticesStore } from '@wordpress/notices';
import { __, _n, sprintf } from '@wordpress/i18n';
import { concat, create, RichTextData, slice } from '@wordpress/rich-text';
import { findUniqueOffset, getRichTextAttributeKey, toHtml } from './quick-fix';
import {
	fromSourceHTML,
	markReplacement,
	markSource,
	readSource,
	readValue,
	toContent,
	toSourceHTML,
} from './source-newlines';
import { getRewriteRuns } from './reply-edit';
import { resolveBlockClientId } from './navigate-to-block';
import { isBlockAction } from './is-reply-edit';
import {
	findNoteBlock,
	getBlockActionState,
	getMovePosition,
} from './block-action';

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
	const content = html || '';
	return (
		content.includes(`data-suggestion-id="${id}"`) ||
		// A structural suggestion's marker sits in the block's attributes.
		new RegExp(`"commentId":${Number(id)}(?![0-9])`).test(content)
	);
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

	const { value, marker } = readSource(toHtml(content), [fix.replace]);
	const start = findUniqueOffset(readValue(value, marker), fix);
	if (start === -1) {
		return null;
	}
	const end = start + fix.find.length;

	let markup = '';
	if (fix.replace) {
		const length = fix.replace.length;
		const added = toSourceHTML(
			{
				text: markReplacement(
					value.text.slice(start, end),
					fix.replace,
					marker
				),
				formats: Array.from({ length }, () => value.formats[start]),
				replacements: new Array(length),
			},
			marker
		);
		markup += `${openMarker('add', id, authorId)}${added}</mark>`;
	}
	const removed = toSourceHTML(slice(value, start, end), marker);
	markup += `${openMarker('del', id, authorId)}${removed}</mark>`;

	const updated = concat(
		slice(value, 0, start),
		(marker && markSource(markup, marker)) || create({ html: markup }),
		slice(value, end, value.text.length)
	);

	return toContent(content, updated, marker);
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

	const { value, marker } = readSource(markup);
	return toContent(content, value, marker);
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
 * Payload key naming the AI reply whose edit a thread's root note holds.
 *
 * Gutenberg only links a thread's root note to its block, so a reply's edit
 * is suggested on the root note, and this key says which reply it is.
 * Gutenberg keeps keys it does not know.
 */
export const EDIT_OWNER_KEY = 'aiFeedbackEditOwner';

/**
 * Builds the `_wp_suggestion` payload for a quick fix.
 *
 * An empty replacement is a deletion; anything else is a replacement, which
 * Gutenberg stores as an `add` run followed by a `del` run under one id. A
 * rewrite is a replacement made of several such pairs.
 *
 * @param {string}      blockName     Block name, eg. `core/paragraph`.
 * @param {string}      attribute     Rich text attribute holding the markers.
 * @param {Object}      fix           Quick fix or reply edit.
 * @param {number|null} [editOwnerId] AI reply whose edit this is, when the root note holds it.
 * @return {Object} Suggestion payload.
 */
export function buildSuggestionPayload(
	blockName,
	attribute,
	fix,
	editOwnerId = null
) {
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
		...(editOwnerId ? { [EDIT_OWNER_KEY]: Number(editOwnerId) } : {}),
	};
}

/**
 * Finds the note whose edit a note's suggestion is.
 *
 * @param {Object} record Comment record from core-data.
 * @return {number|null} The reply named in the payload, the note itself when none is named, or null when it is not a suggestion.
 */
export function getSuggestionOwnerId(record) {
	const raw = record?.meta?._wp_suggestion;
	if (!raw) {
		return null;
	}
	let payload;
	try {
		payload = JSON.parse(raw);
	} catch {
		payload = null;
	}
	const owner = payload?.[EDIT_OWNER_KEY];
	return Number.isInteger(owner) && owner > 0 ? owner : record.id;
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
 * A block's rich text without one note's suggestion markers.
 *
 * @param {string|RichTextData} content Attribute value.
 * @param {number}              id      Suggestion (note) id.
 * @return {string|RichTextData} The content as it reads without that suggestion.
 */
function withoutOwnMarkers(content, id) {
	return removeSuggestionMarkers(content, id) ?? content;
}

/**
 * Turns an edit into a suggestion on a note.
 *
 * The payload is stored first; if the server dropped it, or the block
 * changed while the request ran, it is removed again. A reload between the
 * two steps leaves a pending suggestion without markers, which
 * `removeOrphanedSuggestions()` later turns back into a plain note.
 *
 * With `replace`, the note's pending suggestion gives way to this one in a
 * single block update: its markers come out as the new ones go in. The new
 * payload overwrites the old one before that, so if the server dropped it or
 * the block changed meanwhile, the earlier suggestion is lost as well: its
 * markers are taken out and the note goes back to a plain note, rather than
 * leave markers the stored payload no longer describes. Both edits stay
 * available with Preview and Apply.
 *
 * @param {Object}      options               Suggestion options.
 * @param {number}      options.noteId        Note that holds the suggestion.
 * @param {string}      options.blockId       Client id of the block to edit.
 * @param {Object}      options.edit          Quick fix or reply edit.
 * @param {number|null} [options.editOwnerId] AI reply whose edit this is, when another note holds it.
 * @param {boolean}     [options.replace]     Whether to replace the note's pending suggestion.
 * @return {Promise<boolean>} Whether the suggestion was added.
 */
export async function suggestEdit({
	noteId,
	blockId,
	edit,
	editOwnerId = null,
	replace = false,
}) {
	const base = (content) =>
		replace ? withoutOwnMarkers(content, noteId) : content;

	const target = getBlockContent(blockId);
	if (
		!target ||
		!buildEditSuggestionContent(base(target.content), edit, {
			id: noteId,
		})
	) {
		return false;
	}

	const saved = await saveNoteMeta(noteId, {
		_wp_suggestion: JSON.stringify(
			buildSuggestionPayload(
				target.block.name,
				target.key,
				edit,
				editOwnerId
			)
		),
		_wp_suggestion_status: 'pending',
	});

	const current = getBlockContent(blockId);
	// Gutenberg's sanitize callback stores '' for a payload it rejects.
	// Markers carry the note's author (the Fresh Eyes user, or 0): Gutenberg
	// tints and names markers by matching it, and lets a user grow or remove
	// markers carrying their own id, so the reviewer's id would let their
	// edits rewrite the AI's suggestion.
	const updated =
		!!saved?.meta?._wp_suggestion &&
		current &&
		buildEditSuggestionContent(base(current.content), edit, {
			id: noteId,
			authorId: saved.author ?? null,
		});

	if (!updated) {
		// The stored payload no longer matches the markers it replaced, so
		// those go too.
		if (replace && current) {
			const cleared = removeSuggestionMarkers(current.content, noteId);
			if (cleared !== null) {
				dataDispatch(blockEditorStore).updateBlockAttributes(blockId, {
					[current.key]: cleared,
				});
			}
		}
		await removeSuggestion(noteId);
		return false;
	}

	dataDispatch(blockEditorStore).updateBlockAttributes(blockId, {
		[current.key]: updated,
	});
	// The note's own fix is not the suggestion when it holds a reply's edit.
	dataDispatch(STORE_NAME).setSuggestionStatus(
		noteId,
		editOwnerId ? null : 'pending'
	);
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
 * The query the Notes UI loads a post's notes with, as in getNotesQuery(),
 * so records already fetched are read rather than each note fetched.
 *
 * @return {Array|null} Arguments for `getEntityRecords`, or null without a post.
 */
function getNotesQueryArgs() {
	const postId = dataSelect(EDITOR_STORE).getCurrentPostId?.();
	return postId
		? [
				'root',
				'comment',
				{ post: postId, type: 'note', status: 'all', per_page: -1 },
			]
		: null;
}

/**
 * Reads a loaded note record.
 *
 * @param {number} noteId Note ID.
 * @return {Object|null} Comment record, or null when not loaded.
 */
function getLoadedNote(noteId) {
	const args = getNotesQueryArgs();
	const notes = args
		? dataSelect(CORE_STORE).getEntityRecords?.(...args)
		: null;
	return notes?.find((note) => note.id === Number(noteId)) ?? null;
}

/**
 * Reads a note record once the post's notes have loaded.
 *
 * A new AI reply refetches the notes, so this waits for that rather than
 * read a record the refetch is about to replace.
 *
 * @param {number} noteId Note ID.
 * @return {Promise<Object|null>} Comment record, or null when the post has no such note.
 */
async function loadNote(noteId) {
	const args = getNotesQueryArgs();
	if (args) {
		try {
			await resolveSelect(CORE_STORE).getEntityRecords(...args);
		} catch {
			// The records already loaded, if any, will do.
		}
	}
	return getLoadedNote(noteId);
}

/**
 * Turns the edit an AI reply proposed into a suggestion on its thread's
 * root note.
 *
 * Gutenberg's Notes UI links only a thread's root note to its block, so a
 * suggestion on the reply itself could not be accepted, rejected or saved.
 * The root note holds the thread's one suggestion, and the payload names
 * the reply whose edit it is. A pending suggestion the root holds, its own
 * quick fix or an earlier reply's edit, gives way to the new one.
 *
 * The edit stays with Preview and Apply in the sidebar instead when the root
 * note is not an AI note (a mention thread a person started), or when the
 * author already accepted or rejected the root's suggestion.
 *
 * Whether the root is an AI note comes from the caller, which has it from
 * the server: only the note's `ai_feedback` meta says so, and that is not in
 * the note's REST record. Its author does not: an AI note is written as the
 * Fresh Eyes user, or as user 0 without one and in older notes.
 *
 * @param {Object}      reply               AI reply with `id` and `edit`.
 * @param {number}      threadId            Root note of the reply's thread.
 * @param {string|null} [blockId]           Client id stored with the root note, if known.
 * @param {Object}      [options]           Options.
 * @param {boolean}     [options.isMention] Whether a person started the thread, as its `is_mention` says.
 * @return {Promise<boolean>} Whether the suggestion was added.
 */
export async function suggestReplyEdit(
	reply,
	threadId,
	blockId = null,
	{ isMention = false } = {}
) {
	if (
		!isSuggestionModeAvailable() ||
		!reply?.id ||
		!reply.edit ||
		isMention
	) {
		return false;
	}
	const root = await loadNote(threadId);
	const clientId = resolveBlockClientId(blockId, threadId);
	if (!clientId || !root) {
		return false;
	}

	const status = getRecordStatus(root);
	if (status !== null && status !== 'pending') {
		return false;
	}
	if (status === 'pending' && getSuggestionOwnerId(root) === reply.id) {
		return true;
	}

	try {
		if (
			!(await suggestEdit({
				noteId: threadId,
				blockId: clientId,
				edit: reply.edit,
				editOwnerId: reply.id,
				replace: status === 'pending',
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
 * Checks whether structural edits should become suggestions: Suggestion
 * Mode is on, and the server found the Gutenberg code that handles them.
 *
 * @return {boolean} True when structural suggestions are supported.
 */
export function isStructuralSuggestionAvailable() {
	return (
		isSuggestionModeAvailable() &&
		!!window.aiFeedbackData?.structuralSuggestions
	);
}

/**
 * Whether the suggestion payload the server stored is the one sent.
 *
 * Gutenberg's sanitizer accepts any operation, so a change in how it
 * stores structural suggestions only shows when it is read back.
 *
 * @param {string} stored    `_wp_suggestion` as saved.
 * @param {Object} operation Operation that was sent.
 * @return {boolean} True when the stored payload holds that operation.
 */
export function isStoredStructuralPayload(stored, operation) {
	let payload;
	try {
		payload = JSON.parse(stored || '');
	} catch {
		return false;
	}
	const operations = Array.isArray(payload?.operations)
		? payload.operations
		: [];
	const [first] = operations;
	return (
		payload.schemaVersion === SCHEMA_VERSION &&
		operations.length === 1 &&
		first?.type === operation.type &&
		first?.clientId === operation.clientId &&
		first?.blockName === operation.blockName
	);
}

/**
 * Structural edits that can become Gutenberg suggestions.
 *
 * An insert would have to move the note onto the new block, so it stays
 * with Preview and Apply in the sidebar.
 */
const SUGGESTABLE_BLOCK_ACTIONS = ['remove_block', 'move_block'];

/**
 * Checks whether a note's structural edit can become a suggestion.
 *
 * Only a review note qualifies: Gutenberg's Notes UI finds a suggestion's
 * block through the thread's root note, which is linked to the block that
 * is removed or moved.
 *
 * @param {Object} note Feedback note.
 * @return {boolean} True for top-level, single-block notes that remove or move their block.
 */
export function canSuggestBlockAction(note) {
	return (
		!!note?.id &&
		!!note.block_id &&
		!note.is_group &&
		!note.parent &&
		!note.is_resolved &&
		!note.suggestion_status &&
		isBlockAction(note.block_action) &&
		SUGGESTABLE_BLOCK_ACTIONS.includes(note.block_action.type)
	);
}

/**
 * Builds the `_wp_suggestion` payload for a structural edit, in the shape
 * Gutenberg's own structural capture writes.
 *
 * @param {string} blockName Block name.
 * @param {Object} operation `block-remove` or `block-move` operation.
 * @return {Object} Suggestion payload.
 */
export function buildStructuralPayload(blockName, operation) {
	return {
		schemaVersion: SCHEMA_VERSION,
		blockName,
		baseRevision: null,
		operations: [operation],
	};
}

/**
 * Whether a structural edit can become a suggestion with the blocks as they
 * are: it is ready to apply, and a move stays within its parent block.
 *
 * Reject puts a moved block back by its old neighbor or index. Client ids
 * change on reload, so a move between parents could only be put back into
 * the wrong parent; those stay with Preview and Apply.
 *
 * @param {Object} selectors Block editor selectors.
 * @param {Object} action    Structural edit.
 * @param {string} clientId  Client id of the note's block.
 * @return {boolean} True when it can be suggested.
 */
function canSuggestNow(selectors, action, clientId) {
	const { state, targetId } = getBlockActionState(
		selectors,
		action,
		clientId
	);
	if (state !== 'ready') {
		return false;
	}
	if (action.type !== 'move_block') {
		return true;
	}
	const { fromRootClientId, toRootClientId } = getMovePosition(
		selectors,
		action,
		clientId,
		targetId
	);
	return fromRootClientId === toRootClientId;
}

/**
 * Where a block is before it moves, in the fields Gutenberg's `block-move`
 * operation and `pending-move` marker use.
 *
 * @param {Object} selectors Block editor selectors.
 * @param {string} clientId  Client id of the block.
 * @return {{fromParentClientId: (string|null), fromIndex: number, fromAnchorClientId: (string|null)}} Its origin.
 */
function getMoveOrigin(selectors, clientId) {
	const fromParentClientId = selectors.getBlockRootClientId(clientId);
	const fromIndex = selectors.getBlockIndex(clientId);
	const fromOrder = selectors.getBlockOrder(fromParentClientId ?? '');
	return {
		fromParentClientId: fromParentClientId || null,
		fromIndex,
		fromAnchorClientId: fromOrder[fromIndex - 1] ?? null,
	};
}

/**
 * Turns a review note's remove or move into a Gutenberg structural
 * suggestion. Moves between parent blocks stay with Preview and Apply.
 *
 * Like Gutenberg's own capture, the change is made and tagged: a removal
 * keeps the block and marks it `pending-remove`, and a move puts the block
 * in its new place, marked `pending-move` with where it came from. Accept
 * then removes the block or keeps it where it is; Reject clears the mark
 * or moves it back. The marker carries the note's id, so the suggestion is
 * found again after a reload.
 *
 * Meant for Gutenberg's Edit intent. Its Suggest intent can only be read
 * through a private selector, so it is not checked; there, Gutenberg's own
 * capture may record the move a second time.
 *
 * If the server did not store the payload as
 * sent, the note goes back to being a plain note and the edit stays with
 * Preview and Apply.
 *
 * @param {Object} note Feedback note with `id`, `block_id` and `block_action`.
 * @return {Promise<boolean>} Whether the suggestion was added.
 */
export async function suggestBlockAction(note) {
	if (!isStructuralSuggestionAvailable()) {
		return false;
	}
	const action = note.block_action;
	const selectors = dataSelect(blockEditorStore);
	const clientId = findNoteBlock(selectors, note.block_id, note.id);
	const block = clientId ? selectors.getBlock(clientId) : null;
	if (
		!block ||
		block.attributes?.metadata?.suggestion ||
		!canSuggestNow(selectors, action, clientId)
	) {
		return false;
	}

	const operation =
		action.type === 'remove_block'
			? { type: 'block-remove', clientId, blockName: block.name }
			: {
					type: 'block-move',
					clientId,
					blockName: block.name,
					// Gutenberg's Reject puts the block back from these.
					...getMoveOrigin(selectors, clientId),
				};
	const saved = await saveNoteMeta(note.id, {
		_wp_suggestion: JSON.stringify(
			buildStructuralPayload(block.name, operation)
		),
		_wp_suggestion_status: 'pending',
	});

	// The blocks may have changed while the request ran.
	const current = dataSelect(blockEditorStore);
	if (
		!isStoredStructuralPayload(saved?.meta?._wp_suggestion, operation) ||
		!canSuggestNow(current, action, clientId) ||
		current.getBlock(clientId)?.attributes?.metadata?.suggestion
	) {
		await removeSuggestion(note.id);
		return false;
	}

	const editor = dataDispatch(blockEditorStore);
	const marker = {
		type:
			action.type === 'remove_block' ? 'pending-remove' : 'pending-move',
		commentId: note.id,
		authorId: saved.author ?? null,
	};

	if (action.type === 'move_block') {
		const { targetId } = getBlockActionState(current, action, clientId);
		Object.assign(marker, getMoveOrigin(current, clientId));

		const { fromRootClientId, toRootClientId, index } = getMovePosition(
			current,
			action,
			clientId,
			targetId
		);
		editor.moveBlocksToPosition(
			[clientId],
			fromRootClientId,
			toRootClientId,
			index
		);
	}

	const metadata =
		dataSelect(blockEditorStore).getBlock(clientId)?.attributes?.metadata;
	editor.updateBlockAttributes(clientId, {
		metadata: { ...(metadata || {}), suggestion: marker },
	});
	dataDispatch(STORE_NAME).setSuggestionStatus(note.id, 'pending');
	return true;
}

/**
 * Finds the block holding a note's structural suggestion marker.
 *
 * @param {number} noteId Suggestion (note) id.
 * @return {string|null} Client id, or null when no block is marked for it.
 */
function findStructuralMarker(noteId) {
	const selectors = dataSelect(blockEditorStore);
	return (
		selectors
			.getClientIdsWithDescendants()
			.find(
				(id) =>
					Number(
						selectors.getBlock(id)?.attributes?.metadata?.suggestion
							?.commentId
					) === Number(noteId)
			) ?? null
	);
}

/**
 * Where a suggested move goes back to: right after the block that came
 * before it, while that block is still in the parent, or else its old index.
 *
 * @param {Object} selectors     Block editor selectors.
 * @param {string} clientId      Client id of the moved block.
 * @param {string} restoreParent Parent to restore it to, '' for the root.
 * @param {Object} marker        Its `pending-move` marker.
 * @return {number} Index once the block has left its current place.
 */
function getRestoreIndex(selectors, clientId, restoreParent, marker) {
	if (marker.fromAnchorClientId === null) {
		return 0;
	}
	const order = selectors
		.getBlockOrder(restoreParent)
		.filter((id) => id !== clientId);
	const anchor = order.indexOf(marker.fromAnchorClientId);
	return anchor === -1 ? (marker.fromIndex ?? 0) : anchor + 1;
}

/**
 * Takes a structural suggestion out of the post, as Reject does: a removal
 * keeps its block, and a move goes back to where it came from.
 *
 * @param {string} clientId Client id of the marked block.
 */
function rejectStructuralMarker(clientId) {
	const selectors = dataSelect(blockEditorStore);
	const editor = dataDispatch(blockEditorStore);
	const { suggestion, ...metadata } =
		selectors.getBlock(clientId)?.attributes?.metadata || {};

	editor.updateBlockAttributes(clientId, { metadata });
	if (suggestion?.type === 'pending-move') {
		const liveParent = selectors.getBlockRootClientId(clientId) ?? '';
		const recorded = suggestion.fromParentClientId ?? '';
		// A recorded parent only exists in the session that made the move.
		const restoreParent =
			recorded === '' || selectors.getBlock(recorded)
				? recorded
				: liveParent;
		editor.moveBlocksToPosition(
			[clientId],
			liveParent,
			restoreParent,
			getRestoreIndex(selectors, clientId, restoreParent, suggestion)
		);
	}
}

/**
 * Takes one suggestion's markers out of content, as Reject does: its added
 * runs are dropped and its deleted runs keep their text.
 *
 * @param {string|RichTextData} content Attribute value.
 * @param {number}              id      Suggestion (note) id.
 * @return {string|RichTextData|null} New value, or null when the content holds no marker for the id.
 */
export function removeSuggestionMarkers(content, id) {
	const html = toHtml(content);
	if (!hasSuggestionMarker(html, Number(id))) {
		return null;
	}

	// An inert document, so nothing in the content runs or loads.
	const { body } = document.implementation.createHTMLDocument('');
	body.innerHTML = html;
	body.querySelectorAll(`mark[data-suggestion-id="${Number(id)}"]`).forEach(
		(mark) => {
			if (mark.classList.contains('wp-suggestion-add')) {
				mark.remove();
			} else {
				mark.replaceWith(...mark.childNodes);
			}
		}
	);

	return fromSourceHTML(content, body.innerHTML);
}

/**
 * Applies one suggestion's markers in content, as Accept does: its deleted
 * runs are dropped and its added runs keep their text.
 *
 * @param {string|RichTextData} content Attribute value.
 * @param {number}              id      Suggestion (note) id.
 * @return {string|RichTextData|null} New value, or null when the content holds no marker for the id.
 */
export function acceptSuggestionMarkers(content, id) {
	const html = toHtml(content);
	if (!html.includes(`data-suggestion-id="${Number(id)}"`)) {
		return null;
	}

	// An inert document, so nothing in the content runs or loads.
	const { body } = document.implementation.createHTMLDocument('');
	body.innerHTML = html;
	body.querySelectorAll(`mark[data-suggestion-id="${Number(id)}"]`).forEach(
		(mark) => {
			if (mark.classList.contains('wp-suggestion-del')) {
				mark.remove();
			} else {
				mark.replaceWith(...mark.childNodes);
			}
		}
	);

	return fromSourceHTML(content, body.innerHTML);
}

/**
 * Records a status on suggestions, in their notes and in the store.
 *
 * Used for suggestions accepted in the text by Accept all, marked
 * `applied-unsaved` like Gutenberg's own Accept until the post is saved.
 *
 * @param {number[]} noteIds Suggestion (note) ids.
 * @param {string}   status  Status to record.
 * @return {Promise<number[]>} IDs whose status could not be saved.
 */
export async function saveSuggestionStatuses(noteIds, status) {
	const results = await Promise.allSettled(
		noteIds.map((noteId) =>
			saveNoteMeta(noteId, { _wp_suggestion_status: status })
		)
	);
	const failed = [];
	results.forEach((result, index) => {
		if (result.status === 'fulfilled') {
			dataDispatch(STORE_NAME).setSuggestionStatus(
				noteIds[index],
				status
			);
		} else {
			failed.push(noteIds[index]);
		}
	});
	return failed;
}

/**
 * Reads a note's suggestion status from its comment record.
 *
 * @param {Object|null} record Comment record from core-data.
 * @return {string|null} Status, or null when not a suggestion.
 */
function getRecordStatus(record) {
	if (!record?.meta?._wp_suggestion) {
		return null;
	}
	return record.meta._wp_suggestion_status || 'pending';
}

/**
 * Reads a note's suggestion status from the record core-data has loaded.
 *
 * @param {number} noteId Note ID.
 * @return {string|null} Status, or null when unknown or not a suggestion.
 */
function getLoadedSuggestionStatus(noteId) {
	return getRecordStatus(getLoadedNote(noteId));
}

/**
 * Notes whose superseding is in progress, so the sidebar and the reply
 * watcher never both do it.
 */
const supersedingIds = new Set();

/**
 * The notes holding the suggestions of superseded edits.
 *
 * A thread's root note may hold an AI reply's edit, named in its payload.
 * It is retired when that reply's edit is superseded, not when the root
 * note's own edit is. A reply holds its own edit only in suggestions made
 * before reply edits moved to the root note.
 *
 * @param {number}   threadId Root note of the thread.
 * @param {number[]} noteIds  Notes whose edits are superseded.
 * @return {number[]} Notes whose suggestions to retire.
 */
function getSupersededHolders(threadId, noteIds) {
	const rootId = Number(threadId);
	const rootOwner = getSuggestionOwnerId(getLoadedNote(rootId));
	const superseded = new Set(noteIds.map(Number));
	const holders = new Set();
	for (const id of superseded) {
		if (id === rootId) {
			// An unloaded root is taken to hold its own edit.
			if (rootOwner === null || rootOwner === rootId) {
				holders.add(rootId);
			}
		} else if (rootOwner === id) {
			holders.add(rootId);
		} else {
			holders.add(id);
		}
	}
	return [...holders];
}

/**
 * Retires the suggestions a newer edit in the same thread replaces.
 *
 * Each pending suggestion's markers are taken out of the block and it is
 * marked `rejected-unsaved`, which Gutenberg finalizes as `rejected` when
 * the post is saved: its status list has no `superseded`. Suggestions the
 * author already accepted or rejected are left alone.
 *
 * @param {number}   threadId           Root note of the thread, used to find its block.
 * @param {number[]} noteIds            Notes whose edits the newest edit supersedes.
 * @param {Object}   [options]          Options.
 * @param {boolean}  [options.keepRoot] Leave the root note's suggestion for the newest edit to replace.
 * @return {Promise<number[]>} IDs of the suggestions retired.
 */
export async function supersedeSuggestions(
	threadId,
	noteIds,
	{ keepRoot = false } = {}
) {
	if (!isSuggestionModeAvailable() || !noteIds?.length) {
		return [];
	}
	const clientId = resolveBlockClientId(null, threadId);
	if (!clientId) {
		return [];
	}

	const holders = getSupersededHolders(threadId, noteIds).filter(
		(id) => !keepRoot || id !== Number(threadId)
	);
	const retired = [];
	for (const noteId of holders) {
		const target = getBlockContent(clientId);
		const withoutMarkers = target
			? removeSuggestionMarkers(target.content, noteId)
			: null;
		const structural = findStructuralMarker(noteId);
		if (
			supersedingIds.has(noteId) ||
			(!withoutMarkers &&
				!structural &&
				getLoadedSuggestionStatus(noteId) !== 'pending')
		) {
			continue;
		}

		supersedingIds.add(noteId);
		try {
			// Markers first, so the new edit can be suggested even if the
			// status save fails; the orphan cleanup then tidies the note.
			if (withoutMarkers !== null) {
				dataDispatch(blockEditorStore).updateBlockAttributes(clientId, {
					[target.key]: withoutMarkers,
				});
			}
			if (structural) {
				rejectStructuralMarker(structural);
			}
			await saveNoteMeta(noteId, {
				_wp_suggestion_status: 'rejected-unsaved',
			});
			dataDispatch(STORE_NAME).setSuggestionStatus(
				noteId,
				'rejected-unsaved'
			);
			retired.push(noteId);
		} catch (error) {
			// eslint-disable-next-line no-console
			console.warn(
				'[AI-Feedback] Could not retire a superseded suggestion:',
				noteId,
				error
			);
		} finally {
			supersedingIds.delete(noteId);
		}
	}

	return retired;
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
	// One at a time, so each write sees the blocks as the last one left them.
	for (const note of notes || []) {
		try {
			if (canSuggestBlockAction(note)) {
				if (await suggestBlockAction(note)) {
					added++;
				}
			} else if (canSuggest(note) && (await suggestQuickFix(note))) {
				added++;
			}
		} catch {
			// The edit stays available in the sidebar.
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
