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
	if (item.title || item.feedback) {
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

	const container = document.createElement('div');
	container.innerHTML = raw;

	container
		.querySelectorAll('.ai-feedback-badge')
		.forEach((badge) => badge.remove());

	const strong = container.querySelector('strong');
	const title = strong ? strong.textContent.trim() : '';
	strong?.remove();

	let feedback = container.textContent.trim();
	let suggestion = '';

	const suggestionLabel = container.querySelector('em');
	if (suggestionLabel) {
		const label = suggestionLabel.textContent;
		const [before, ...after] = feedback.split(label);
		feedback = before.trim();
		suggestion = after.join(label).trim();
	}

	return { title, feedback, suggestion };
}
