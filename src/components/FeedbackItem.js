/**
 * Feedback Item Component
 *
 * Displays a single feedback item or a group of feedback items.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Card, CardBody, CardHeader } from '@wordpress/components';
import { Icon, chevronUp, chevronDown } from '@wordpress/icons';
import { dispatch } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import FeedbackReplies from './FeedbackReplies';

/**
 * Navigate to a specific block in the editor.
 *
 * @param {string} clientId Block client ID to navigate to.
 */
function navigateToBlock(clientId) {
	const { selectBlock } = dispatch(blockEditorStore);

	if (selectBlock) {
		selectBlock(clientId);
		// Scroll to block if possible
		const blockElement = document.querySelector(
			`[data-block="${clientId}"]`
		);
		if (blockElement) {
			blockElement.scrollIntoView({
				behavior: 'smooth',
				block: 'center',
			});
		}
	}
}

/**
 * Get severity badge emoji.
 *
 * @param {string} severity Severity level.
 * @return {string} Emoji for severity.
 */
function getSeverityBadge(severity) {
	const badges = {
		critical: '🔴',
		important: '🟡',
		suggestion: '🟢',
	};
	return badges[severity] || '⚪';
}

/**
 * Get severity label.
 *
 * @param {string} severity Severity level.
 * @return {string} Label for severity.
 */
function getSeverityLabel(severity) {
	const labels = {
		critical: __('Critical', 'ai-feedback'),
		important: __('Important', 'ai-feedback'),
		suggestion: __('Suggestion', 'ai-feedback'),
	};
	return labels[severity] || severity;
}

/**
 * Single feedback item component (non-grouped).
 *
 * @param {Object}   props            Component props.
 * @param {Object}   props.item       Feedback item data.
 * @param {Function} props.onNavigate Navigation callback.
 * @return {Element} Feedback item component.
 */
function SingleFeedbackItem({ item, onNavigate }) {
	return (
		<Card className="ai-feedback-item" size="small" tabIndex={0}>
			<CardBody>
				<div className="ai-feedback-item-header">
					<span
						className={`severity-badge severity-${item.severity}`}
						role="img"
						aria-label={sprintf(
							/* translators: %s: severity level */
							__('Severity: %s', 'ai-feedback'),
							getSeverityLabel(item.severity)
						)}
					>
						{getSeverityBadge(item.severity)}
					</span>
					<strong className="feedback-title">{item.title}</strong>
				</div>

				<p className="feedback-text">{item.feedback}</p>

				{item.suggestion && (
					<p className="feedback-suggestion">
						<em>{item.suggestion}</em>
					</p>
				)}

				{item.block_id && (
					<Button
						variant="link"
						size="small"
						onClick={() => onNavigate(item.block_id)}
						className="go-to-block-button"
					>
						{__('Go to block', 'ai-feedback')} →
					</Button>
				)}

				<FeedbackReplies
					replies={item.replies || []}
					pendingReplyId={item.pending_reply_id ?? null}
				/>
			</CardBody>
		</Card>
	);
}

/**
 * Grouped feedback items component.
 *
 * @param {Object}   props            Component props.
 * @param {Object}   props.item       Grouped feedback item data.
 * @param {Function} props.onNavigate Navigation callback.
 * @return {Element} Feedback group component.
 */
function GroupedFeedbackItem({ item, onNavigate }) {
	const [expanded, setExpanded] = useState(false);

	return (
		<Card className="ai-feedback-group" size="small" tabIndex={0}>
			<CardHeader>
				<div className="ai-feedback-group-header">
					<Button
						variant="link"
						onClick={() => setExpanded(!expanded)}
						aria-expanded={expanded}
						className="expand-group-button"
					>
						<span
							className={`severity-badge severity-${item.severity}`}
							role="img"
							aria-label={sprintf(
								/* translators: %s: severity level */
								__('Severity: %s', 'ai-feedback'),
								getSeverityLabel(item.severity)
							)}
						>
							{getSeverityBadge(item.severity)}
						</span>
						<span className="group-title">{item.title}</span>
						<Icon
							icon={expanded ? chevronUp : chevronDown}
							size={20}
						/>
					</Button>
				</div>
			</CardHeader>

			{expanded && (
				<CardBody>
					<p className="feedback-text">{item.feedback}</p>

					{item.suggestion && (
						<p className="feedback-suggestion">
							<em>{item.suggestion}</em>
						</p>
					)}

					<div className="affected-blocks-section">
						<h5 className="affected-blocks-heading">
							{__('Affected blocks:', 'ai-feedback')}
						</h5>
						<ul className="affected-blocks-list">
							{item.block_ids &&
								item.block_ids.map((blockId, index) => (
									<li key={blockId}>
										<Button
											variant="link"
											size="small"
											onClick={() => onNavigate(blockId)}
											className="go-to-block-button"
										>
											{sprintf(
												/* translators: %d: block number */
												__('Block %d', 'ai-feedback'),
												index + 1
											)}{' '}
											→
										</Button>
									</li>
								))}
						</ul>
					</div>
				</CardBody>
			)}
		</Card>
	);
}

/**
 * Feedback item wrapper component.
 *
 * Determines whether to render a single item or a grouped item.
 *
 * @param {Object} props      Component props.
 * @param {Object} props.item Feedback item data.
 * @return {Element} Feedback item component.
 */
export default function FeedbackItem({ item }) {
	const handleNavigate = (blockId) => {
		navigateToBlock(blockId);
	};

	if (item.is_group) {
		return <GroupedFeedbackItem item={item} onNavigate={handleNavigate} />;
	}

	return <SingleFeedbackItem item={item} onNavigate={handleNavigate} />;
}
