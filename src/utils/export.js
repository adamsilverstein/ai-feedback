/**
 * Review export helpers.
 *
 * Turn a review response into Markdown, plain text or JSON for sharing.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

export const SEVERITIES = ['critical', 'important', 'suggestion'];

/**
 * Localized severity section headings.
 *
 * @return {Object} Map of severity to heading.
 */
function getSeverityHeadings() {
	return {
		critical: __('Critical', 'ai-feedback'),
		important: __('Important', 'ai-feedback'),
		suggestion: __('Suggestions', 'ai-feedback'),
	};
}

/**
 * Convert an HTML fragment to plain text without executing or loading anything.
 *
 * @param {string} html HTML fragment.
 * @return {string} Plain text.
 */
function htmlToText(html) {
	if (!html) {
		return '';
	}
	const doc = new window.DOMParser().parseFromString(html, 'text/html');
	return (doc.body.textContent || '').trim();
}

/**
 * Normalize a feedback item or a stored note to a common shape.
 *
 * Fresh reviews return notes whose content is HTML built by
 * Notes_Manager::build_note_content(): a bold title, the feedback, an
 * italic "Suggestion:" line and trailing badges.
 *
 * @param {Object} item Feedback item or note.
 * @return {Object} Item with title, feedback, suggestion, category and severity.
 */
export function normalizeFeedbackItem(item) {
	const base = {
		category: item.category || '',
		severity: item.severity || '',
		isResolved: !!item.is_resolved,
	};

	if (item.title || item.feedback) {
		return {
			...base,
			title: item.title || '',
			feedback: item.feedback || '',
			suggestion: item.suggestion || '',
		};
	}

	const raw = item.content?.raw ?? item.content?.rendered ?? '';
	const doc = new window.DOMParser().parseFromString(raw, 'text/html');
	const body = doc.body;
	body.querySelectorAll('.ai-feedback-badge').forEach((el) => el.remove());

	// The title is a <strong> only when it opens the note.
	let title = '';
	const first = Array.from(body.childNodes).find(
		(node) => node.nodeType !== 3 || node.textContent.trim() !== ''
	);
	if (first && first.nodeName === 'STRONG') {
		title = first.textContent.trim();
		first.remove();
	}

	// The suggestion follows a top-level <em> holding the label, so inline
	// emphasis inside the feedback is left alone.
	const label = __('Suggestion:', 'ai-feedback');
	const marker = Array.from(body.children).find(
		(el) => el.nodeName === 'EM' && el.textContent.trim() === label
	);

	let feedbackHtml = body.innerHTML;
	let suggestion = '';
	if (marker) {
		const after = doc.createElement('div');
		while (marker.nextSibling) {
			after.appendChild(marker.nextSibling);
		}
		marker.remove();
		feedbackHtml = body.innerHTML;
		suggestion = htmlToText(after.innerHTML);
	}

	return {
		...base,
		title,
		feedback: htmlToText(feedbackHtml),
		suggestion,
	};
}

/**
 * Group normalized items by severity.
 *
 * @param {Array} items Normalized items.
 * @return {Object} Map of severity to items; unknown severities count as suggestions.
 */
export function groupBySeverity(items) {
	const grouped = { critical: [], important: [], suggestion: [] };
	items.forEach((item) => {
		const key = SEVERITIES.includes(item.severity)
			? item.severity
			: 'suggestion';
		grouped[key].push(item);
	});
	return grouped;
}

/**
 * Get a review's feedback items, normalized.
 *
 * @param {Object} review Review response.
 * @return {Array} Normalized items.
 */
export function getReviewItems(review) {
	return (review?.notes || []).map(normalizeFeedbackItem);
}

/**
 * Format one item as Markdown.
 *
 * @param {Object} item Normalized item.
 * @return {string} Markdown.
 */
function formatMarkdownItem(item) {
	const title = (item.title || __('Feedback', 'ai-feedback')).replace(
		/\s+/g,
		' '
	);
	let md = `#### ${title}\n\n`;
	if (item.category) {
		md += `**${__('Category:', 'ai-feedback')}** ${item.category}`;
		md += item.isResolved ? ` (${__('resolved', 'ai-feedback')})` : '';
		md += '\n\n';
	}
	if (item.feedback) {
		md += `${item.feedback}\n\n`;
	}
	if (item.suggestion) {
		const quoted = item.suggestion.replace(/\r?\n/g, '\n> ');
		md += `> **${__('Suggestion:', 'ai-feedback')}** ${quoted}\n\n`;
	}
	return md;
}

/**
 * Build a Markdown report for a review.
 *
 * @param {Object} review    Review response.
 * @param {string} postTitle Post title.
 * @return {string} Markdown.
 */
export function generateMarkdownReport(review, postTitle) {
	const items = getReviewItems(review);
	const headings = getSeverityHeadings();

	let md = `# ${sprintf(
		/* translators: %s: post title */
		__('AI Feedback Report: %s', 'ai-feedback'),
		postTitle || __('(no title)', 'ai-feedback')
	)}\n\n`;

	if (review.timestamp) {
		md += `**${__('Date:', 'ai-feedback')}** ${review.timestamp}\n`;
	}
	if (review.model) {
		md += `**${__('Model:', 'ai-feedback')}** ${review.model}\n`;
	}
	md += '\n';

	if (review.summary_text) {
		md += `## ${__('Summary', 'ai-feedback')}\n\n${review.summary_text}\n\n`;
	}

	if (items.length === 0) {
		md += `## ${__('Feedback', 'ai-feedback')}\n\n_${__(
			'No issues found.',
			'ai-feedback'
		)}_\n`;
	} else {
		md += `## ${sprintf(
			/* translators: %d: number of feedback items */
			_n(
				'Feedback (%d item)',
				'Feedback (%d items)',
				items.length,
				'ai-feedback'
			),
			items.length
		)}\n\n`;

		const grouped = groupBySeverity(items);
		SEVERITIES.forEach((severity) => {
			if (grouped[severity].length === 0) {
				return;
			}
			md += `### ${headings[severity]}\n\n`;
			grouped[severity].forEach((item) => {
				md += formatMarkdownItem(item);
			});
		});
	}

	md += `\n---\n_${__('Generated by AI Feedback for WordPress', 'ai-feedback')}_\n`;

	return md;
}

/**
 * Build a short plain text summary for chat or email.
 *
 * @param {Object} review    Review response.
 * @param {string} postTitle Post title.
 * @return {string} Plain text.
 */
export function generatePlainTextSummary(review, postTitle) {
	const grouped = groupBySeverity(getReviewItems(review));
	const title = sprintf(
		/* translators: %s: post title */
		__('AI Feedback summary: %s', 'ai-feedback'),
		postTitle || __('(no title)', 'ai-feedback')
	);

	let text = `${title}\n${'='.repeat(title.length)}\n\n`;
	if (review.summary_text) {
		text += `${review.summary_text}\n\n`;
	}

	text += `${__('Issues found:', 'ai-feedback')}\n`;
	text += `- ${__('Critical', 'ai-feedback')}: ${grouped.critical.length}\n`;
	text += `- ${__('Important', 'ai-feedback')}: ${grouped.important.length}\n`;
	text += `- ${__('Suggestions', 'ai-feedback')}: ${grouped.suggestion.length}\n`;

	return text;
}

/**
 * Build a JSON export with the fields useful outside the editor.
 *
 * Editor-only fields such as block mappings and note IDs are left out.
 *
 * @param {Object} review    Review response.
 * @param {string} postTitle Post title.
 * @return {string} Pretty-printed JSON.
 */
export function generateJSON(review, postTitle) {
	return JSON.stringify(
		{
			review_id: review.review_id,
			post_id: review.post_id,
			post_title: postTitle || '',
			model: review.model,
			timestamp: review.timestamp,
			summary_text: review.summary_text || '',
			summary: review.summary || {},
			feedback: getReviewItems(review).map((item) => ({
				title: item.title,
				category: item.category,
				severity: item.severity,
				feedback: item.feedback,
				suggestion: item.suggestion,
				resolved: item.isResolved,
			})),
		},
		null,
		2
	);
}

/**
 * Build a download filename for a review.
 *
 * @param {Object} review    Review response.
 * @param {string} extension File extension without the dot.
 * @return {string} Filename.
 */
export function getExportFilename(review, extension) {
	const id = String(review?.review_id || 'review')
		.replace(/[^a-z0-9-]/gi, '')
		.slice(0, 8);
	const date = String(review?.timestamp || '')
		.slice(0, 10)
		.replace(/[^0-9-]/g, '');
	return (
		['ai-feedback', date, id].filter(Boolean).join('-') + `.${extension}`
	);
}
