/**
 * Settings Panel Component
 */
import { CheckboxControl, SelectControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { useRef, useEffect, useCallback } from '@wordpress/element';
import { STORE_NAME } from '../store';

/**
 * Custom debounce implementation.
 *
 * @param {Function} func  Function to debounce.
 * @param {number}   delay Delay in milliseconds.
 * @return {Function} Debounced function with cancel method.
 */
function debounce(func, delay) {
	let timeoutId;
	const debounced = function (...args) {
		clearTimeout(timeoutId);
		timeoutId = setTimeout(() => func.apply(this, args), delay);
	};
	debounced.cancel = () => clearTimeout(timeoutId);
	return debounced;
}

/**
 * Settings Panel component.
 *
 * @return {JSX.Element} Settings panel component.
 */
export default function SettingsPanel() {
	const { availableFocusAreas, focusAreas, availableTones, targetTone } =
		useSelect(
			(select) => ({
				availableFocusAreas:
					select(STORE_NAME).getAvailableFocusAreas(),
				focusAreas: select(STORE_NAME).getFocusAreas(),
				availableTones: select(STORE_NAME).getAvailableTones(),
				targetTone: select(STORE_NAME).getTargetTone(),
			}),
			[]
		);

	const { updateSettings } = useDispatch(STORE_NAME);

	// Create debounced update function with 500ms delay
	const debouncedUpdateRef = useRef(null);

	// Initialize debounced function and cleanup on unmount
	useEffect(() => {
		debouncedUpdateRef.current = debounce(updateSettings, 500);

		return () => {
			if (debouncedUpdateRef.current) {
				debouncedUpdateRef.current.cancel();
			}
		};
	}, [updateSettings]);

	const handleFocusAreaChange = useCallback(
		(areaId, checked) => {
			const newFocusAreas = checked
				? [...focusAreas, areaId]
				: focusAreas.filter((id) => id !== areaId);

			debouncedUpdateRef.current({
				default_focus_areas: newFocusAreas,
			});
		},
		[focusAreas]
	);

	const handleToneChange = useCallback((value) => {
		debouncedUpdateRef.current({
			default_tone: value,
		});
	}, []);

	return (
		<div className="ai-feedback-settings">
			<fieldset>
				<legend>{__('Focus Areas', 'ai-feedback')}</legend>
				<p className="description">
					{__(
						'Select the areas you want AI to focus on when reviewing your content',
						'ai-feedback'
					)}
				</p>
				{availableFocusAreas.map((area) => (
					<CheckboxControl
						key={area.id}
						label={area.label}
						help={area.description}
						checked={focusAreas.includes(area.id)}
						onChange={(checked) =>
							handleFocusAreaChange(area.id, checked)
						}
					/>
				))}
			</fieldset>

			<SelectControl
				label={__('Target Tone', 'ai-feedback')}
				value={targetTone}
				options={availableTones.map((tone) => ({
					label: tone.label,
					value: tone.id,
				}))}
				onChange={handleToneChange}
				help={__('The desired tone for your content', 'ai-feedback')}
			/>
		</div>
	);
}
