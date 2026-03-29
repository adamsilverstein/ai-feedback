/**
 * Language Selector Component
 */
import { SelectControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store';

/**
 * Language Selector component.
 *
 * @return {JSX.Element} Language selector component.
 */
export default function LanguageSelector() {
	const { availableLocales, feedbackLocale } = useSelect(
		(select) => ({
			availableLocales: select(STORE_NAME).getAvailableLocales(),
			feedbackLocale: select(STORE_NAME).getFeedbackLocale(),
		}),
		[]
	);

	const { updateSettings } = useDispatch(STORE_NAME);

	// Create options from available locales
	const options = availableLocales.map((locale) => ({
		label: locale.label,
		value: locale.id,
	}));

	const handleChange = (value) => {
		updateSettings({
			feedback_locale: value,
		});
	};

	return (
		<SelectControl
			label={__('Feedback Language', 'ai-feedback')}
			value={feedbackLocale}
			options={options}
			onChange={handleChange}
			help={__('Language for AI feedback responses', 'ai-feedback')}
		/>
	);
}
