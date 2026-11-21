/**
 * RememberRule component.
 *
 * Shown under an AI reply that offers to remember a stated preference as a
 * house style rule, and for each rule suggested from published posts in
 * the House style settings. The whole rule is shown, since the AI wrote it
 * and it may say more than the author did. The author can edit it before
 * saving; nothing is saved until they click Remember, and the confirmation
 * shows the rule as saved.
 */
import { Button, SelectControl } from '@wordpress/components';
import { speak } from '@wordpress/a11y';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import useHouseStyle from '../hooks/useHouseStyle';
import RuleTextarea from './RuleTextarea';
import {
	SCOPE_SITE,
	SCOPE_USER,
	addRule,
	dismissProposal,
	hasRule,
} from '../utils/house-style';

/**
 * Fallback for the longest rule, until the rules have loaded.
 */
const DEFAULT_MAX_LENGTH = 150;

/**
 * @param {Object} props
 * @param {number} [props.noteId]       AI reply that proposed the rule, when one did.
 * @param {Object} props.proposal       Proposal: { text, status }, status '', 'saved' or 'dismissed'.
 * @param {string} [props.label]        Field label. Defaults to the offer shown in a thread.
 * @param {string} [props.help]         Help text under the field.
 * @param {string} [props.defaultScope] Scope first selected for a user who manages site rules.
 * @return {Element|null} The offer, a saved confirmation, or null once dismissed.
 */
export default function RememberRule({
	noteId,
	proposal,
	label,
	help,
	defaultScope = SCOPE_USER,
}) {
	const { rules } = useHouseStyle();
	const [text, setText] = useState(proposal?.text || '');
	const [scope, setScope] = useState(defaultScope);
	const [status, setStatus] = useState(proposal?.status || '');
	const [savedText, setSavedText] = useState(null);
	const [isBusy, setIsBusy] = useState(false);
	const [error, setError] = useState(null);

	if (!proposal?.text || status === 'dismissed') {
		return null;
	}

	// Saved here, or the same rule was added another way since.
	if (status === 'saved' || (!isBusy && hasRule(rules, proposal.text))) {
		return (
			<p className="ai-feedback-remember is-saved">
				{savedText
					? sprintf(
							/* translators: %s: house style rule. */
							__('Saved to house style: “%s”', 'fresh-eyes'),
							savedText
						)
					: __('Saved to house style.', 'fresh-eyes')}
			</p>
		);
	}

	const canManageSite = !!rules?.can_manage_site;

	const save = () => {
		setIsBusy(true);
		setError(null);
		addRule({
			text,
			scope: canManageSite ? scope : SCOPE_USER,
			noteId,
		})
			.then((state) => {
				setSavedText(state?.rule?.text || null);
				setStatus('saved');
				speak(__('Saved to house style.', 'fresh-eyes'));
			})
			.catch((requestError) =>
				setError(
					requestError?.message ||
						__('The rule could not be saved.', 'fresh-eyes')
				)
			)
			.finally(() => setIsBusy(false));
	};

	const dismiss = () => {
		setStatus('dismissed');
		// Hidden either way; the server only keeps a note's from coming back.
		if (noteId) {
			dismissProposal(noteId).catch(() => {});
		}
	};

	return (
		<div className="ai-feedback-remember">
			<RuleTextarea
				label={
					label ||
					__('Remember this for future reviews', 'fresh-eyes')
				}
				help={
					help ||
					__(
						'Saved as a house style rule. You can edit it first.',
						'fresh-eyes'
					)
				}
				value={text}
				onChange={setText}
				maxLength={rules?.max_length || DEFAULT_MAX_LENGTH}
			/>
			{canManageSite && (
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={__('Applies to', 'fresh-eyes')}
					value={scope}
					onChange={setScope}
					options={[
						{
							label: __('Just me', 'fresh-eyes'),
							value: SCOPE_USER,
						},
						{
							label: __('Everyone on this site', 'fresh-eyes'),
							value: SCOPE_SITE,
						},
					]}
				/>
			)}
			{error && (
				<p className="ai-feedback-remember-error" role="alert">
					{error}
				</p>
			)}
			<div className="ai-feedback-remember-actions">
				<Button
					variant="secondary"
					size="small"
					isBusy={isBusy}
					disabled={isBusy || !text.trim()}
					accessibleWhenDisabled
					onClick={save}
				>
					{__('Remember', 'fresh-eyes')}
				</Button>
				<Button variant="tertiary" size="small" onClick={dismiss}>
					{__('Not now', 'fresh-eyes')}
				</Button>
			</div>
		</div>
	);
}
