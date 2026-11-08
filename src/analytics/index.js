/**
 * Analytics admin page entry point.
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';
import Dashboard from './Dashboard';
import './analytics.scss';

domReady(() => {
	const container = document.getElementById('ai-feedback-analytics-root');
	if (container) {
		createRoot(container).render(<Dashboard />);
	}
});
