/**
 * Focus Area Selector Component
 */
import { CheckboxControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store';

/**
 * Help text for each focus area explaining what it checks.
 */
const FOCUS_AREA_HELP = {
	content: __(
		'Checks grammar, spelling, clarity, and factual accuracy.',
		'ai-feedback'
	),
	tone: __(
		'Analyzes voice consistency, formality level, and audience fit.',
		'ai-feedback'
	),
	flow: __(
		'Reviews logical progression, transitions, and paragraph structure.',
		'ai-feedback'
	),
	design: __(
		'Evaluates formatting, headings, lists, and visual hierarchy.',
		'ai-feedback'
	),
};

/**
 * Focus Area Selector component.
 *
 * @return {Element} Focus area selector component.
 */
export default function FocusAreaSelector() {
	const { availableFocusAreas, focusAreas } = useSelect(
		(select) => ({
			availableFocusAreas: select(STORE_NAME).getAvailableFocusAreas(),
			focusAreas: select(STORE_NAME).getFocusAreas(),
		}),
		[]
	);

	const { updateSettings } = useDispatch(STORE_NAME);
	const selectedAreas = Array.isArray(focusAreas) ? focusAreas : [];
	const [draftAreas, setDraftAreas] = useState(selectedAreas);

	useEffect(() => {
		setDraftAreas(Array.isArray(focusAreas) ? focusAreas : []);
	}, [focusAreas]);

	const handleChange = (areaId, checked) => {
		const base = Array.isArray(draftAreas) ? draftAreas : [];
		const updated = checked
			? Array.from(new Set([...base, areaId]))
			: base.filter((id) => id !== areaId);

		setDraftAreas(updated);
		updateSettings({
			default_focus_areas: updated,
		});
	};

	if (!availableFocusAreas || availableFocusAreas.length === 0) {
		return null;
	}

	return (
		<fieldset className="ai-feedback-focus-areas">
			<legend>{__('Focus Areas', 'ai-feedback')}</legend>
			<p className="description">
				{__(
					'Select which aspects of your content to review.',
					'ai-feedback'
				)}
			</p>
			{availableFocusAreas.map((area) => (
				<CheckboxControl
					key={area.id}
					label={area.label}
					checked={draftAreas.includes(area.id)}
					onChange={(checked) => handleChange(area.id, checked)}
					help={FOCUS_AREA_HELP[area.id] || area.description}
				/>
			))}
		</fieldset>
	);
}
