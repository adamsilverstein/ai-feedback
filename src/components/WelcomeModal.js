/**
 * Welcome Modal Component
 *
 * Displays a welcome modal on first use to introduce the plugin
 * and guide users through basic functionality.
 */
import { Modal, Button, Icon, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { check, pencil, commentContent } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';
import { getConnectorsUrl } from '../utils/connectors-url';

const STORAGE_KEY = 'ai-feedback-welcomed';

/**
 * WelcomeModal component shown on first sidebar open.
 *
 * @return {Element|null} WelcomeModal component or null if already welcomed.
 */
export default function WelcomeModal() {
	const [isOpen, setIsOpen] = useState(false);
	const [isLoading, setIsLoading] = useState(true);
	const [status, setStatus] = useState(null);
	const [statusError, setStatusError] = useState(null);

	useEffect(() => {
		// Check if already welcomed first.
		try {
			const welcomed = window.localStorage.getItem(STORAGE_KEY);
			if (welcomed) {
				setIsLoading(false);
				return;
			}
		} catch {
			// Ignore localStorage errors, continue with status check.
		}

		// Check AI client + connector status before showing modal.
		apiFetch({ path: '/ai-feedback/v1/status' })
			.then((response) => {
				setStatus(response);
				setIsOpen(true);
				setIsLoading(false);
			})
			.catch((error) => {
				setStatusError(
					error.message ||
						__('Failed to check AI client status', 'ai-feedback')
				);
				setIsOpen(true);
				setIsLoading(false);
			});
	}, []);

	const aiClientAvailable = status?.ai_client_available === true;
	const connectorConfigured = status?.connector_configured === true;
	const setupRequired = !aiClientAvailable || !connectorConfigured;
	const settingsUrl = status?.settings_url || getConnectorsUrl();

	const dismiss = () => {
		// Only save welcomed state when the plugin is fully configured.
		if (!setupRequired) {
			try {
				window.localStorage.setItem(STORAGE_KEY, 'true');
			} catch (error) {
				// Storage may be unavailable (e.g., private browsing, quota exceeded)
				// eslint-disable-next-line no-console
				console.warn(
					'AI Feedback: Could not save welcome state',
					error
				);
			}
		}
		setIsOpen(false);
	};

	// Don't render while loading.
	if (isLoading) {
		return null;
	}

	if (!isOpen) {
		return null;
	}

	// Show error state if status check failed.
	if (statusError) {
		return (
			<Modal
				title={__('Welcome to AI Feedback', 'ai-feedback')}
				onRequestClose={() => setIsOpen(false)}
				className="ai-feedback-welcome-modal"
			>
				<Notice status="error" isDismissible={false}>
					{statusError}
				</Notice>
				<div className="ai-feedback-welcome-actions">
					<Button
						variant="secondary"
						onClick={() => setIsOpen(false)}
					>
						{__('Close', 'ai-feedback')}
					</Button>
				</div>
			</Modal>
		);
	}

	// Show configuration required message if AI client is unavailable or no
	// AI provider connector is configured yet.
	if (setupRequired) {
		const message = !aiClientAvailable
			? __(
					'AI Feedback requires the WordPress 7.0 core AI Client. Update WordPress to continue.',
					'ai-feedback'
				)
			: __(
					'AI Feedback uses the WordPress core AI Client. Configure an AI provider in Settings → Connectors before continuing.',
					'ai-feedback'
				);
		return (
			<Modal
				title={__('AI Feedback Setup Required', 'ai-feedback')}
				onRequestClose={() => setIsOpen(false)}
				className="ai-feedback-welcome-modal"
			>
				<div className="ai-feedback-welcome">
					<Notice status="warning" isDismissible={false}>
						<p>{message}</p>
					</Notice>

					<div className="ai-feedback-welcome-prereq">
						<h3>{__('To get started:', 'ai-feedback')}</h3>
						<ol className="ai-feedback-welcome-steps">
							<li>
								<Icon icon={pencil} />
								<span>
									{__(
										'Open Settings → Connectors in WordPress admin',
										'ai-feedback'
									)}
								</span>
							</li>
							<li>
								<Icon icon={check} />
								<span>
									{__(
										'Configure an AI provider (Anthropic, OpenAI, or Google)',
										'ai-feedback'
									)}
								</span>
							</li>
							<li>
								<Icon icon={commentContent} />
								<span>
									{__(
										'Return here to start using AI Feedback',
										'ai-feedback'
									)}
								</span>
							</li>
						</ol>
					</div>

					<div className="ai-feedback-welcome-actions">
						<Button
							variant="primary"
							href={settingsUrl}
							target="_blank"
							rel="noopener noreferrer"
						>
							{__('Open Connectors Settings', 'ai-feedback')}
						</Button>
						<Button
							variant="secondary"
							onClick={() => setIsOpen(false)}
						>
							{__('Close', 'ai-feedback')}
						</Button>
					</div>
				</div>
			</Modal>
		);
	}

	// Normal welcome modal when AI client is available.
	return (
		<Modal
			title={__('Welcome to AI Feedback', 'ai-feedback')}
			onRequestClose={dismiss}
			className="ai-feedback-welcome-modal"
		>
			<div className="ai-feedback-welcome">
				<p className="ai-feedback-welcome-intro">
					{__(
						'Get AI-powered editorial feedback on your content right in the editor.',
						'ai-feedback'
					)}
				</p>

				<h3>{__('Get started in 3 steps:', 'ai-feedback')}</h3>

				<ul className="ai-feedback-welcome-steps">
					<li>
						<Icon icon={pencil} />
						<span>
							{__(
								'Write your content in the editor',
								'ai-feedback'
							)}
						</span>
					</li>
					<li>
						<Icon icon={check} />
						<span>
							{__('Save your post as a draft', 'ai-feedback')}
						</span>
					</li>
					<li>
						<Icon icon={commentContent} />
						<span>
							{__(
								'Click "Review Document" to get AI feedback',
								'ai-feedback'
							)}
						</span>
					</li>
				</ul>

				<div className="ai-feedback-welcome-actions">
					<Button variant="primary" onClick={dismiss}>
						{__('Get Started', 'ai-feedback')}
					</Button>
					<Button
						variant="link"
						href="https://github.com/adamsilverstein/ai-feedback#readme"
						target="_blank"
						rel="noopener noreferrer"
					>
						{__('Learn More', 'ai-feedback')}
					</Button>
				</div>
			</div>
		</Modal>
	);
}
