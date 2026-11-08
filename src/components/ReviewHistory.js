/**
 * Review History Component
 *
 * Lists recent reviews for the current post so writers can see how the
 * feedback changed between drafts.
 */
import { PanelBody, Button, Modal, Spinner } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useState, RawHTML } from '@wordpress/element';
import { store as editorStore } from '@wordpress/editor';
import { __, sprintf } from '@wordpress/i18n';
import { STORE_NAME } from '../store';
import {
	describeChange,
	formatReviewDate,
	formatNoteCount,
} from '../utils/review-history';

const SEVERITIES = ['critical', 'important', 'suggestion'];

/**
 * Localized severity labels.
 *
 * @return {Object} Map of severity to label.
 */
function getSeverityLabels() {
	return {
		critical: __('Critical', 'ai-feedback'),
		important: __('Important', 'ai-feedback'),
		suggestion: __('Suggestion', 'ai-feedback'),
	};
}

/**
 * Severity counts as a short text list.
 *
 * @param {Object} bySeverity Map of severity to count.
 * @return {string} Text such as "Critical: 1, Important: 2".
 */
function formatSeverities(bySeverity = {}) {
	const labels = getSeverityLabels();
	return SEVERITIES.filter((severity) => bySeverity[severity] > 0)
		.map((severity) => `${labels[severity]}: ${bySeverity[severity]}`)
		.join(', ');
}

/**
 * Modal showing one past review.
 *
 * @param {Object}   props         Component props.
 * @param {number}   props.postId  Post ID.
 * @param {Object}   props.entry   History entry.
 * @param {Function} props.onClose Close callback.
 * @return {Element} Modal.
 */
function HistoryReviewModal({ postId, entry, onClose }) {
	const { review, isLoading } = useSelect(
		(select) => ({
			review: select(STORE_NAME).getHistoryReview(
				postId,
				entry.review_id
			),
			isLoading: select(STORE_NAME).isResolving('getHistoryReview', [
				postId,
				entry.review_id,
			]),
		}),
		[postId, entry.review_id]
	);

	const notes = review?.notes || [];
	const severities = formatSeverities(entry.summary?.by_severity);

	return (
		<Modal
			title={sprintf(
				/* translators: %s: review date */
				__('Review from %s', 'ai-feedback'),
				formatReviewDate(entry.timestamp)
			)}
			onRequestClose={onClose}
			className="ai-feedback-history-modal"
		>
			{entry.summary_text && <p>{entry.summary_text}</p>}
			<p>
				<strong>{formatNoteCount(entry.note_count)}</strong>
				{severities && ` (${severities})`}
			</p>
			{entry.model && (
				<p className="model-used">
					<em>
						{__('Reviewed with:', 'ai-feedback')} {entry.model}
					</em>
				</p>
			)}

			{isLoading && <Spinner />}

			{!isLoading && review === null && (
				<p>{__('Could not load this review.', 'ai-feedback')}</p>
			)}

			{entry.notes_failed && (
				<p className="description">
					{__(
						'Notes could not be created for this review.',
						'ai-feedback'
					)}
				</p>
			)}

			{!isLoading &&
				review &&
				!entry.notes_failed &&
				notes.length === 0 && (
					<p className="description">
						{__(
							'The notes from this review are no longer available.',
							'ai-feedback'
						)}
					</p>
				)}

			{notes.length > 0 && (
				<ul className="ai-feedback-history-notes">
					{notes.map((note) => (
						<li key={note.id} className="ai-feedback-history-note">
							<RawHTML>{note.content?.rendered || ''}</RawHTML>
							{note.is_resolved && (
								<p className="ai-feedback-history-resolved">
									{__('Resolved', 'ai-feedback')}
								</p>
							)}
						</li>
					))}
				</ul>
			)}
		</Modal>
	);
}

/**
 * Review history panel.
 *
 * @return {Element|null} Panel, or null when there is no history.
 */
export default function ReviewHistory() {
	const [openEntry, setOpenEntry] = useState(null);
	const { history, postId } = useSelect(
		(select) => ({
			history: select(STORE_NAME).getReviewHistory(
				select(editorStore).getCurrentPostId()
			),
			postId: select(editorStore).getCurrentPostId(),
		}),
		[]
	);
	const { invalidateResolution } = useDispatch(STORE_NAME);

	const openReview = (entry) => {
		// Refetch so notes resolved or deleted since the last view show up.
		invalidateResolution('getHistoryReview', [postId, entry.review_id]);
		setOpenEntry(entry);
	};

	if (!history || history.length === 0) {
		return null;
	}

	return (
		<PanelBody
			title={__('Review History', 'ai-feedback')}
			initialOpen={false}
			className="ai-feedback-review-history"
		>
			<ol className="ai-feedback-history-list">
				{history.map((entry, index) => {
					const previous = history[index + 1];
					const change = describeChange(
						entry.note_count,
						previous?.note_count
					);
					const severities = formatSeverities(
						entry.summary?.by_severity
					);

					return (
						<li
							key={entry.review_id}
							className="ai-feedback-history-item"
						>
							<time
								className="ai-feedback-history-date"
								dateTime={entry.timestamp}
							>
								{formatReviewDate(entry.timestamp)}
							</time>
							<div>
								<strong>
									{formatNoteCount(entry.note_count)}
								</strong>
								{severities && ` (${severities})`}
							</div>
							{change && (
								<div className="ai-feedback-history-change">
									{change}
								</div>
							)}
							{entry.model && (
								<div className="ai-feedback-history-model">
									{entry.model}
								</div>
							)}
							<Button
								variant="secondary"
								size="small"
								className="ai-feedback-history-view"
								onClick={() => openReview(entry)}
								aria-label={sprintf(
									/* translators: %s: review date */
									__('View review from %s', 'ai-feedback'),
									formatReviewDate(entry.timestamp)
								)}
							>
								{__('View', 'ai-feedback')}
							</Button>
						</li>
					);
				})}
			</ol>
			{openEntry && (
				<HistoryReviewModal
					postId={postId}
					entry={openEntry}
					onClose={() => setOpenEntry(null)}
				/>
			)}
		</PanelBody>
	);
}
