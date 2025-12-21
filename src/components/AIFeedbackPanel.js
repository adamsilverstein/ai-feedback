/**
 * AI Feedback Panel Component
 */
import { PanelBody, Notice, Button } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store';

import ModelSelector from './ModelSelector';
import SettingsPanel from './SettingsPanel';
import ReviewButton from './ReviewButton';
import ReviewSummary from './ReviewSummary';

/**
 * Settings page URL.
 */
const SETTINGS_PAGE_URL = '/wp-admin/admin.php?page=ai-feedback-settings';

/**
 * Get action button for specific error types.
 *
 * @param {Object} error Error object with code, message, and data.
 * @return {JSX.Element|null} Action button or null.
 */
function getErrorAction(error) {
	// Check if error is related to API credits or billing
	if (
		error.code === 'ai_request_failed' &&
		(error.message.toLowerCase().includes('credit') ||
			error.message.toLowerCase().includes('billing'))
	) {
		return (
			<Button
				variant="link"
				href={SETTINGS_PAGE_URL}
				target="_blank"
				className="ai-feedback-error-action"
			>
				{__('Go to Settings', 'ai-feedback')}
			</Button>
		);
	}

	// Check for rate limit errors
	if (error.code === 'rate_limit_exceeded') {
		return (
			<p className="ai-feedback-error-help">
				{__(
					'Please wait before making another request.',
					'ai-feedback'
				)}
			</p>
		);
	}

	return null;
}

/**
 * AI Feedback Panel component.
 *
 * @return {JSX.Element} Panel component.
 */
export default function AIFeedbackPanel() {
	const { error, lastReview, isLoadingSettings } = useSelect(
		(select) => ({
			error: select(STORE_NAME).getError(),
			lastReview: select(STORE_NAME).getLastReview(),
			isLoadingSettings: select(STORE_NAME).isLoadingSettings(),
		}),
		[]
	);

	const { clearError } = useDispatch(STORE_NAME);

	if (isLoadingSettings) {
		return (
			<div className="ai-feedback-panel">
				<PanelBody>
					<p>{__('Loading…', 'ai-feedback')}</p>
				</PanelBody>
			</div>
		);
	}

	return (
		<div className="ai-feedback-panel">
			{error && (
				<Notice
					status="error"
					isDismissible={true}
					onRemove={clearError}
					className="ai-feedback-error-notice"
				>
					<div className="ai-feedback-error-content">
						<div className="ai-feedback-error-message">
							{error.message}
						</div>
						{error.code && (
							<div className="ai-feedback-error-code">
								{__('Error code:', 'ai-feedback')} {error.code}
							</div>
						)}
						{getErrorAction(error)}
					</div>
				</Notice>
			)}

			<PanelBody
				title={__('Review Settings', 'ai-feedback')}
				initialOpen={true}
			>
				<ModelSelector />
				<SettingsPanel />
			</PanelBody>

			<PanelBody
				title={__('Review Document', 'ai-feedback')}
				initialOpen={true}
			>
				<ReviewButton />
			</PanelBody>

			{lastReview && (
				<PanelBody
					title={__('Last Review', 'ai-feedback')}
					initialOpen={true}
				>
					<ReviewSummary review={lastReview} />
				</PanelBody>
			)}
		</div>
	);
}
