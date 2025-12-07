/**
 * Settings Panel Component
 */
import { CheckboxControl, SelectControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store';

/**
 * Settings Panel component.
 *
 * @return {JSX.Element} Settings panel component.
 */
export default function SettingsPanel() {
	const { availableFocusAreas, focusAreas, availableTones, targetTone } =
		useSelect(
			( select ) => ( {
				availableFocusAreas:
					select( STORE_NAME ).getAvailableFocusAreas(),
				focusAreas: select( STORE_NAME ).getFocusAreas(),
				availableTones: select( STORE_NAME ).getAvailableTones(),
				targetTone: select( STORE_NAME ).getTargetTone(),
			} ),
			[]
		);

	const { updateSettings } = useDispatch( STORE_NAME );

	const handleFocusAreaChange = ( areaId, checked ) => {
		const newFocusAreas = checked
			? [ ...focusAreas, areaId ]
			: focusAreas.filter( ( id ) => id !== areaId );

		updateSettings( {
			default_focus_areas: newFocusAreas,
		} );
	};

	const handleToneChange = ( value ) => {
		updateSettings( {
			default_tone: value,
		} );
	};

	return (
		<div className="ai-feedback-settings">
			<fieldset>
				<legend>{ __( 'Focus Areas', 'ai-feedback' ) }</legend>
				<p className="description">
					{ __(
						'Select the areas you want AI to focus on when reviewing your content',
						'ai-feedback'
					) }
				</p>
				{ availableFocusAreas.map( ( area ) => (
					<CheckboxControl
						key={ area.id }
						label={ area.label }
						help={ area.description }
						checked={ focusAreas.includes( area.id ) }
						onChange={ ( checked ) =>
							handleFocusAreaChange( area.id, checked )
						}
					/>
				) ) }
			</fieldset>

			<SelectControl
				label={ __( 'Target Tone', 'ai-feedback' ) }
				value={ targetTone }
				options={ availableTones.map( ( tone ) => ( {
					label: tone.label,
					value: tone.id,
				} ) ) }
				onChange={ handleToneChange }
				help={ __(
					'The desired tone for your content',
					'ai-feedback'
				) }
			/>
		</div>
	);
}
