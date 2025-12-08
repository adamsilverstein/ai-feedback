/**
 * AI Feedback Plugin Entry Point
 */
import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { commentAuthorAvatar as icon } from '@wordpress/icons';

import './store';
import AIFeedbackPanel from './components/AIFeedbackPanel';
import './index.scss';

/**
 * Register the AI Feedback plugin.
 */
registerPlugin( 'ai-feedback', {
	render: () => {
		return (
			<>
				<PluginSidebarMoreMenuItem target="ai-feedback-sidebar" icon={ icon }>
					{ __( 'AI Feedback', 'ai-feedback' ) }
				</PluginSidebarMoreMenuItem>
				<PluginSidebar
					name="ai-feedback-sidebar"
					title={ __( 'AI Feedback', 'ai-feedback' ) }
					icon={ icon }
				>
					<AIFeedbackPanel />
				</PluginSidebar>
			</>
		);
	},
} );
