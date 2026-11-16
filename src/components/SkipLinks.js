/**
 * Skip Links Component
 *
 * Provides keyboard navigation shortcuts for accessibility.
 */
import { __ } from '@wordpress/i18n';

/**
 * Handle skip link click to ensure focus moves to target element.
 *
 * @param {Event} e Click event.
 */
function handleSkipClick(e) {
	e.preventDefault();
	const targetId = e.currentTarget.getAttribute('href').slice(1);
	const target = document.getElementById(targetId);

	if (target) {
		// Make the target focusable if it isn't already
		target.setAttribute('tabindex', '-1');
		target.focus();

		// Remove tabindex after blur to maintain normal tab order
		target.addEventListener(
			'blur',
			() => {
				target.removeAttribute('tabindex');
			},
			{ once: true }
		);
	}
}

/**
 * Skip Links component for keyboard navigation.
 *
 * @param {Object}  props                  Component props.
 * @param {boolean} props.hasResults       Whether review results are available.
 * @param {boolean} props.showModel        Whether model selector is visible.
 * @param {boolean} props.hasFeedbackItems Whether individual feedback items are available.
 * @return {Element} Skip links component.
 */
export default function SkipLinks({ hasResults, showModel, hasFeedbackItems }) {
	return (
		<nav
			className="ai-feedback-skip-links"
			aria-label={__('Skip links', 'copydesk-ai')}
		>
			{showModel && (
				<a
					href="#ai-feedback-model-select"
					className="ai-feedback-skip-link"
					onClick={handleSkipClick}
				>
					{__('Skip to model selection', 'copydesk-ai')}
				</a>
			)}
			<a
				href="#ai-feedback-settings"
				className="ai-feedback-skip-link"
				onClick={handleSkipClick}
			>
				{__('Skip to review settings', 'copydesk-ai')}
			</a>
			<a
				href="#ai-feedback-review-button"
				className="ai-feedback-skip-link"
				onClick={handleSkipClick}
			>
				{__('Skip to review button', 'copydesk-ai')}
			</a>
			{hasResults && (
				<a
					href="#ai-feedback-results"
					className="ai-feedback-skip-link"
					onClick={handleSkipClick}
				>
					{__('Skip to review results', 'copydesk-ai')}
				</a>
			)}
			{hasFeedbackItems && (
				<a
					href="#ai-feedback-items"
					className="ai-feedback-skip-link"
					onClick={handleSkipClick}
				>
					{__('Skip to feedback items', 'copydesk-ai')}
				</a>
			)}
		</nav>
	);
}
