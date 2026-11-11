/**
 * Read the display text for a feedback item.
 */

/**
 * Strip markup from a string.
 *
 * @param {string|undefined} html HTML or plain text.
 * @return {string} Plain text.
 */
function toPlainText(html) {
	if (!html) {
		return '';
	}
	if (!/[<&]/.test(html)) {
		return html;
	}
	return new window.DOMParser()
		.parseFromString(html, 'text/html')
		.body.textContent.trim();
}

/**
 * Get the title, feedback and suggestion for a feedback item.
 *
 * New notes carry these as fields. Notes saved before that only have HTML
 * content (a bold title, the feedback, an optional "Suggestion:" and badges),
 * so fall back to reading it from there.
 *
 * @param {Object} item Feedback item or note.
 * @return {{title: string, feedback: string, suggestion: string}} Display text.
 */
export function getFeedbackContent(item = {}) {
	if (item.title || item.feedback || item.suggestion) {
		// Stored text may carry the few tags the parser allows (strong, em,
		// code, br); the sidebar shows plain text.
		return {
			title: toPlainText(item.title),
			feedback: toPlainText(item.feedback),
			suggestion: toPlainText(item.suggestion),
		};
	}

	const raw = item.content?.raw || item.content?.rendered || '';
	if (!raw) {
		return { title: '', feedback: '', suggestion: '' };
	}

	// DOMParser documents are inert: no scripts run and no images load.
	const doc = new window.DOMParser().parseFromString(raw, 'text/html');
	const container = doc.body;

	container
		.querySelectorAll('.ai-feedback-badge')
		.forEach((badge) => badge.remove());

	// The title is the leading <strong>.
	const first = container.firstElementChild;
	const title =
		first?.tagName === 'STRONG' && container.firstChild === first
			? first.textContent.trim()
			: '';
	if (title) {
		first.remove();
	}

	// The suggestion follows a top-level <em> label ending in a colon.
	const label = Array.from(container.children).find(
		(element) =>
			element.tagName === 'EM' && element.textContent.trim().endsWith(':')
	);

	let suggestion = '';
	if (label) {
		const parts = [];
		let node = label.nextSibling;
		while (node) {
			parts.push(node.textContent);
			const next = node.nextSibling;
			node.remove();
			node = next;
		}
		label.remove();
		suggestion = parts.join('').trim();
	}

	const feedback = container.textContent.trim();

	return { title, feedback, suggestion };
}
