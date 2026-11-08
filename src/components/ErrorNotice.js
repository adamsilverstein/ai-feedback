/**
 * Error Notice Component
 */
import { Notice, Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { getConnectorsUrl } from '../utils/connectors-url';
import { getErrorKind } from '../utils/error-kind';

/**
 * Get action button for specific error types.
 *
 * @param {Object} error Error object with code, message, and data.
 * @return {Element|null} Action button or null.
 */
function getErrorAction(error) {
	const kind = getErrorKind(error);

	if (kind === 'credentials') {
		return (
			<Button
				variant="link"
				href={getConnectorsUrl()}
				target="_blank"
				rel="noopener noreferrer"
				className="ai-feedback-error-action"
			>
				{__('Open Connectors Settings', 'ai-feedback')}
			</Button>
		);
	}

	if (kind === 'rate_limit') {
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
 * Review error notice.
 *
 * The notice is the only place the error is announced, so screen readers
 * hear one message that says it is an error.
 *
 * @param {Object}   props          Component props.
 * @param {Object}   props.error    Error object with code, message, and data.
 * @param {Function} props.onRemove Called when the notice is dismissed.
 * @return {Element} Error notice.
 */
export default function ErrorNotice({ error, onRemove }) {
	return (
		<Notice
			status="error"
			isDismissible={true}
			onRemove={onRemove}
			className="ai-feedback-error-notice"
			spokenMessage={sprintf(
				/* translators: %s: error message */
				__('Error: %s', 'ai-feedback'),
				error.message
			)}
		>
			<div className="ai-feedback-error-content">
				<div className="ai-feedback-error-message">{error.message}</div>
				{error.code && (
					<div className="ai-feedback-error-code">
						{__('Error code:', 'ai-feedback')} {error.code}
					</div>
				)}
				{getErrorAction(error)}
			</div>
		</Notice>
	);
}
