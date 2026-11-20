/**
 * useHouseStyle hook.
 *
 * Loads the house style rules and keeps them current when they change
 * anywhere in the editor.
 */
import { useEffect, useState } from '@wordpress/element';
import { loadHouseStyle, subscribeHouseStyle } from '../utils/house-style';

/**
 * @return {{rules: import('../utils/house-style').HouseStyleState|null, loadError: Error|null}}
 *         Rules, or null while loading, and the load error if there was one.
 */
export default function useHouseStyle() {
	const [rules, setRules] = useState(null);
	const [loadError, setLoadError] = useState(null);

	useEffect(() => {
		let active = true;
		const unsubscribe = subscribeHouseStyle((state) => {
			if (active) {
				setRules(state);
			}
		});
		loadHouseStyle()
			.then((state) => active && setRules(state))
			.catch((error) => active && setLoadError(error));

		return () => {
			active = false;
			unsubscribe();
		};
	}, []);

	return { rules, loadError };
}
