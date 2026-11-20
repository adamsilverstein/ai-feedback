/**
 * Keep the Fresh Eyes user out of the block editor's `@` user completer.
 *
 * The server lists the hidden Fresh Eyes user for the Notes completer's
 * search. The block completer, in paragraphs and other blocks, sends the same
 * request, so it would offer Fresh Eyes too. Mentioning it in post content
 * does nothing, so it is left out there.
 */
import { useMemo } from '@wordpress/element';

/**
 * Get the Fresh Eyes user ID from the server.
 *
 * @return {number} User ID, or 0.
 */
export function getFreshEyesUserId() {
	const id = Number(window.aiFeedbackData?.mentionUserId);
	return Number.isInteger(id) && id > 0 ? id : 0;
}

/**
 * Wrap the block editor's user completer so it never lists a user.
 *
 * @param {Object} completer Completer.
 * @param {number} userId    User ID to leave out.
 * @return {Object} Completer.
 */
export function hideUserFromCompleter(completer, userId) {
	if (
		!userId ||
		completer?.name !== 'users' ||
		typeof completer.useItems !== 'function'
	) {
		return completer;
	}

	const { useItems } = completer;
	return {
		...completer,
		useItems(filterValue) {
			const [options, ...rest] = useItems(filterValue);
			const visible = useMemo(
				() =>
					Array.isArray(options)
						? options.filter(
								(option) => option?.value?.id !== userId
							)
						: options,
				[options]
			);
			return [visible, ...rest];
		},
	};
}

/**
 * Filter for `editor.Autocomplete.completers`.
 *
 * @param {Object[]} completers Completers.
 * @return {Object[]} Completers.
 */
export function hideFreshEyesUser(completers) {
	const userId = getFreshEyesUserId();
	return Array.isArray(completers)
		? completers.map((completer) =>
				hideUserFromCompleter(completer, userId)
			)
		: completers;
}
