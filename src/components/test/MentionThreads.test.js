/**
 * MentionThreads against the real block editor store.
 *
 * External dependencies
 */
import { beforeAll, describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { createRegistry, RegistryProvider } from '@wordpress/data';
import {
	createBlock,
	getBlockTypes,
	store as blocksStore,
} from '@wordpress/blocks';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { registerCoreBlocks } from '@wordpress/block-library';

/**
 * Internal dependencies
 */
import MentionThreads, { getMentionThreads } from '../MentionThreads';

let mockThreads = {};
const mockOnReplyComplete = vi.fn();
vi.mock('../../hooks/useReplyThreads', () => ({
	default: () => ({
		threads: mockThreads,
		onReplyComplete: mockOnReplyComplete,
	}),
}));

// Records what each thread hands the reply list, which renders the answers
// with Preview and Apply.
const mockRepliesProps = [];
vi.mock('../FeedbackReplies', () => ({
	default: (props) => {
		mockRepliesProps.push(props);
		return null;
	},
}));

vi.mock('@wordpress/editor', () => ({
	store: 'core/editor',
}));

beforeAll(() => {
	if (!getBlockTypes().some((type) => type.name === 'core/paragraph')) {
		registerCoreBlocks();
	}
});

const EDIT = { type: 'replace_text', find: 'very long', replace: 'short' };

const THREADS = {
	// A review's feedback thread, listed by the feedback items instead.
	100: { replies: [], pending_reply_id: null },
	300: {
		is_mention: true,
		root: { id: 300, author: 'Jane', content: '@fresh-eyes simplify this' },
		replies: [
			{
				id: 900,
				author: 'Fresh Eyes',
				content: 'Shortened it.',
				is_ai: true,
				edit: EDIT,
				revision: 1,
			},
		],
		pending_reply_id: null,
	},
	305: {
		is_mention: true,
		root: { id: 305, author: 'Bob', content: 'Is this clear?' },
		replies: [],
		pending_reply_id: 306,
	},
};

/**
 * Render the panel with a paragraph linked to note 300.
 *
 * @return {string} The paragraph's client ID.
 */
function renderPanel() {
	const registry = createRegistry();
	registry.register(blocksStore);
	registry.register(blockEditorStore);
	registry.registerStore('core/editor', {
		reducer: (state = {}) => state,
		selectors: { getCurrentPostId: () => 42 },
	});
	const paragraph = createBlock('core/paragraph', {
		content: 'A very long sentence.',
	});
	// The editor links a note to its block in `metadata`, which createBlock
	// only keeps once the block editor's attribute filters have run.
	paragraph.attributes.metadata = { noteId: 300 };
	registry.dispatch(blockEditorStore).resetBlocks([paragraph]);

	render(
		<RegistryProvider value={registry}>
			<MentionThreads />
		</RegistryProvider>
	);
	return paragraph.clientId;
}

describe('getMentionThreads', () => {
	it('keeps the threads a person started, oldest first', () => {
		expect(getMentionThreads(THREADS).map(([id]) => id)).toEqual([
			300, 305,
		]);
		expect(getMentionThreads(undefined)).toEqual([]);
	});
});

describe('MentionThreads', () => {
	it('lists each question with its answers and the note’s block', () => {
		mockThreads = THREADS;
		mockRepliesProps.length = 0;

		const clientId = renderPanel();

		expect(screen.getByText('@fresh-eyes simplify this')).toBeTruthy();
		expect(screen.getByText('Is this clear?')).toBeTruthy();
		expect(
			screen.getAllByRole('button', { name: 'Go to block' })
		).toHaveLength(1);

		const [first, second] = mockRepliesProps.slice(-2);
		expect(first.note).toEqual({
			id: 300,
			block_id: clientId,
			is_mention: true,
		});
		expect(first.replies[0].edit).toEqual(EDIT);
		expect(first.pendingReplyId).toBeNull();
		expect(second.note).toEqual({
			id: 305,
			block_id: null,
			is_mention: true,
		});
		expect(second.pendingReplyId).toBe(306);
	});

	it('explains how to ask while there are no questions', () => {
		mockThreads = { 100: { replies: [], pending_reply_id: null } };

		renderPanel();

		expect(
			screen.getByRole('button', { name: 'Ask Fresh Eyes' })
		).toBeTruthy();
		expect(screen.queryByRole('list')).toBeNull();
	});
});
