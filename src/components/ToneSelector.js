/**
 * Tone Selector Component
 */
import { SelectControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store';

/**
 * Help text for each tone option.
 */
const TONE_HELP = {
	professional: __(
		'Clear and authoritative. Best for business content and documentation.',
		'ai-feedback'
	),
	casual: __(
		'Conversational and friendly. Best for blogs and social media.',
		'ai-feedback'
	),
	academic: __(
		'Scholarly and precise. Best for research and educational content.',
		'ai-feedback'
	),
	friendly: __(
		'Warm and engaging. Best for community and customer content.',
		'ai-feedback'
	),
};

/**
 * Tone Selector component.
 *
 * @return {Element} Tone selector component.
 */
export default function ToneSelector() {
	const { availableTones, targetTone } = useSelect(
		(select) => ({
			availableTones: select(STORE_NAME).getAvailableTones(),
			targetTone: select(STORE_NAME).getTargetTone(),
		}),
		[]
	);

	const { updateSettings } = useDispatch(STORE_NAME);
	const [draftTone, setDraftTone] = useState(targetTone);

	useEffect(() => {
		setDraftTone(targetTone);
	}, [targetTone]);

	if (!availableTones || availableTones.length === 0) {
		return null;
	}

	const options = availableTones.map((tone) => ({
		label: tone.label,
		value: tone.id,
	}));

	const handleChange = (value) => {
		setDraftTone(value);
		updateSettings({
			default_tone: value,
		});
	};

	const toneDescription = availableTones.find(
		(tone) => tone.id === draftTone
	)?.description;

	return (
		<SelectControl
			label={__('Target Tone', 'ai-feedback')}
			value={draftTone}
			options={options}
			onChange={handleChange}
			help={
				TONE_HELP[draftTone] ||
				toneDescription ||
				__(
					'The tone the AI will evaluate your content against.',
					'ai-feedback'
				)
			}
		/>
	);
}
