/**
 * AI Feedback Plugin Entry Point
 */
import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { commentAuthorAvatar as icon } from '@wordpress/icons';
import { dispatch } from '@wordpress/data';
import { store as preferencesStore } from '@wordpress/preferences';

import './store';
import AIFeedbackPanel from './components/AIFeedbackPanel';
import ResetTipsCommand from './components/ResetTipsCommand';
import { PREFERENCES_SCOPE, PREFERENCE_DEFAULTS } from './tips';
import './index.scss';

dispatch(preferencesStore).setDefaults(PREFERENCES_SCOPE, PREFERENCE_DEFAULTS);

/**
 * Register the AI Feedback plugin.
 */
registerPlugin('ai-feedback', {
	render: () => {
		return (
			<>
				<ResetTipsCommand />
				<PluginSidebarMoreMenuItem
					target="ai-feedback-sidebar"
					icon={icon}
				>
					{__('AI Feedback', 'ai-feedback')}
				</PluginSidebarMoreMenuItem>
				<PluginSidebar
					name="ai-feedback-sidebar"
					title={__('AI Feedback', 'ai-feedback')}
					icon={icon}
				>
					<AIFeedbackPanel />
				</PluginSidebar>
			</>
		);
	},
});
