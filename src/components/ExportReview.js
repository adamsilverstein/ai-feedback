/**
 * Export Review Component
 *
 * Dropdown to copy or download a review for sharing.
 */
import { DropdownMenu, MenuGroup, MenuItem } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { store as noticesStore } from '@wordpress/notices';
import { downloadBlob } from '@wordpress/blob';
import { __ } from '@wordpress/i18n';
import { copy, download, share } from '@wordpress/icons';
import {
	generateMarkdownReport,
	generatePlainTextSummary,
	generateJSON,
	getExportFilename,
} from '../utils/export';

const NOTICE_ID = 'ai-feedback-export';

/**
 * Export dropdown for a review.
 *
 * Hidden until the review has finished every part, so an incomplete review
 * can't be exported as if it were the whole document.
 *
 * @param {Object} props        Component props.
 * @param {Object} props.review Review response.
 * @return {Element|null} Dropdown menu.
 */
export default function ExportReview({ review }) {
	const postTitle = useSelect(
		(select) => select(editorStore).getEditedPostAttribute('title'),
		[]
	);
	const { createSuccessNotice, createErrorNotice } =
		useDispatch(noticesStore);

	const copyToClipboard = async (content) => {
		try {
			await window.navigator.clipboard.writeText(content);
			createSuccessNotice(__('Copied to clipboard.', 'fresh-eyes'), {
				type: 'snackbar',
				id: NOTICE_ID,
			});
		} catch {
			createErrorNotice(
				__(
					'Could not copy to the clipboard. Try downloading instead.',
					'fresh-eyes'
				),
				{ type: 'snackbar', id: NOTICE_ID }
			);
		}
	};

	const save = (content, extension, mimeType) => {
		downloadBlob(getExportFilename(review, extension), content, mimeType);
	};

	if (review?.is_in_progress || review?.is_partial) {
		return null;
	}

	return (
		<DropdownMenu
			icon={share}
			label={__('Export review', 'fresh-eyes')}
			className="ai-feedback-export"
		>
			{({ onClose }) => (
				<>
					<MenuGroup label={__('Copy', 'fresh-eyes')}>
						<MenuItem
							icon={copy}
							iconPosition="left"
							onClick={() => {
								copyToClipboard(
									generatePlainTextSummary(review, postTitle)
								);
								onClose();
							}}
						>
							{__('Copy summary', 'fresh-eyes')}
						</MenuItem>
						<MenuItem
							icon={copy}
							iconPosition="left"
							onClick={() => {
								copyToClipboard(
									generateMarkdownReport(review, postTitle)
								);
								onClose();
							}}
						>
							{__('Copy as Markdown', 'fresh-eyes')}
						</MenuItem>
					</MenuGroup>
					<MenuGroup label={__('Download', 'fresh-eyes')}>
						<MenuItem
							icon={download}
							iconPosition="left"
							onClick={() => {
								save(
									generateMarkdownReport(review, postTitle),
									'md',
									'text/markdown'
								);
								onClose();
							}}
						>
							{__('Download Markdown', 'fresh-eyes')}
						</MenuItem>
						<MenuItem
							icon={download}
							iconPosition="left"
							onClick={() => {
								save(
									generateJSON(review, postTitle),
									'json',
									'application/json'
								);
								onClose();
							}}
						>
							{__('Download JSON', 'fresh-eyes')}
						</MenuItem>
					</MenuGroup>
				</>
			)}
		</DropdownMenu>
	);
}
