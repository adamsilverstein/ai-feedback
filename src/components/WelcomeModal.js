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

const STORAGE_KEY = 'ai-feedback-welcomed';
const AI_SETTINGS_URL = '/wp-admin/options-general.php?page=wp-ai-client';

/**
 * WelcomeModal component shown on first sidebar open.
 *
 * @return {JSX.Element|null} WelcomeModal component or null if already welcomed.
 */
export default function WelcomeModal() {
	const [isOpen, setIsOpen] = useState(false);
	const [isLoading, setIsLoading] = useState(true);
	const [aiClientAvailable, setAiClientAvailable] = useState(null);
	const [statusError, setStatusError] = useState(null);
	const [settingsUrl, setSettingsUrl] = useState(AI_SETTINGS_URL);

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

		// Check AI client status before showing modal.
		apiFetch({ path: '/ai-feedback/v1/status' })
			.then((response) => {
				setAiClientAvailable(response.ai_client_available);
				if (response.settings_url) {
					setSettingsUrl(response.settings_url);
				}
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

	const dismiss = () => {
		// Only save welcomed state if AI client is available.
		if (aiClientAvailable) {
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

	// Show configuration required message if AI client is not available.
	if (!aiClientAvailable) {
		return (
			<Modal
				title={__('AI Feedback Setup Required', 'ai-feedback')}
				onRequestClose={() => setIsOpen(false)}
				className="ai-feedback-welcome-modal"
			>
				<div className="ai-feedback-welcome">
					<Notice status="warning" isDismissible={false}>
						<p>
							{__(
								'The WordPress AI Experiments plugin must be installed and configured before using AI Feedback.',
								'ai-feedback'
							)}
						</p>
					</Notice>

					<div className="ai-feedback-welcome-prereq">
						<h3>{__('To get started:', 'ai-feedback')}</h3>
						<ol className="ai-feedback-welcome-steps">
							<li>
								<Icon icon={pencil} />
								<span>
									{__(
										'Install the WordPress AI Experiments plugin',
										'ai-feedback'
									)}
								</span>
							</li>
							<li>
								<Icon icon={check} />
								<span>
									{__(
										'Configure an AI provider (OpenAI, Anthropic, or Google)',
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
							{__('Configure AI Settings', 'ai-feedback')}
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
