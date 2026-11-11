/**
 * External dependencies
 */
import { act, renderHook } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { createRegistry, RegistryProvider } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies
 */
import useStartReview from '../use-start-review';

jest.mock('@wordpress/block-editor', () => ({ store: 'core/block-editor' }));
jest.mock('@wordpress/editor', () => ({ store: 'core/editor' }));
jest.mock('@wordpress/a11y', () => ({ speak: jest.fn() }));
jest.mock('../../store', () => ({ STORE_NAME: 'ai-feedback/store' }));
jest.mock('../../utils/block-utils', () => ({
	extractBlockData: (blocks) => blocks,
}));

function setup({
	postId = 1,
	status = 'draft',
	blocks = [{ clientId: 'a' }],
	isReviewing = false,
} = {}) {
	const startReview = jest.fn();
	const registry = createRegistry();
	registry.register(noticesStore);
	registry.registerStore('core/editor', {
		reducer: (state = {}) => state,
		selectors: {
			getCurrentPostId: () => postId,
			getEditedPostAttribute: (state, key) =>
				({ status, title: 'Title' })[key],
		},
	});
	registry.registerStore('core/block-editor', {
		reducer: (state = {}) => state,
		selectors: { getBlocks: () => blocks },
	});
	registry.registerStore('ai-feedback/store', {
		reducer: (state = {}) => state,
		selectors: {
			isReviewing: () => isReviewing,
			getSelectedModel: () => 'model',
			getFocusAreas: () => ['tone'],
			getTargetTone: () => 'casual',
			getFeedbackLocale: () => 'auto',
			getChunkSize: () => 20,
		},
		actions: {
			startReview: (args) => {
				startReview(args);
				return { type: 'NOOP' };
			},
		},
	});

	const { result } = renderHook(() => useStartReview(), {
		wrapper: ({ children }) => (
			<RegistryProvider value={registry}>{children}</RegistryProvider>
		),
	});

	return { result, registry, startReview };
}

describe('useStartReview', () => {
	beforeEach(() => speak.mockClear());

	it('starts a review with the current post and settings', async () => {
		const { result, startReview } = setup();

		await act(() => result.current.startReview());

		expect(startReview).toHaveBeenCalledWith({
			postId: 1,
			title: 'Title',
			blocks: [{ clientId: 'a' }],
			model: 'model',
			focusAreas: ['tone'],
			targetTone: 'casual',
			locale: 'auto',
			chunkSize: 20,
		});
	});

	it.each([
		['an unsaved post', { status: 'auto-draft' }, 'Save your post first'],
		['an empty post', { blocks: [] }, 'No content blocks'],
	])('warns instead of reviewing %s', async (_label, options, message) => {
		const { result, registry, startReview } = setup(options);

		await act(() => result.current.startReview());

		expect(startReview).not.toHaveBeenCalled();
		const [notice] = registry.select(noticesStore).getNotices();
		expect(notice.content).toContain(message);
	});

	it('announces when a review is already running', async () => {
		const { result, startReview } = setup({ isReviewing: true });

		await act(() => result.current.startReview());

		expect(startReview).not.toHaveBeenCalled();
		expect(speak).toHaveBeenCalledWith('A review is already in progress.');
	});
});
