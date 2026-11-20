/**
 * House Style Component
 *
 * Lists the short style rules every review and reply follows, in Review
 * Settings. Anyone can keep their own rules; site rules are shown to
 * everyone they apply to and can be changed by users who manage options.
 */
import { Button, Notice, SelectControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import useHouseStyle from '../hooks/useHouseStyle';
import RuleTextarea from './RuleTextarea';
import {
	SCOPE_SITE,
	SCOPE_USER,
	addRule,
	deleteRule,
	updateRule,
} from '../utils/house-style';

/**
 * Message for a failed request.
 *
 * @param {Error|Object} error Error from apiFetch.
 * @return {string} Message.
 */
const errorMessage = (error) =>
	error?.message || __('The rule could not be saved.', 'fresh-eyes');

/**
 * One rule, with Edit and Remove when the user may change it.
 *
 * @param {Object}                         props
 * @param {Object}                         props.rule      Rule: { id, text }.
 * @param {string}                         props.scope     SCOPE_USER or SCOPE_SITE.
 * @param {boolean}                        props.canEdit   Whether the user may change the rule.
 * @param {number}                         props.maxLength Most characters in a rule.
 * @param {(message: string|null) => void} props.onError   Called with an error message.
 * @return {Element} Rule row.
 */
function RuleItem({ rule, scope, canEdit, maxLength, onError }) {
	const [draft, setDraft] = useState(null);
	const [isBusy, setIsBusy] = useState(false);

	const run = (request) => {
		setIsBusy(true);
		onError(null);
		return request()
			.then(() => setDraft(null))
			.catch((error) => onError(errorMessage(error)))
			.finally(() => setIsBusy(false));
	};

	if (draft !== null) {
		return (
			<li className="ai-feedback-house-style-rule is-editing">
				<RuleTextarea
					label={__('Edit rule', 'fresh-eyes')}
					value={draft}
					onChange={setDraft}
					onSubmit={() =>
						!isBusy &&
						draft.trim() &&
						run(() => updateRule(rule.id, draft, scope))
					}
					maxLength={maxLength}
				/>
				<div className="ai-feedback-house-style-actions">
					<Button
						variant="primary"
						size="small"
						isBusy={isBusy}
						disabled={isBusy || !draft.trim()}
						accessibleWhenDisabled
						onClick={() =>
							run(() => updateRule(rule.id, draft, scope))
						}
					>
						{__('Save', 'fresh-eyes')}
					</Button>
					<Button
						variant="tertiary"
						size="small"
						onClick={() => setDraft(null)}
					>
						{__('Cancel', 'fresh-eyes')}
					</Button>
				</div>
			</li>
		);
	}

	return (
		<li className="ai-feedback-house-style-rule">
			<span className="ai-feedback-house-style-text">{rule.text}</span>
			{canEdit && (
				<div className="ai-feedback-house-style-actions">
					<Button
						variant="link"
						onClick={() => setDraft(rule.text)}
						aria-label={sprintf(
							/* translators: %s: house style rule. */
							__('Edit rule: %s', 'fresh-eyes'),
							rule.text
						)}
					>
						{__('Edit', 'fresh-eyes')}
					</Button>
					<Button
						variant="link"
						isDestructive
						disabled={isBusy}
						accessibleWhenDisabled
						onClick={() => run(() => deleteRule(rule.id, scope))}
						aria-label={sprintf(
							/* translators: %s: house style rule. */
							__('Remove rule: %s', 'fresh-eyes'),
							rule.text
						)}
					>
						{__('Remove', 'fresh-eyes')}
					</Button>
				</div>
			)}
		</li>
	);
}

/**
 * A list of rules under a heading.
 *
 * @param {Object}                         props
 * @param {string}                         props.title     Heading.
 * @param {Object[]}                       props.rules     Rules.
 * @param {string}                         props.scope     SCOPE_USER or SCOPE_SITE.
 * @param {boolean}                        props.canEdit   Whether the user may change the rules.
 * @param {number}                         props.maxLength Most characters in a rule.
 * @param {(message: string|null) => void} props.onError   Called with an error message.
 * @return {Element} Rule list.
 */
function RuleList({ title, rules, scope, canEdit, maxLength, onError }) {
	return (
		<div className="ai-feedback-house-style-group">
			<h3 className="ai-feedback-house-style-heading">{title}</h3>
			{rules.length === 0 ? (
				<p className="ai-feedback-house-style-empty">
					{__('No rules yet.', 'fresh-eyes')}
				</p>
			) : (
				<ul className="ai-feedback-house-style-rules">
					{rules.map((rule) => (
						<RuleItem
							key={rule.id}
							rule={rule}
							scope={scope}
							canEdit={canEdit}
							maxLength={maxLength}
							onError={onError}
						/>
					))}
				</ul>
			)}
		</div>
	);
}

/**
 * House Style component.
 *
 * @return {Element|null} House style settings, or null while loading.
 */
export default function HouseStyle() {
	const { rules, loadError } = useHouseStyle();
	const [text, setText] = useState('');
	const [scope, setScope] = useState(SCOPE_USER);
	const [isAdding, setIsAdding] = useState(false);
	const [error, setError] = useState(null);

	if (loadError || !rules) {
		return null;
	}

	const canManageSite = !!rules.can_manage_site;
	const addScope = canManageSite ? scope : SCOPE_USER;
	const isFull = (rules[addScope] || []).length >= rules.max_rules;

	const handleAdd = (event) => {
		event.preventDefault();
		setIsAdding(true);
		setError(null);
		addRule({ text, scope: addScope })
			.then(() => setText(''))
			.catch((requestError) => setError(errorMessage(requestError)))
			.finally(() => setIsAdding(false));
	};

	return (
		<fieldset className="ai-feedback-focus-areas ai-feedback-house-style">
			<legend>{__('House style', 'fresh-eyes')}</legend>
			<p className="description">
				{__(
					'Short rules every review and reply follows. Reviews flag writing that breaks a rule and leave alone what a rule allows.',
					'fresh-eyes'
				)}
			</p>

			{error && (
				<Notice status="error" onRemove={() => setError(null)}>
					{error}
				</Notice>
			)}

			{(canManageSite || rules.site.length > 0) && (
				<RuleList
					title={__('Whole site', 'fresh-eyes')}
					rules={rules.site}
					scope={SCOPE_SITE}
					canEdit={canManageSite}
					maxLength={rules.max_length}
					onError={setError}
				/>
			)}
			<RuleList
				title={__('Just for you', 'fresh-eyes')}
				rules={rules.user}
				scope={SCOPE_USER}
				canEdit
				maxLength={rules.max_length}
				onError={setError}
			/>

			<form className="ai-feedback-house-style-add" onSubmit={handleAdd}>
				<RuleTextarea
					label={__('New rule', 'fresh-eyes')}
					placeholder={__('Headings use sentence case', 'fresh-eyes')}
					value={text}
					onChange={setText}
					onSubmit={(event) => event?.target?.form?.requestSubmit?.()}
					maxLength={rules.max_length}
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
								label: __(
									'Everyone on this site',
									'fresh-eyes'
								),
								value: SCOPE_SITE,
							},
						]}
					/>
				)}
				<Button
					type="submit"
					variant="secondary"
					isBusy={isAdding}
					disabled={isAdding || isFull || !text.trim()}
					accessibleWhenDisabled
				>
					{__('Add rule', 'fresh-eyes')}
				</Button>
				{isFull && (
					<p className="ai-feedback-house-style-empty">
						{sprintf(
							/* translators: %d: maximum number of rules. */
							__(
								'This list is full (%d rules). Remove one to add another.',
								'fresh-eyes'
							),
							rules.max_rules
						)}
					</p>
				)}
			</form>
		</fieldset>
	);
}
