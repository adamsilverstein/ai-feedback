/**
 * AI Feedback Panel Component
 */
import { PanelBody, Notice } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store';

import ModelSelector from './ModelSelector';
import SettingsPanel from './SettingsPanel';
import ReviewButton from './ReviewButton';
import ReviewSummary from './ReviewSummary';

/**
 * AI Feedback Panel component.
 *
 * @return {JSX.Element} Panel component.
 */
export default function AIFeedbackPanel() {
	const { error, lastReview, isLoadingSettings } = useSelect(
		( select ) => ( {
			error: select( STORE_NAME ).getError(),
			lastReview: select( STORE_NAME ).getLastReview(),
			isLoadingSettings: select( STORE_NAME ).isLoadingSettings(),
		} ),
		[]
	);

	if ( isLoadingSettings ) {
		return (
			<div className="ai-feedback-panel">
				<PanelBody>
					<p>{ __( 'Loading...', 'ai-feedback' ) }</p>
				</PanelBody>
			</div>
		);
	}

	return (
		<div className="ai-feedback-panel">
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error.message }
				</Notice>
			) }

			<PanelBody
				title={ __( 'Review Settings', 'ai-feedback' ) }
				initialOpen={ true }
			>
				<ModelSelector />
				<SettingsPanel />
			</PanelBody>

			<PanelBody
				title={ __( 'Review Document', 'ai-feedback' ) }
				initialOpen={ true }
			>
				<ReviewButton />
			</PanelBody>

			{ lastReview && (
				<PanelBody
					title={ __( 'Last Review', 'ai-feedback' ) }
					initialOpen={ true }
				>
					<ReviewSummary review={ lastReview } />
				</PanelBody>
			) }
		</div>
	);
}
