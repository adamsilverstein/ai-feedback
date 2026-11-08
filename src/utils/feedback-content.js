/**
 * Read the display text for a feedback item.
 */

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
		return {
			title: item.title || '',
			feedback: item.feedback || '',
			suggestion: item.suggestion || '',
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
