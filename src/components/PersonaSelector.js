/**
 * Persona Selector Component
 *
 * Picks the reader a review runs as, and who the document is for.
 */
import { RadioControl, TextControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store';

/**
 * Longest audience the server keeps, matching Personas::MAX_AUDIENCE_LENGTH.
 */
export const MAX_AUDIENCE_LENGTH = 100;

/**
 * Persona Selector component.
 *
 * @return {Element|null} Persona selector, or null before personas load.
 */
export default function PersonaSelector() {
	const { availablePersonas, persona, audience } = useSelect(
		(select) => ({
			availablePersonas: select(STORE_NAME).getAvailablePersonas(),
			persona: select(STORE_NAME).getPersona(),
			audience: select(STORE_NAME).getReviewAudience(),
		}),
		[]
	);

	const { updateSettings, setReviewAudience } = useDispatch(STORE_NAME);
	const [draftPersona, setDraftPersona] = useState(persona ?? '');
	// The audience when the field gained focus, to skip saving it unchanged.
	const audienceOnFocus = useRef(null);

	useEffect(() => {
		setDraftPersona(persona ?? '');
	}, [persona]);

	if (!availablePersonas || availablePersonas.length === 0) {
		return null;
	}

	const handlePersonaChange = (value) => {
		setDraftPersona(value);
		// Roll back to the saved persona if saving fails; the store shows
		// the error.
		Promise.resolve(updateSettings({ default_persona: value })).catch(
			() => {
				setDraftPersona((current) =>
					current === value ? (persona ?? '') : current
				);
			}
		);
	};

	// The store follows every keystroke so a review started straight away
	// uses it; the preference is saved once the field loses focus.
	const saveAudience = () => {
		const value = (audience ?? '').trim().slice(0, MAX_AUDIENCE_LENGTH);
		if (value === audienceOnFocus.current) {
			return;
		}
		audienceOnFocus.current = value;
		// The store shows the error if saving fails.
		Promise.resolve(updateSettings({ review_audience: value })).catch(
			() => {}
		);
	};

	return (
		<div className="ai-feedback-persona-selector">
			<RadioControl
				label={__('Read it as', 'fresh-eyes')}
				selected={draftPersona}
				options={availablePersonas.map((item) => ({
					label: item.label,
					value: item.id,
					description: item.description || undefined,
				}))}
				onChange={handlePersonaChange}
			/>
			<TextControl
				__next40pxDefaultSize
				label={__('Who is this for? (optional)', 'fresh-eyes')}
				value={audience ?? ''}
				maxLength={MAX_AUDIENCE_LENGTH}
				placeholder={__('e.g. plugin developers', 'fresh-eyes')}
				help={__(
					'The review checks whether the post works for these readers.',
					'fresh-eyes'
				)}
				onChange={setReviewAudience}
				onFocus={() => {
					audienceOnFocus.current = (audience ?? '').trim();
				}}
				onBlur={saveAudience}
			/>
		</div>
	);
}
