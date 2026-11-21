/**
 * WordPress dependencies
 */
import { _n, sprintf } from '@wordpress/i18n';

/**
 * Why the AI was not shown some images, one line per reason, for the
 * review summary. Built at render time so translations apply.
 *
 * @param {Array<{block_id: string, reason: string}>} skipped Images the review left out, from `images_skipped`.
 * @return {string[]} Lines, empty when no image was left out.
 */
export function describeSkippedImages(skipped) {
	if (!Array.isArray(skipped) || !skipped.length) {
		return [];
	}

	const counts = {};
	skipped.forEach(({ reason }) => {
		counts[reason] = (counts[reason] || 0) + 1;
	});

	const describe = {
		type: (n) =>
			/* translators: %d: number of images. */
			_n(
				'%d image was not shown to the AI: the AI provider does not take its file type, so it has no alt text proposal.',
				'%d images were not shown to the AI: the AI provider does not take their file type, so they have no alt text proposals.',
				n,
				'fresh-eyes'
			),
		dimensions: (n) =>
			/* translators: %d: number of images. */
			_n(
				'%d image was not shown to the AI: it is too large in pixels for the AI provider, so it has no alt text proposal.',
				'%d images were not shown to the AI: they are too large in pixels for the AI provider, so they have no alt text proposals.',
				n,
				'fresh-eyes'
			),
		file_size: (n) =>
			/* translators: %d: number of images. */
			_n(
				'%d image was not shown to the AI: its file is too large to send, so it has no alt text proposal.',
				'%d images were not shown to the AI: their files are too large to send, so they have no alt text proposals.',
				n,
				'fresh-eyes'
			),
		refused: (n) =>
			/* translators: %d: number of images. */
			_n(
				'The AI model refused the request with images, so the review ran on the text alone and %d image has no alt text proposal.',
				'The AI model refused the request with images, so the review ran on the text alone and %d images have no alt text proposals.',
				n,
				'fresh-eyes'
			),
		refused_recently: (n) =>
			/* translators: %d: number of images. */
			_n(
				'%d image was not shown to the AI because the selected model recently refused images, so it has no alt text proposal.',
				'%d images were not shown to the AI because the selected model recently refused images, so they have no alt text proposals.',
				n,
				'fresh-eyes'
			),
	};

	return Object.entries(counts)
		.filter(([reason]) => describe[reason])
		.map(([reason, count]) => sprintf(describe[reason](count), count));
}
