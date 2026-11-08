/**
 * Focus Area Selector Component
 */
import { CheckboxControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store';

/**
 * Help text for each focus area explaining what it checks.
 *
 * Built at render time rather than module load so translations are applied.
 *
 * @return {Object} Help text keyed by focus area id.
 */
const getFocusAreaHelp = () => ({
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
});

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

	// Latest draft, read by handleChange so toggles that land in the same
	// render batch build on each other instead of on a stale closure.
	const draftRef = useRef(selectedAreas);
	// Last saved value, used to roll back a failed save.
	const savedRef = useRef(selectedAreas);

	useEffect(() => {
		const synced = Array.isArray(focusAreas) ? focusAreas : [];
		draftRef.current = synced;
		savedRef.current = synced;
		setDraftAreas(synced);
	}, [focusAreas]);

	const setDraft = (areas) => {
		draftRef.current = areas;
		setDraftAreas(areas);
	};

	const handleChange = (areaId, checked) => {
		const updated = checked
			? Array.from(new Set([...draftRef.current, areaId]))
			: draftRef.current.filter((id) => id !== areaId);

		// A review needs at least one focus area.
		if (updated.length === 0) {
			return;
		}

		setDraft(updated);
		// Roll back to the last saved value if saving fails; the store
		// surfaces the error notice.
		Promise.resolve(
			updateSettings({
				default_focus_areas: updated,
			})
		).catch(() => {
			if (draftRef.current === updated) {
				setDraft(savedRef.current);
			}
		});
	};

	if (!availableFocusAreas || availableFocusAreas.length === 0) {
		return null;
	}

	const focusAreaHelp = getFocusAreaHelp();

	return (
		<fieldset className="ai-feedback-focus-areas">
			<legend>{__('Focus Areas', 'ai-feedback')}</legend>
			<p className="description">
				{__(
					'Select which aspects of your content to review. At least one is required.',
					'ai-feedback'
				)}
			</p>
			{availableFocusAreas.map((area) => (
				<CheckboxControl
					key={area.id}
					label={area.label}
					checked={draftAreas.includes(area.id)}
					disabled={
						draftAreas.length === 1 && draftAreas.includes(area.id)
					}
					onChange={(checked) => handleChange(area.id, checked)}
					help={focusAreaHelp[area.id] || area.description}
				/>
			))}
		</fieldset>
	);
}
