/**
 * Image Sharing Component
 */
import { CheckboxControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store';

/**
 * The site setting for sending a post's images to the AI provider, shown
 * to users who can change it.
 *
 * @return {Element|null} The setting, or null for other users.
 */
export default function ImageSharing() {
	const { sendImages, canManageSite } = useSelect(
		(select) => ({
			sendImages: select(STORE_NAME).getSendImages(),
			canManageSite: select(STORE_NAME).canManageSite(),
		}),
		[]
	);

	const { updateSettings } = useDispatch(STORE_NAME);
	const [draft, setDraft] = useState(sendImages);

	useEffect(() => {
		setDraft(sendImages);
	}, [sendImages]);

	if (!canManageSite) {
		return null;
	}

	const handleChange = (checked) => {
		setDraft(checked);
		// Go back to the saved value if saving fails; the store surfaces
		// the error notice.
		Promise.resolve(updateSettings({ send_images: checked })).catch(() =>
			setDraft(sendImages)
		);
	};

	return (
		<fieldset className="ai-feedback-focus-areas ai-feedback-image-sharing">
			<legend>{__('Images', 'fresh-eyes')}</legend>
			<CheckboxControl
				label={__('Send images to the AI provider', 'fresh-eyes')}
				checked={draft}
				onChange={handleChange}
				help={__(
					'Lets the AI see images that have no alt text, so it can suggest some. Applies to everyone on this site.',
					'fresh-eyes'
				)}
			/>
		</fieldset>
	);
}
