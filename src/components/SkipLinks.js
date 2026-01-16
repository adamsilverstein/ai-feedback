/**
 * Skip Links Component
 *
 * Provides keyboard navigation shortcuts for accessibility.
 */
import { __ } from '@wordpress/i18n';

/**
 * Skip Links component for keyboard navigation.
 *
 * @param {Object}  props            Component props.
 * @param {boolean} props.hasResults Whether review results are available.
 * @param {boolean} props.showModel  Whether model selector is visible.
 * @return {JSX.Element} Skip links component.
 */
export default function SkipLinks( { hasResults, showModel } ) {
	return (
		<nav
			className="ai-feedback-skip-links"
			aria-label={ __( 'Skip links', 'ai-feedback' ) }
		>
			{ showModel && (
				<a href="#ai-feedback-model-select" className="skip-link">
					{ __( 'Skip to model selection', 'ai-feedback' ) }
				</a>
			) }
			<a href="#ai-feedback-review-button" className="skip-link">
				{ __( 'Skip to review button', 'ai-feedback' ) }
			</a>
			{ hasResults && (
				<a href="#ai-feedback-results" className="skip-link">
					{ __( 'Skip to review results', 'ai-feedback' ) }
				</a>
			) }
		</nav>
	);
}
