/**
 * AI Feedback Plugin Entry Point
 */
import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { commentAuthorAvatar as icon } from '@wordpress/icons';
import { dispatch } from '@wordpress/data';
import { addFilter } from '@wordpress/hooks';
import { store as preferencesStore } from '@wordpress/preferences';

import './store';
import AIFeedbackPanel from './components/AIFeedbackPanel';
import KeyboardShortcuts from './components/KeyboardShortcuts';
import NoteRelinker from './components/NoteRelinker';
import ReplyWatcher from './components/ReplyWatcher';
import ResetTipsCommand from './components/ResetTipsCommand';
import { PREFERENCES_SCOPE, PREFERENCE_DEFAULTS } from './tips';
import { hideFreshEyesUser } from './utils/user-completer';
import './index.scss';

dispatch(preferencesStore).setDefaults(PREFERENCES_SCOPE, PREFERENCE_DEFAULTS);

// After the editor adds its default completers.
addFilter(
	'editor.Autocomplete.completers',
	'fresh-eyes/hide-user',
	hideFreshEyesUser,
	20
);

/**
 * Register the AI Feedback plugin.
 */
registerPlugin('ai-feedback', {
	render: () => {
		return (
			<>
				<KeyboardShortcuts />
				<NoteRelinker />
				<ReplyWatcher />
				<ResetTipsCommand />
				<PluginSidebarMoreMenuItem
					target="ai-feedback-sidebar"
					icon={icon}
				>
					{__('Fresh Eyes', 'fresh-eyes')}
				</PluginSidebarMoreMenuItem>
				<PluginSidebar
					name="ai-feedback-sidebar"
					title={__('Fresh Eyes', 'fresh-eyes')}
					icon={icon}
				>
					<AIFeedbackPanel />
				</PluginSidebar>
			</>
		);
	},
});
