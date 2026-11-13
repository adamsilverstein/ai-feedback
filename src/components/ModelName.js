/**
 * Model name.
 */
import { useSelect } from '@wordpress/data';

/**
 * Shows a model's display name in place of its ID.
 *
 * @param {Object} props    Component props.
 * @param {string} props.id Model ID.
 * @return {string} Model name, or the ID when the model is not listed.
 */
export default function ModelName({ id }) {
	return useSelect(
		(select) => select('ai-feedback/store')?.getModelName?.(id) || id,
		[id]
	);
}
