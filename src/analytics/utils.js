/**
 * Analytics dashboard helpers.
 */

/**
 * Turn a count map into rows sorted by count, largest first.
 *
 * @param {Object} counts Map of label to count.
 * @return {Array<{label: string, count: number}>} Rows.
 */
export function toRows(counts = {}) {
	return Object.entries(counts)
		.map(([label, count]) => ({ label, count: Number(count) || 0 }))
		.sort((a, b) => b.count - a.count);
}

/**
 * Escape one CSV cell.
 *
 * Cells that a spreadsheet would treat as a formula are prefixed with a
 * quote so exported model names can't run as formulas.
 *
 * @param {*} value Cell value.
 * @return {string} Escaped cell.
 */
export function csvCell(value) {
	let text = String(value ?? '');
	if (/^[=+\-@\t\r]/.test(text)) {
		text = `'${text}`;
	}
	if (/[",\n\r]/.test(text)) {
		text = `"${text.replace(/"/g, '""')}"`;
	}
	return text;
}

/**
 * Build a CSV of reviews and feedback per day.
 *
 * @param {Array} byDay Rows with date, reviews and feedback.
 * @return {string} CSV text.
 */
export function generateDailyCsv(byDay = []) {
	const lines = [['date', 'reviews', 'feedback'].join(',')];
	byDay.forEach((row) => {
		lines.push(
			[row.date, row.reviews, row.feedback].map(csvCell).join(',')
		);
	});
	return lines.join('\n') + '\n';
}
