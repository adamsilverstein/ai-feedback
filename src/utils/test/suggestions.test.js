/**
 * External dependencies
 */
import {
	afterAll,
	afterEach,
	beforeAll,
	beforeEach,
	describe,
	expect,
	it,
	vi,
} from 'vitest';

/**
 * WordPress dependencies
 */
import {
	create,
	RichTextData,
	toHTMLString,
	registerFormatType,
	unregisterFormatType,
} from '@wordpress/rich-text';

/**
 * Internal dependencies
 */
import {
	buildEditSuggestionContent,
	buildRewriteSuggestionContent,
	buildSuggestionContent,
	buildSuggestionPayload,
	canSuggest,
	hasSuggestionMarker,
	hasSuggestionMarkers,
	isSuggestionModeAvailable,
	removeOrphanedSuggestions,
	removeSuggestionMarkers,
	acceptSuggestionMarkers,
	saveSuggestionStatuses,
	suggestQuickFix,
	suggestQuickFixes,
	suggestReplyEdit,
	supersedeSuggestions,
} from '../suggestions';
import {
	registerEditorFormats,
	unregisterEditorFormats,
} from '../../../tests/js/editor-formats';

const mocks = vi.hoisted(() => ({
	apiFetch: vi.fn(),
	saveEntityRecord: vi.fn(),
	blocks: {},
	postContent: '',
	updateBlockAttributes: vi.fn(),
	setSuggestionStatus: vi.fn(),
	createInfoNotice: vi.fn(),
	notes: null,
}));

vi.mock('@wordpress/api-fetch', () => ({ default: mocks.apiFetch }));
vi.mock('@wordpress/block-editor', () => ({ store: 'core/block-editor' }));
vi.mock('@wordpress/blocks', () => ({ store: 'core/blocks' }));
vi.mock('@wordpress/notices', () => ({ store: 'core/notices' }));
vi.mock('@wordpress/data', async (importOriginal) => {
	const actual = await importOriginal();
	return {
		...actual,
		select: (store) => {
			switch (store) {
				case 'core/block-editor':
					return {
						getBlock: (clientId) => mocks.blocks[clientId] ?? null,
						getClientIdsWithDescendants: () =>
							Object.keys(mocks.blocks),
					};
				case 'core/blocks':
					return {
						getBlockType: () => ({
							attributes: { content: { type: 'rich-text' } },
						}),
					};
				case 'core/editor':
					return {
						...(mocks.editorReady === undefined
							? {}
							: {
									__unstableIsEditorReady: () =>
										mocks.editorReady,
								}),
						getCurrentPostId: () => 42,
						getEditedPostContent: () => mocks.postContent,
						getCurrentPostAttribute: (name) =>
							name === 'content' ? mocks.savedContent : undefined,
					};
				case 'core':
					return { getEntityRecords: () => mocks.notes };
				default:
					return actual.select(store);
			}
		},
		dispatch: (store) => {
			switch (store) {
				case 'core/block-editor':
					return {
						updateBlockAttributes: mocks.updateBlockAttributes,
					};
				case 'core/notices':
					return { createInfoNotice: mocks.createInfoNotice };
				case 'ai-feedback/store':
					return { setSuggestionStatus: mocks.setSuggestionStatus };
				case 'core':
					return { saveEntityRecord: mocks.saveEntityRecord };
				default:
					return actual.dispatch(store);
			}
		},
	};
});

const fix = { find: 'in order to', replace: 'to' };

/**
 * Resolves a meta save the way the comments endpoint answers it.
 *
 * @param {Object} record      Saved record.
 * @param {number} record.id   Note ID.
 * @param {Object} record.meta Meta values.
 * @return {Promise<Object>} Comment record.
 */
const savedRecord = async ({ id, meta }) => ({
	id,
	author: 0,
	meta: {
		_wp_suggestion: meta._wp_suggestion ?? '',
		_wp_suggestion_status: meta._wp_suggestion_status ?? '',
	},
});

describe('buildSuggestionContent', () => {
	it('adds an add run followed by a del run under one id', () => {
		expect(
			buildSuggestionContent('We met in order to plan.', fix, {
				id: 12,
				authorId: 0,
			})
		).toBe(
			'We met <mark class="wp-suggestion-add" data-suggestion-id="12" data-suggestion-type="add" data-author="0">to</mark><mark class="wp-suggestion-del" data-suggestion-id="12" data-suggestion-type="del" data-author="0">in order to</mark> plan.'
		);
	});

	it('keeps a line break inside the markers', () => {
		expect(
			buildSuggestionContent(
				'Revenue grew 40%<br>last year.',
				{ find: '40%\nlast year', replace: '40%\nin 2025' },
				{ id: 7 }
			)
		).toBe(
			'Revenue grew <mark class="wp-suggestion-add" data-suggestion-id="7" data-suggestion-type="add">40%<br>in 2025</mark><mark class="wp-suggestion-del" data-suggestion-id="7" data-suggestion-type="del">40%<br>last year</mark>.'
		);
	});

	it('only marks a deletion when the replacement is empty', () => {
		expect(
			buildSuggestionContent(
				'This is very good.',
				{ find: 'very ', replace: '' },
				{ id: 3 }
			)
		).toBe(
			'This is <mark class="wp-suggestion-del" data-suggestion-id="3" data-suggestion-type="del">very </mark>good.'
		);
	});

	it('keeps formatting inside the markers', () => {
		expect(
			buildSuggestionContent(
				'We <strong>utilize</strong> tools.',
				{ find: 'utilize', replace: 'use' },
				{ id: 5 }
			)
		).toBe(
			'We <mark class="wp-suggestion-add" data-suggestion-id="5" data-suggestion-type="add"><strong>use</strong></mark><mark class="wp-suggestion-del" data-suggestion-id="5" data-suggestion-type="del"><strong>utilize</strong></mark> tools.'
		);
	});

	it('returns RichTextData for RichTextData input', () => {
		const result = buildSuggestionContent(
			RichTextData.fromHTMLString('We met in order to plan.'),
			fix,
			{ id: 12 }
		);

		expect(result).toBeInstanceOf(RichTextData);
		expect(result.text).toBe('We met toin order to plan.');
	});

	it.each([
		['missing text', 'We met to plan.'],
		['ambiguous text', 'in order to go, in order to stay'],
		[
			'existing suggestions',
			'We <mark class="wp-suggestion-del" data-suggestion-id="1">met</mark> in order to plan.',
		],
	])('refuses %s', (label, content) => {
		expect(buildSuggestionContent(content, fix, { id: 1 })).toBeNull();
	});

	describe('with Gutenberg marker formats registered', () => {
		const settings = (className) => ({
			title: 'Suggestion',
			tagName: 'mark',
			className,
			attributes: {
				'data-suggestion-id': 'data-suggestion-id',
				'data-suggestion-type': 'data-suggestion-type',
				'data-author': 'data-author',
			},
			edit: () => null,
		});

		beforeEach(() => {
			registerFormatType(
				'core/suggestion-add',
				settings('wp-suggestion-add')
			);
			registerFormatType(
				'core/suggestion-del',
				settings('wp-suggestion-del')
			);
		});

		afterEach(() => {
			unregisterFormatType('core/suggestion-add');
			unregisterFormatType('core/suggestion-del');
		});

		it('parses the markers as Gutenberg formats', () => {
			const html = buildSuggestionContent(
				'We met in order to plan.',
				fix,
				{
					id: 12,
				}
			);
			const value = create({ html });

			expect(value.formats[7][0].type).toBe('core/suggestion-add');
			expect(value.formats[7][0].attributes).toMatchObject({
				'data-suggestion-id': '12',
				'data-suggestion-type': 'add',
			});
			expect(value.formats[9][0].type).toBe('core/suggestion-del');
		});
	});
});

describe('buildSuggestionPayload', () => {
	it('describes a replacement as one inline operation', () => {
		expect(
			buildSuggestionPayload('core/paragraph', 'content', fix)
		).toEqual({
			schemaVersion: 2,
			blockName: 'core/paragraph',
			baseRevision: null,
			operations: [
				{
					type: 'inline-suggestion',
					attribute: 'content',
					suggestionType: 'replace',
				},
			],
		});
	});

	it('describes an empty replacement as a deletion', () => {
		expect(
			buildSuggestionPayload('core/heading', 'content', {
				find: 'very ',
				replace: '',
			}).operations[0].suggestionType
		).toBe('del');
	});
});

describe('marker helpers', () => {
	it('finds suggestion markers', () => {
		expect(hasSuggestionMarkers('<mark class="wp-suggestion-add">')).toBe(
			true
		);
		expect(hasSuggestionMarkers('<mark class="wp-note">')).toBe(false);
		// The class name as plain text is not a marker.
		expect(hasSuggestionMarkers('<p>wp-suggestion-add</p>')).toBe(false);
		expect(hasSuggestionMarkers('<p class="wp-suggestion-add">x</p>')).toBe(
			false
		);
		expect(hasSuggestionMarker('<mark data-suggestion-id="12">', 12)).toBe(
			true
		);
		expect(hasSuggestionMarker('<mark data-suggestion-id="123">', 12)).toBe(
			false
		);
	});

	it.each([
		[{}, false],
		[{ id: 1, quick_fix: fix, block_id: 'a' }, true],
		[{ id: 1, quick_fix: fix, block_id: 'a', is_group: true }, false],
		[{ id: 1, quick_fix: fix, block_id: 'a', parent: 4 }, false],
		[{ id: 1, quick_fix: fix, block_id: 'a', is_resolved: true }, false],
		[
			{
				id: 1,
				quick_fix: fix,
				block_id: 'a',
				suggestion_status: 'pending',
			},
			false,
		],
	])('canSuggest(%j) is %s', (note, expected) => {
		expect(canSuggest(note)).toBe(expected);
	});
});

describe('isSuggestionModeAvailable', () => {
	afterEach(() => {
		delete window.__experimentalSuggestionMode;
		delete window.aiFeedbackData;
	});

	it('needs the Gutenberg experiment and server support', () => {
		expect(isSuggestionModeAvailable()).toBe(false);

		window.__experimentalSuggestionMode = true;
		expect(isSuggestionModeAvailable()).toBe(false);

		window.aiFeedbackData = { suggestions: true };
		expect(isSuggestionModeAvailable()).toBe(true);
	});
});

describe('suggestQuickFix', () => {
	const note = { id: 12, block_id: 'block-1', quick_fix: fix };

	beforeEach(() => {
		vi.clearAllMocks();
		mocks.saveEntityRecord.mockImplementation((kind, name, record) =>
			savedRecord(record)
		);
		mocks.blocks = {
			'block-1': {
				name: 'core/paragraph',
				attributes: { content: 'We met in order to plan.' },
			},
		};
	});

	it('stores the payload through core-data, then writes the markers', async () => {
		await expect(suggestQuickFix(note)).resolves.toBe(true);

		expect(mocks.saveEntityRecord).toHaveBeenCalledWith(
			'root',
			'comment',
			{
				id: 12,
				meta: {
					_wp_suggestion: JSON.stringify(
						buildSuggestionPayload('core/paragraph', 'content', fix)
					),
					_wp_suggestion_status: 'pending',
				},
			},
			{ throwOnError: true }
		);
		expect(mocks.apiFetch).not.toHaveBeenCalled();
		expect(mocks.updateBlockAttributes).toHaveBeenCalledWith('block-1', {
			content:
				'We met <mark class="wp-suggestion-add" data-suggestion-id="12" data-suggestion-type="add" data-author="0">to</mark><mark class="wp-suggestion-del" data-suggestion-id="12" data-suggestion-type="del" data-author="0">in order to</mark> plan.',
		});
		expect(mocks.setSuggestionStatus).toHaveBeenCalledWith(12, 'pending');
	});

	// Gutenberg tints and names a marker by matching `data-author` to the
	// note's author, and treats markers carrying the current user's id as
	// theirs to grow or remove. AI notes are authored by user 0.
	it.each([0, 7])(
		"credits the markers to the note's author (%i), not the current user",
		async (author) => {
			mocks.saveEntityRecord.mockImplementation(
				async (kind, name, record) => ({
					...(await savedRecord(record)),
					author,
				})
			);
			await suggestQuickFix(note);
			const { content } = mocks.updateBlockAttributes.mock.calls[0][1];
			// One deletion marker and one addition marker.
			expect(content.match(/data-author="[^"]*"/g)).toEqual([
				`data-author="${author}"`,
				`data-author="${author}"`,
			]);
		}
	);

	it('skips the request when the fix no longer applies', async () => {
		mocks.blocks['block-1'].attributes.content = 'We met to plan.';

		await expect(suggestQuickFix(note)).resolves.toBe(false);
		expect(mocks.saveEntityRecord).not.toHaveBeenCalled();
	});

	it('removes the payload when the block changed during the request', async () => {
		mocks.saveEntityRecord.mockImplementation(
			async (kind, name, record) => {
				if (record.meta._wp_suggestion) {
					mocks.blocks['block-1'].attributes.content = 'Rewritten.';
				}
				return savedRecord(record);
			}
		);

		await expect(suggestQuickFix(note)).resolves.toBe(false);

		expect(mocks.saveEntityRecord).toHaveBeenLastCalledWith(
			'root',
			'comment',
			{
				id: 12,
				meta: { _wp_suggestion: null, _wp_suggestion_status: null },
			},
			{ throwOnError: true }
		);
		expect(mocks.updateBlockAttributes).not.toHaveBeenCalled();
		expect(mocks.setSuggestionStatus).toHaveBeenCalledWith(12, null);
	});

	it('removes the note again when the server dropped the payload', async () => {
		// Gutenberg's sanitize callback stores '' for a payload it rejects.
		mocks.saveEntityRecord.mockImplementation(async (kind, name, record) =>
			savedRecord({ id: record.id, meta: {} })
		);

		await expect(suggestQuickFix(note)).resolves.toBe(false);

		expect(mocks.saveEntityRecord).toHaveBeenCalledTimes(2);
		expect(mocks.saveEntityRecord.mock.calls[1][2]).toEqual({
			id: 12,
			meta: { _wp_suggestion: null, _wp_suggestion_status: null },
		});
		expect(mocks.updateBlockAttributes).not.toHaveBeenCalled();
	});
});

describe('suggestQuickFixes', () => {
	beforeEach(() => {
		vi.clearAllMocks();
		mocks.blocks = {
			'block-1': {
				name: 'core/paragraph',
				attributes: { content: 'We met in order to plan.' },
			},
		};
		mocks.saveEntityRecord.mockImplementation((kind, name, record) =>
			savedRecord(record)
		);
	});

	afterEach(() => {
		delete window.__experimentalSuggestionMode;
		delete window.aiFeedbackData;
	});

	it('does nothing without Suggestion Mode', async () => {
		await expect(
			suggestQuickFixes([{ id: 12, block_id: 'block-1', quick_fix: fix }])
		).resolves.toBe(0);
		expect(mocks.saveEntityRecord).not.toHaveBeenCalled();
	});

	it('adds eligible fixes and announces them', async () => {
		window.__experimentalSuggestionMode = true;
		window.aiFeedbackData = { suggestions: true };

		await expect(
			suggestQuickFixes([
				{ id: 12, block_id: 'block-1', quick_fix: fix },
				{ id: 13, block_id: 'block-1' },
			])
		).resolves.toBe(1);
		expect(mocks.createInfoNotice).toHaveBeenCalled();
	});

	it('only suggests the first fix in a block', async () => {
		window.__experimentalSuggestionMode = true;
		window.aiFeedbackData = { suggestions: true };
		mocks.blocks['block-1'].attributes.content =
			'We met in order to plan the very big launch.';
		mocks.updateBlockAttributes.mockImplementationOnce((id, attributes) =>
			Object.assign(mocks.blocks[id].attributes, attributes)
		);

		await expect(
			suggestQuickFixes([
				{ id: 12, block_id: 'block-1', quick_fix: fix },
				{
					id: 13,
					block_id: 'block-1',
					quick_fix: { find: 'very ', replace: '' },
				},
			])
		).resolves.toBe(1);

		expect(mocks.saveEntityRecord).toHaveBeenCalledTimes(1);
		expect(mocks.saveEntityRecord.mock.calls[0][2].id).toBe(12);
		expect(mocks.updateBlockAttributes).toHaveBeenCalledTimes(1);
	});

	it('keeps going when one request fails', async () => {
		window.__experimentalSuggestionMode = true;
		window.aiFeedbackData = { suggestions: true };
		mocks.saveEntityRecord.mockRejectedValue(new Error('nope'));

		await expect(
			suggestQuickFixes([{ id: 12, block_id: 'block-1', quick_fix: fix }])
		).resolves.toBe(0);
		expect(mocks.updateBlockAttributes).not.toHaveBeenCalled();
	});
});

describe('removeOrphanedSuggestions', () => {
	beforeEach(() => {
		vi.clearAllMocks();
		window.__experimentalSuggestionMode = true;
		window.aiFeedbackData = { suggestions: true };
		mocks.apiFetch.mockResolvedValue({ note_ids: [12, 13] });
		mocks.saveEntityRecord.mockImplementation((kind, name, record) =>
			savedRecord(record)
		);
		mocks.postContent =
			'<!-- wp:paragraph --><p><mark class="wp-suggestion-del" data-suggestion-id="12">x</mark></p><!-- /wp:paragraph -->';
		mocks.savedContent = mocks.postContent;
	});

	afterEach(() => {
		delete window.__experimentalSuggestionMode;
		delete window.aiFeedbackData;
		delete mocks.editorReady;
	});

	it('removes saved pending suggestions whose markers are gone', async () => {
		await expect(removeOrphanedSuggestions(42)).resolves.toBe(1);

		expect(mocks.apiFetch).toHaveBeenCalledWith({
			path: '/ai-feedback/v1/notes/post/42/saved-pending-suggestions',
		});
		expect(mocks.saveEntityRecord).toHaveBeenCalledTimes(1);
		expect(mocks.saveEntityRecord.mock.calls[0][2]).toEqual({
			id: 13,
			meta: { _wp_suggestion: null, _wp_suggestion_status: null },
		});
		expect(mocks.setSuggestionStatus).toHaveBeenCalledWith(13, null);
	});

	it('keeps a suggestion whose marker is only in the saved content', async () => {
		// The author removed marker 12 without saving, so undo can restore it.
		mocks.postContent =
			'<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->';

		await expect(removeOrphanedSuggestions(42)).resolves.toBe(1);
		expect(mocks.saveEntityRecord).toHaveBeenCalledTimes(1);
		expect(mocks.saveEntityRecord.mock.calls[0][2].id).toBe(13);
	});

	it('does nothing without Suggestion Mode', async () => {
		delete window.__experimentalSuggestionMode;

		await expect(removeOrphanedSuggestions(42)).resolves.toBe(0);
		expect(mocks.apiFetch).not.toHaveBeenCalled();
		expect(mocks.saveEntityRecord).not.toHaveBeenCalled();
	});

	it('leaves suggestions the server does not list', async () => {
		// Too recent, still anchored, or a newer autosave exists.
		mocks.apiFetch.mockResolvedValue({ note_ids: [] });

		await expect(removeOrphanedSuggestions(42)).resolves.toBe(0);
		expect(mocks.saveEntityRecord).not.toHaveBeenCalled();
	});

	it.each([
		['empty', ''],
		['whitespace only', ' \n'],
	])('waits for content to load (%s)', async (label, content) => {
		mocks.postContent = content;

		await expect(removeOrphanedSuggestions(42)).resolves.toBe(0);
		expect(mocks.saveEntityRecord).not.toHaveBeenCalled();
	});

	it('waits for the editor to be ready', async () => {
		mocks.editorReady = false;

		await expect(removeOrphanedSuggestions(42)).resolves.toBe(0);
		expect(mocks.saveEntityRecord).not.toHaveBeenCalled();
	});

	it('cleans up an empty post once the editor is ready', async () => {
		mocks.editorReady = true;
		mocks.postContent = '';
		mocks.savedContent = '';

		await expect(removeOrphanedSuggestions(42)).resolves.toBe(2);
		expect(mocks.setSuggestionStatus).toHaveBeenCalledWith(12, null);
		expect(mocks.setSuggestionStatus).toHaveBeenCalledWith(13, null);
	});
});

describe('buildRewriteSuggestionContent', () => {
	const rewrite = {
		type: 'rewrite_block',
		content: 'We met to plan the launch.',
		original: 'We met in order to plan the launch.',
	};
	const add = (id, html) =>
		`<mark class="wp-suggestion-add" data-suggestion-id="${id}" data-suggestion-type="add">${html}</mark>`;
	const del = (id, html) =>
		`<mark class="wp-suggestion-del" data-suggestion-id="${id}" data-suggestion-type="del">${html}</mark>`;

	it('marks each changed run as an add then a del under one id', () => {
		expect(
			buildRewriteSuggestionContent(
				'We met in order to plan the launch.',
				{
					...rewrite,
					content: 'We met to plan the <em>launch</em> today.',
				},
				{ id: 30 }
			)
		).toBe(
			`We met ${del(30, 'in order ')}to plan the ${add(30, '<em>launch</em> today')}${del(30, 'launch')}.`
		);
	});

	it('goes through buildEditSuggestionContent for rewrites', () => {
		expect(
			buildEditSuggestionContent(
				'We met in order to plan the launch.',
				rewrite,
				{ id: 31 }
			)
		).toBe(`We met ${del(31, 'in order ')}to plan the launch.`);
	});

	it('returns RichTextData for RichTextData input', () => {
		const result = buildRewriteSuggestionContent(
			RichTextData.fromHTMLString('We met in order to plan the launch.'),
			rewrite,
			{ id: 32 }
		);
		expect(result).toBeInstanceOf(RichTextData);
		expect(result.text).toBe('We met in order to plan the launch.');
	});

	it.each([
		['outdated text', 'We met to plan.'],
		[
			'existing suggestions',
			'We met <mark class="wp-suggestion-del" data-suggestion-id="1">in order</mark> to plan the launch.',
		],
	])('refuses %s', (label, content) => {
		expect(
			buildRewriteSuggestionContent(content, rewrite, { id: 1 })
		).toBeNull();
	});

	it('describes a rewrite as a replacement', () => {
		expect(
			buildSuggestionPayload('core/paragraph', 'content', rewrite)
				.operations[0].suggestionType
		).toBe('replace');
	});
});

describe('suggestReplyEdit', () => {
	const reply = {
		id: 40,
		is_ai: true,
		edit: { type: 'replace_text', find: 'in order to', replace: 'to' },
	};

	beforeEach(() => {
		vi.clearAllMocks();
		window.__experimentalSuggestionMode = true;
		window.aiFeedbackData = { suggestions: true };
		mocks.saveEntityRecord.mockImplementation((kind, name, record) =>
			savedRecord(record)
		);
		mocks.blocks = {
			'block-1': {
				name: 'core/paragraph',
				attributes: { content: 'We met in order to plan.' },
			},
		};
	});

	afterEach(() => {
		delete window.__experimentalSuggestionMode;
		delete window.aiFeedbackData;
	});

	it('adds the edit as a suggestion on the reply', async () => {
		await expect(suggestReplyEdit(reply, 39, 'block-1')).resolves.toBe(
			true
		);

		expect(mocks.saveEntityRecord).toHaveBeenCalledWith(
			'root',
			'comment',
			expect.objectContaining({ id: 40 }),
			{ throwOnError: true }
		);
		expect(mocks.updateBlockAttributes).toHaveBeenCalledWith('block-1', {
			content:
				'We met <mark class="wp-suggestion-add" data-suggestion-id="40" data-suggestion-type="add" data-author="0">to</mark><mark class="wp-suggestion-del" data-suggestion-id="40" data-suggestion-type="del" data-author="0">in order to</mark> plan.',
		});
		expect(mocks.createInfoNotice).toHaveBeenCalledTimes(1);
	});

	it('finds the block by the thread note, as the threads hook calls it', async () => {
		mocks.blocks = {
			'other-block': {
				name: 'core/paragraph',
				attributes: { content: 'Unrelated in order to text.' },
			},
			'linked-block': {
				name: 'core/paragraph',
				attributes: {
					content: 'We met in order to plan.',
					metadata: { noteId: [12, 39] },
				},
			},
		};

		await expect(suggestReplyEdit(reply, 39)).resolves.toBe(true);

		expect(mocks.updateBlockAttributes).toHaveBeenCalledTimes(1);
		expect(mocks.updateBlockAttributes).toHaveBeenCalledWith(
			'linked-block',
			expect.objectContaining({
				content: expect.stringContaining('data-suggestion-id="40"'),
			})
		);
	});

	it('does nothing when no block links the thread', async () => {
		await expect(suggestReplyEdit(reply, 39)).resolves.toBe(false);

		expect(mocks.saveEntityRecord).not.toHaveBeenCalled();
		expect(mocks.updateBlockAttributes).not.toHaveBeenCalled();
	});

	it('adds a rewrite as one replace suggestion with a marker pair per change', async () => {
		mocks.blocks['block-1'].attributes.metadata = { noteId: 39 };
		const rewrite = {
			id: 41,
			is_ai: true,
			edit: {
				type: 'rewrite_block',
				content: 'We met to plan the <em>launch</em>.',
				original: 'We met in order to plan.',
			},
		};

		await expect(suggestReplyEdit(rewrite, 39)).resolves.toBe(true);

		const [, , record] = mocks.saveEntityRecord.mock.calls[0];
		expect(record.id).toBe(41);
		expect(JSON.parse(record.meta._wp_suggestion)).toMatchObject({
			blockName: 'core/paragraph',
			operations: [
				{
					type: 'inline-suggestion',
					attribute: 'content',
					suggestionType: 'replace',
				},
			],
		});
		expect(record.meta._wp_suggestion_status).toBe('pending');

		// Markers are built after the save, carrying the saved author.
		const marker = (type, html) =>
			`<mark class="wp-suggestion-${type}" data-suggestion-id="41" data-suggestion-type="${type}" data-author="0">${html}</mark>`;
		expect(mocks.updateBlockAttributes).toHaveBeenCalledWith('block-1', {
			content: `We met ${marker('del', 'in order ')}to plan${marker('add', ' the <em>launch</em>')}.`,
		});
	});

	it('removes the suggestion again when the server drops the payload', async () => {
		mocks.saveEntityRecord.mockImplementation(
			async (kind, name, { id, meta }) => ({
				id,
				author: 0,
				meta: {
					_wp_suggestion: '',
					_wp_suggestion_status: meta._wp_suggestion_status ?? '',
				},
			})
		);

		await expect(suggestReplyEdit(reply, 39, 'block-1')).resolves.toBe(
			false
		);

		expect(mocks.saveEntityRecord).toHaveBeenLastCalledWith(
			'root',
			'comment',
			{
				id: 40,
				meta: { _wp_suggestion: null, _wp_suggestion_status: null },
			},
			{ throwOnError: true }
		);
		expect(mocks.updateBlockAttributes).not.toHaveBeenCalled();
		expect(mocks.setSuggestionStatus).toHaveBeenCalledWith(40, null);
	});

	it('leaves a block that already holds suggestions alone', async () => {
		mocks.blocks['block-1'].attributes.content =
			'We <mark class="wp-suggestion-del" data-suggestion-id="7">met</mark> in order to plan.';

		await expect(suggestReplyEdit(reply, 39, 'block-1')).resolves.toBe(
			false
		);
		expect(mocks.saveEntityRecord).not.toHaveBeenCalled();
		expect(mocks.updateBlockAttributes).not.toHaveBeenCalled();
	});

	it('does nothing without Suggestion Mode', async () => {
		delete window.__experimentalSuggestionMode;

		await expect(suggestReplyEdit(reply, 39, 'block-1')).resolves.toBe(
			false
		);
		expect(mocks.saveEntityRecord).not.toHaveBeenCalled();
	});

	it('leaves an outdated edit to the sidebar', async () => {
		mocks.blocks['block-1'].attributes.content = 'We met to plan.';

		await expect(suggestReplyEdit(reply, 39, 'block-1')).resolves.toBe(
			false
		);
		expect(mocks.saveEntityRecord).not.toHaveBeenCalled();
		expect(mocks.updateBlockAttributes).not.toHaveBeenCalled();
	});

	it('leaves the edit to the sidebar when saving fails', async () => {
		mocks.saveEntityRecord.mockRejectedValue(new Error('nope'));

		await expect(suggestReplyEdit(reply, 39, 'block-1')).resolves.toBe(
			false
		);
		expect(mocks.updateBlockAttributes).not.toHaveBeenCalled();
	});
});

const ADD_40 =
	'<mark class="wp-suggestion-add" data-suggestion-id="40" data-suggestion-type="add" data-author="0">to</mark>';
const DEL_40 =
	'<mark class="wp-suggestion-del" data-suggestion-id="40" data-suggestion-type="del" data-author="0">in order to</mark>';

describe('removeSuggestionMarkers', () => {
	it('drops the added run and keeps the deleted text, as Reject does', () => {
		expect(
			removeSuggestionMarkers(`We met ${ADD_40}${DEL_40} plan.`, 40)
		).toBe('We met in order to plan.');
	});

	it('leaves other suggestions in place', () => {
		const other =
			'<mark class="wp-suggestion-del" data-suggestion-id="41" data-suggestion-type="del">soon</mark>';
		expect(
			removeSuggestionMarkers(
				`We met ${ADD_40}${DEL_40} plan ${other}.`,
				40
			)
		).toBe(`We met in order to plan ${other}.`);
	});

	it('keeps formatting inside the deleted run', () => {
		expect(
			removeSuggestionMarkers(
				'A <mark class="wp-suggestion-add" data-suggestion-id="7">b</mark><mark class="wp-suggestion-del" data-suggestion-id="7"><strong>c</strong></mark> d',
				7
			)
		).toBe('A <strong>c</strong> d');
	});

	it('returns rich text for rich text content', () => {
		const result = removeSuggestionMarkers(
			RichTextData.fromHTMLString(`We met ${ADD_40}${DEL_40} plan.`),
			40
		);
		expect(result).toBeInstanceOf(RichTextData);
		expect(result.toHTMLString()).toBe('We met in order to plan.');
	});

	it('returns null when the content has no marker for the id', () => {
		expect(removeSuggestionMarkers('We met in order to plan.', 40)).toBe(
			null
		);
	});
});

describe('supersedeSuggestions', () => {
	beforeEach(() => {
		vi.clearAllMocks();
		window.__experimentalSuggestionMode = true;
		window.aiFeedbackData = { suggestions: true };
		mocks.notes = null;
		mocks.saveEntityRecord.mockImplementation((kind, name, record) =>
			savedRecord(record)
		);
		mocks.blocks = {
			'block-1': {
				name: 'core/paragraph',
				attributes: {
					content: `We met ${ADD_40}${DEL_40} plan.`,
					metadata: { noteId: 39 },
				},
			},
		};
	});

	afterEach(() => {
		delete window.__experimentalSuggestionMode;
		delete window.aiFeedbackData;
	});

	it('takes out the markers and marks the suggestion rejected', async () => {
		await expect(supersedeSuggestions(39, [39, 40])).resolves.toEqual([40]);

		expect(mocks.updateBlockAttributes).toHaveBeenCalledWith('block-1', {
			content: 'We met in order to plan.',
		});
		expect(mocks.saveEntityRecord).toHaveBeenCalledTimes(1);
		expect(mocks.saveEntityRecord).toHaveBeenCalledWith(
			'root',
			'comment',
			{ id: 40, meta: { _wp_suggestion_status: 'rejected-unsaved' } },
			{ throwOnError: true }
		);
		expect(mocks.setSuggestionStatus).toHaveBeenCalledWith(
			40,
			'rejected-unsaved'
		);
	});

	it('retires a pending suggestion whose markers are already gone', async () => {
		mocks.blocks['block-1'].attributes.content = 'We met in order to plan.';
		mocks.notes = [
			{
				id: 39,
				meta: {
					_wp_suggestion: '{}',
					_wp_suggestion_status: 'pending',
				},
			},
		];

		await expect(supersedeSuggestions(39, [39])).resolves.toEqual([39]);
		expect(mocks.updateBlockAttributes).not.toHaveBeenCalled();
	});

	it('leaves accepted and rejected suggestions alone', async () => {
		mocks.blocks['block-1'].attributes.content = 'We met to plan.';
		mocks.notes = [
			{
				id: 40,
				meta: {
					_wp_suggestion: '{}',
					_wp_suggestion_status: 'applied-unsaved',
				},
			},
		];

		await expect(supersedeSuggestions(39, [39, 40])).resolves.toEqual([]);
		expect(mocks.saveEntityRecord).not.toHaveBeenCalled();
		expect(mocks.updateBlockAttributes).not.toHaveBeenCalled();
	});

	it('does nothing without Suggestion Mode', async () => {
		delete window.__experimentalSuggestionMode;

		await expect(supersedeSuggestions(39, [40])).resolves.toEqual([]);
		expect(mocks.updateBlockAttributes).not.toHaveBeenCalled();
	});

	it('does nothing when the thread’s block is not loaded', async () => {
		mocks.blocks = {};

		await expect(supersedeSuggestions(39, [40])).resolves.toEqual([]);
		expect(mocks.saveEntityRecord).not.toHaveBeenCalled();
	});

	it('keeps the markers out when the status cannot be saved', async () => {
		mocks.saveEntityRecord.mockRejectedValue(new Error('offline'));
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

		await expect(supersedeSuggestions(39, [40])).resolves.toEqual([]);
		expect(mocks.updateBlockAttributes).toHaveBeenCalledTimes(1);
		warn.mockRestore();
	});
});

describe('acceptSuggestionMarkers', () => {
	it('keeps the added run and drops the deleted text, as Accept does', () => {
		expect(
			acceptSuggestionMarkers(`We met ${ADD_40}${DEL_40} plan.`, 40)
		).toBe('We met to plan.');
	});

	it('leaves other suggestions in place', () => {
		const other =
			'<mark class="wp-suggestion-del" data-suggestion-id="41" data-suggestion-type="del">soon</mark>';
		expect(
			acceptSuggestionMarkers(
				`We met ${ADD_40}${DEL_40} plan ${other}.`,
				40
			)
		).toBe(`We met to plan ${other}.`);
	});

	it('keeps formatting inside the added run', () => {
		expect(
			acceptSuggestionMarkers(
				'A <mark class="wp-suggestion-add" data-suggestion-id="7"><strong>b</strong></mark><mark class="wp-suggestion-del" data-suggestion-id="7">c</mark> d',
				7
			)
		).toBe('A <strong>b</strong> d');
	});

	it('returns null without a marker for the id', () => {
		expect(acceptSuggestionMarkers('Plain text.', 40)).toBeNull();
	});

	it('keeps rich text data as rich text data', () => {
		const result = acceptSuggestionMarkers(
			RichTextData.fromHTMLString(`We met ${ADD_40}${DEL_40} plan.`),
			40
		);
		expect(result).toBeInstanceOf(RichTextData);
		expect(result.toHTMLString()).toBe('We met to plan.');
	});
});

describe('saveSuggestionStatuses', () => {
	it('saves each status and reports the notes that failed', async () => {
		mocks.saveEntityRecord.mockReset();
		mocks.setSuggestionStatus.mockReset();
		mocks.saveEntityRecord.mockImplementation((kind, name, record) =>
			record.id === 2
				? Promise.reject(new Error('nope'))
				: Promise.resolve(record)
		);

		const failed = await saveSuggestionStatuses([1, 2], 'applied-unsaved');

		expect(failed).toEqual([2]);
		expect(mocks.saveEntityRecord).toHaveBeenCalledWith(
			'root',
			'comment',
			{ id: 1, meta: { _wp_suggestion_status: 'applied-unsaved' } },
			{ throwOnError: true }
		);
		expect(mocks.setSuggestionStatus).toHaveBeenCalledTimes(1);
		expect(mocks.setSuggestionStatus).toHaveBeenCalledWith(
			1,
			'applied-unsaved'
		);
	});
});

describe('suggestions beside a footnote', () => {
	// With core's formats, as in the editor, a footnote is one object.
	beforeAll(registerEditorFormats);
	afterAll(unregisterEditorFormats);

	const FOOTNOTE =
		'<sup data-fn="f1" class="fn"><a href="#f1" id="f1-link">1</a></sup>';
	const content = `Revenue growth${FOOTNOTE} was strong last year.`;
	const normalize = (html) => toHTMLString({ value: create({ html }) });
	const footnotes = (html) => html.split('data-fn="f1"').length - 1;

	it('marks the change and keeps the footnote once, through Accept and Reject', () => {
		const suggested = buildSuggestionContent(
			content,
			{ find: 'strong', replace: 'steady' },
			{ id: 30 }
		);

		expect(suggested).not.toBeNull();
		expect(footnotes(suggested)).toBe(1);
		expect(acceptSuggestionMarkers(suggested, 30)).toBe(
			normalize(`Revenue growth${FOOTNOTE} was steady last year.`)
		);
		expect(removeSuggestionMarkers(suggested, 30)).toBe(normalize(content));
	});

	it('suggests nothing across the footnote', () => {
		for (const find of ['growth1 was', 'growth\ufffc was']) {
			expect(
				buildSuggestionContent(
					content,
					{ find, replace: 'growth was' },
					{ id: 31 }
				)
			).toBeNull();
		}
	});
});
