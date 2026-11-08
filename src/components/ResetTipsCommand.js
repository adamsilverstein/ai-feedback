/**
 * Reset Tips Command
 *
 * Registers a Command Palette entry that brings back the welcome modal
 * and all progressive tips, which is handy for testing and demos.
 */
import { useCommand } from '@wordpress/commands';
import { useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import { store as preferencesStore } from '@wordpress/preferences';
import { commentAuthorAvatar } from '@wordpress/icons';
import { PREFERENCES_SCOPE, PREFERENCE_DEFAULTS } from '../tips';

/**
 * Reset Tips Command component.
 *
 * @return {null} Renders nothing.
 */
export default function ResetTipsCommand() {
	const { set } = useDispatch(preferencesStore);
	const { createInfoNotice } = useDispatch(noticesStore);

	useCommand({
		name: 'ai-feedback/reset-tips',
		label: __('Reset AI Feedback tips', 'ai-feedback'),
		icon: commentAuthorAvatar,
		callback: ({ close }) => {
			Object.entries(PREFERENCE_DEFAULTS).forEach(([key, value]) => {
				set(PREFERENCES_SCOPE, key, value);
			});
			createInfoNotice(
				__('AI Feedback tips and welcome screen reset.', 'ai-feedback'),
				{ type: 'snackbar' }
			);
			close();
		},
	});

	return null;
}
