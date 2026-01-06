/**
 * Model Selector Component
 */
import { SelectControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store';

/**
 * Model Selector component.
 *
 * @return {JSX.Element} Model selector component.
 */
export default function ModelSelector() {
	const { availableModels, selectedModel } = useSelect(
		(select) => ({
			availableModels: select(STORE_NAME).getAvailableModels(),
			selectedModel: select(STORE_NAME).getSelectedModel(),
		}),
		[]
	);

	const { updateSettings } = useDispatch(STORE_NAME);

	// Group models by provider
	const groupedModels = availableModels.reduce((acc, model) => {
		if (!acc[model.provider]) {
			acc[model.provider] = [];
		}
		acc[model.provider].push(model);
		return acc;
	}, {});

	// Create options with optgroups
	const options = [];
	Object.keys(groupedModels).forEach((provider) => {
		const providerLabel =
			provider.charAt(0).toUpperCase() + provider.slice(1);
		groupedModels[provider].forEach((model) => {
			options.push({
				label: `${providerLabel} - ${model.name}`,
				value: model.id,
			});
		});
	});

	const handleChange = (value) => {
		updateSettings({
			default_model: value,
		});
	};

	return (
		<SelectControl
			label={__('AI Model', 'ai-feedback')}
			value={selectedModel}
			options={options}
			onChange={handleChange}
			help={__(
				'Select the AI model to analyze your content',
				'ai-feedback'
			)}
		/>
	);
}
