/**
 * Internal dependencies
 */
import {
	normalizeFeedbackItem,
	groupBySeverity,
	generateMarkdownReport,
	generatePlainTextSummary,
	generateJSON,
	getExportFilename,
} from '../export';

const noteContent =
	'<strong>Weak opening</strong>\n\nThe intro buries the point.\n\n' +
	'<em>Suggestion:</em> Lead with the main claim.\n\n' +
	'<span class="ai-feedback-badge ai-feedback-category-content">Content</span> ' +
	'<span class="ai-feedback-badge ai-feedback-severity-critical">Critical</span>';

const review = {
	review_id: '550e8400-e29b-41d4-a716-446655440000',
	post_id: 42,
	model: 'test-model',
	timestamp: '2026-11-08 10:00:00',
	summary_text: 'A clear draft.',
	summary: { by_severity: { critical: 1, important: 0, suggestion: 1 } },
	block_mapping: { abc: 1 },
	notes: [
		{
			id: 1,
			category: 'content',
			severity: 'critical',
			content: { raw: noteContent },
			is_resolved: false,
		},
		{
			title: 'Long sentence',
			feedback: 'Split this sentence.',
			suggestion: '',
			category: 'flow',
			severity: 'suggestion',
		},
	],
};

describe('normalizeFeedbackItem', () => {
	it('parses stored note HTML', () => {
		expect(normalizeFeedbackItem(review.notes[0])).toEqual({
			title: 'Weak opening',
			feedback: 'The intro buries the point.',
			suggestion: 'Lead with the main claim.',
			category: 'content',
			severity: 'critical',
			isResolved: false,
		});
	});

	it('keeps inline emphasis inside the feedback', () => {
		const item = normalizeFeedbackItem({
			content: {
				raw:
					'<strong>Pacing</strong>\n\nConsider <em>shorter</em> sentences and <strong>fewer</strong> asides.\n\n' +
					'<em>Suggestion:</em> Split the <em>second</em> paragraph.',
			},
		});
		expect(item.title).toBe('Pacing');
		expect(item.feedback).toBe(
			'Consider shorter sentences and fewer asides.'
		);
		expect(item.suggestion).toBe('Split the second paragraph.');
	});

	it('does not take a bold word as the title when there is none', () => {
		const item = normalizeFeedbackItem({
			content: { raw: 'Use <strong>active</strong> voice.' },
		});
		expect(item.title).toBe('');
		expect(item.feedback).toBe('Use active voice.');
	});

	it('passes raw feedback items through', () => {
		expect(normalizeFeedbackItem(review.notes[1]).title).toBe(
			'Long sentence'
		);
	});

	it('does not run markup in note content', () => {
		const item = normalizeFeedbackItem({
			content: { raw: '<img src=x onerror="window.pwned=1">Hi' },
		});
		expect(item.feedback).toBe('Hi');
		expect(window.pwned).toBeUndefined();
	});
});

describe('groupBySeverity', () => {
	it('treats unknown severities as suggestions', () => {
		const grouped = groupBySeverity([{ severity: 'odd' }]);
		expect(grouped.suggestion).toHaveLength(1);
	});
});

describe('generateMarkdownReport', () => {
	it('groups feedback by severity', () => {
		const md = generateMarkdownReport(review, 'My Post');
		expect(md).toContain('# AI Feedback Report: My Post');
		expect(md).toContain('**Model:** test-model');
		expect(md).toContain('## Feedback (2 items)');
		expect(md.indexOf('### Critical')).toBeLessThan(
			md.indexOf('### Suggestions')
		);
		expect(md).toContain('> **Suggestion:** Lead with the main claim.');
		expect(md).not.toContain('### Important');
	});

	it('marks resolved items and keeps multi-line suggestions quoted', () => {
		const md = generateMarkdownReport(
			{
				...review,
				notes: [
					{
						title: 'T',
						feedback: 'F',
						suggestion: 'line one\nline two',
						category: 'tone',
						severity: 'important',
						is_resolved: true,
					},
				],
			},
			'P'
		);
		expect(md).toContain('**Category:** tone (resolved)');
		expect(md).toContain('> **Suggestion:** line one\n> line two');
	});

	it('handles reviews with no feedback', () => {
		const md = generateMarkdownReport({ ...review, notes: [] }, 'P');
		expect(md).toContain('_No issues found._');
	});
});

describe('generatePlainTextSummary', () => {
	it('lists counts by severity', () => {
		const text = generatePlainTextSummary(review, 'My Post');
		expect(text).toContain('A clear draft.');
		expect(text).toContain('- Critical: 1');
		expect(text).toContain('- Important: 0');
		expect(text).toContain('- Suggestions: 1');
	});
});

describe('generateJSON', () => {
	it('exports portable fields only', () => {
		const data = JSON.parse(generateJSON(review, 'My Post'));
		expect(data.post_title).toBe('My Post');
		expect(data.feedback).toHaveLength(2);
		expect(data.feedback[0].title).toBe('Weak opening');
		expect(data.block_mapping).toBeUndefined();
	});
});

describe('getExportFilename', () => {
	it('strips unsafe characters', () => {
		expect(
			getExportFilename({ review_id: '../x/y', timestamp: '' }, 'json')
		).toBe('ai-feedback-xy.json');
	});

	it('includes the date and short review ID', () => {
		expect(getExportFilename(review, 'md')).toBe(
			'ai-feedback-2026-11-08-550e8400.md'
		);
	});
});
