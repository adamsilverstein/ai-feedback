/**
 * Internal dependencies
 */
import { getFeedbackContent } from '../feedback-content';

describe('getFeedbackContent', () => {
	it('uses the stored fields when present', () => {
		expect(
			getFeedbackContent({
				title: 'Wordy',
				feedback: 'Too long.',
				suggestion: 'Trim it.',
				content: { raw: '<strong>Other</strong>' },
			})
		).toEqual({
			title: 'Wordy',
			feedback: 'Too long.',
			suggestion: 'Trim it.',
		});
	});

	it('falls back to parsing older note content', () => {
		const raw =
			'<strong>Wordy phrase</strong>\n\nThis could be shorter.\n\n' +
			'<em>Suggestion:</em> Use "to" instead.\n\n' +
			'<span class="ai-feedback-badge ai-feedback-category-tone">Tone</span> ' +
			'<span class="ai-feedback-badge ai-feedback-severity-suggestion">Suggestion</span>';

		expect(getFeedbackContent({ content: { raw } })).toEqual({
			title: 'Wordy phrase',
			feedback: 'This could be shorter.',
			suggestion: 'Use "to" instead.',
		});
	});

	it('handles notes without a suggestion', () => {
		const raw = '<strong>Title</strong>\n\nJust feedback.';

		expect(getFeedbackContent({ content: { raw } })).toEqual({
			title: 'Title',
			feedback: 'Just feedback.',
			suggestion: '',
		});
	});

	it('returns empty strings for an empty item', () => {
		expect(getFeedbackContent({})).toEqual({
			title: '',
			feedback: '',
			suggestion: '',
		});
	});
});
