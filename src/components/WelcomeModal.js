/**
 * Welcome Modal Component
 *
 * Displays a welcome modal on first use to introduce the plugin
 * and guide users through basic functionality.
 */
import { Modal, Button, Icon } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { check, pencil, commentContent } from '@wordpress/icons';

const STORAGE_KEY = 'ai-feedback-welcomed';

/**
 * WelcomeModal component shown on first sidebar open.
 *
 * @return {JSX.Element|null} WelcomeModal component or null if already welcomed.
 */
export default function WelcomeModal() {
	const [isOpen, setIsOpen] = useState(false);

	useEffect(() => {
		const welcomed = window.localStorage.getItem(STORAGE_KEY);
		if (!welcomed) {
			setIsOpen(true);
		}
	}, []);

	const dismiss = () => {
		try {
			window.localStorage.setItem(STORAGE_KEY, 'true');
		} catch (error) {
			// Storage may be unavailable (e.g., private browsing, quota exceeded)
			// eslint-disable-next-line no-console
			console.warn('AI Feedback: Could not save welcome state', error);
		}
		setIsOpen(false);
	};

	if (!isOpen) {
		return null;
	}

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
