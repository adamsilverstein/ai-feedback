/**
 * Feedback Item Component
 *
 * Displays a single feedback item or a group of feedback items.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Card, CardBody, CardHeader } from '@wordpress/components';
import { Icon, chevronUp, chevronDown } from '@wordpress/icons';
import { navigateToBlock } from '../utils/navigate-to-block';
import { getPriorityLevel } from '../utils/priority';
import { getFeedbackContent } from '../utils/feedback-content';
import FeedbackItemActions from './FeedbackItemActions';

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
 * Priority indicator badge.
 *
 * @param {Object} props          Component props.
 * @param {number} props.priority Priority score.
 * @return {Element|null} Badge element, or null for low or missing scores.
 */
export function PriorityBadge({ priority }) {
	if (typeof priority !== 'number') {
		return null;
	}

	// Low priority is implied, so only high and medium get a badge.
	const level = getPriorityLevel(priority);
	if (level === 'low') {
		return null;
	}

	const labels = {
		high: __('High priority', 'ai-feedback'),
		medium: __('Medium priority', 'ai-feedback'),
	};

	return (
		<span
			className={`priority-badge priority-${level}`}
			role="img"
			aria-label={labels[level]}
			title={labels[level]}
		>
			{level === 'high' ? '!' : '•'}
		</span>
	);
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
	const { title, feedback, suggestion } = getFeedbackContent(item);
	const isDismissed = !!item.is_resolved;

	return (
		<Card
			className={`ai-feedback-item${isDismissed ? ' is-dismissed' : ''}`}
			size="small"
			tabIndex={0}
		>
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
					<PriorityBadge priority={item.priority} />
					<strong className="feedback-title">{title}</strong>
					{isDismissed && (
						<span className="feedback-dismissed-label">
							{__('Dismissed', 'ai-feedback')}
						</span>
					)}
				</div>

				{!isDismissed && (
					<>
						{feedback && (
							<p className="feedback-text">{feedback}</p>
						)}

						{suggestion && (
							<p className="feedback-suggestion">
								<em>{suggestion}</em>
							</p>
						)}
					</>
				)}

				<FeedbackItemActions
					item={item}
					title={title}
					onNavigate={onNavigate}
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
	const { title, feedback, suggestion } = getFeedbackContent(item);
	const isDismissed = !!item.is_resolved;

	return (
		<Card
			className={`ai-feedback-group${isDismissed ? ' is-dismissed' : ''}`}
			size="small"
			tabIndex={0}
		>
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
						<PriorityBadge priority={item.priority} />
						<span className="group-title">{title}</span>
						{isDismissed && (
							<span className="feedback-dismissed-label">
								{__('Dismissed', 'ai-feedback')}
							</span>
						)}
						<Icon
							icon={expanded ? chevronUp : chevronDown}
							size={20}
						/>
					</Button>
				</div>
			</CardHeader>

			{expanded && (
				<CardBody>
					{feedback && <p className="feedback-text">{feedback}</p>}

					{suggestion && (
						<p className="feedback-suggestion">
							<em>{suggestion}</em>
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

			<CardBody className="ai-feedback-group-actions">
				<FeedbackItemActions item={item} title={title} />
			</CardBody>
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
