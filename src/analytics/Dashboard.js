/**
 * Usage analytics dashboard.
 */
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { downloadBlob } from '@wordpress/blob';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import { toRows, generateDailyCsv } from './utils';

/**
 * Period options.
 *
 * @return {Array} SelectControl options.
 */
function getPeriodOptions() {
	return [
		{ label: __('Last 7 days', 'fresh-eyes'), value: '7days' },
		{ label: __('Last 30 days', 'fresh-eyes'), value: '30days' },
		{ label: __('Last 90 days', 'fresh-eyes'), value: '90days' },
		{ label: __('All time', 'fresh-eyes'), value: 'all' },
	];
}

/**
 * A single headline number.
 *
 * @param {Object} props       Component props.
 * @param {string} props.label Stat label.
 * @param {string} props.value Stat value.
 * @return {Element} Stat card.
 */
function Stat({ label, value }) {
	return (
		<Card className="ai-feedback-analytics-stat">
			<CardBody>
				<div className="ai-feedback-analytics-stat-label">{label}</div>
				<div className="ai-feedback-analytics-stat-value">{value}</div>
			</CardBody>
		</Card>
	);
}

/**
 * Horizontal bar list, rendered as a table so it reads well without the bars.
 *
 * @param {Object} props        Component props.
 * @param {string} props.title  Card title.
 * @param {Array}  props.rows   Rows with label and count.
 * @param {string} props.column Header for the label column.
 * @return {Element} Card with bars.
 */
function BarList({ title, rows, column }) {
	const max = Math.max(1, ...rows.map((row) => row.count));

	return (
		<Card className="ai-feedback-analytics-panel">
			<CardHeader>
				<h2>{title}</h2>
			</CardHeader>
			<CardBody>
				{rows.length === 0 ? (
					<p>{__('No data for this period.', 'fresh-eyes')}</p>
				) : (
					<table className="ai-feedback-analytics-bars">
						<thead className="screen-reader-text">
							<tr>
								<th scope="col">{column}</th>
								<th scope="col">{__('Count', 'fresh-eyes')}</th>
							</tr>
						</thead>
						<tbody>
							{rows.map((row) => (
								<tr key={row.key ?? row.label}>
									<th scope="row">{row.label}</th>
									<td>
										<span
											className="ai-feedback-analytics-bar"
											style={{
												width: `${(row.count / max) * 100}%`,
											}}
											aria-hidden="true"
										/>
										<span className="ai-feedback-analytics-count">
											{row.count}
										</span>
									</td>
								</tr>
							))}
						</tbody>
					</table>
				)}
			</CardBody>
		</Card>
	);
}

/**
 * Reviews per day as columns, with the data table behind a disclosure.
 *
 * @param {Object} props      Component props.
 * @param {Array}  props.days Rows with date, reviews and feedback.
 * @return {Element} Card with chart.
 */
function ReviewsOverTime({ days }) {
	const max = Math.max(1, ...days.map((day) => day.reviews));

	return (
		<Card className="ai-feedback-analytics-panel ai-feedback-analytics-panel-wide">
			<CardHeader>
				<h2>{__('Reviews over time', 'fresh-eyes')}</h2>
			</CardHeader>
			<CardBody>
				<div
					className="ai-feedback-analytics-columns"
					aria-hidden="true"
				>
					{days.map((day) => (
						<span
							key={day.date}
							className="ai-feedback-analytics-column"
							style={{ height: `${(day.reviews / max) * 100}%` }}
							title={`${day.date}: ${day.reviews}`}
						/>
					))}
				</div>
				<details>
					<summary>{__('Show data table', 'fresh-eyes')}</summary>
					<table className="widefat striped">
						<thead>
							<tr>
								<th scope="col">{__('Date', 'fresh-eyes')}</th>
								<th scope="col">
									{__('Reviews', 'fresh-eyes')}
								</th>
								<th scope="col">
									{__('Feedback items', 'fresh-eyes')}
								</th>
							</tr>
						</thead>
						<tbody>
							{days.map((day) => (
								<tr key={day.date}>
									<td>{day.date}</td>
									<td>{day.reviews}</td>
									<td>{day.feedback}</td>
								</tr>
							))}
						</tbody>
					</table>
				</details>
			</CardBody>
		</Card>
	);
}

/**
 * Analytics dashboard.
 *
 * @return {Element} Dashboard.
 */
export default function Dashboard() {
	const [period, setPeriod] = useState('30days');
	const [data, setData] = useState(null);
	const [error, setError] = useState(null);

	useEffect(() => {
		let active = true;
		setError(null);
		// Clear old numbers so the CSV can't export the previous period.
		setData(null);
		apiFetch({
			path: addQueryArgs('/ai-feedback/v1/analytics', { period }),
		})
			.then((response) => active && setData(response))
			.catch((fetchError) => active && setError(fetchError));
		return () => {
			active = false;
		};
	}, [period]);

	const severityLabels = {
		critical: __('Critical', 'fresh-eyes'),
		important: __('Important', 'fresh-eyes'),
		suggestion: __('Suggestion', 'fresh-eyes'),
	};

	const categoryLabels = {
		content: __('Content', 'fresh-eyes'),
		tone: __('Tone', 'fresh-eyes'),
		flow: __('Flow', 'fresh-eyes'),
		design: __('Design', 'fresh-eyes'),
		grammar: __('Grammar', 'fresh-eyes'),
	};

	return (
		<div className="ai-feedback-analytics">
			<div className="ai-feedback-analytics-toolbar">
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={__('Period', 'fresh-eyes')}
					value={period}
					options={getPeriodOptions()}
					onChange={setPeriod}
				/>
				<Button
					variant="secondary"
					disabled={!data}
					onClick={() =>
						downloadBlob(
							`ai-feedback-analytics-${period}.csv`,
							generateDailyCsv(data?.by_day),
							'text/csv'
						)
					}
				>
					{__('Export CSV', 'fresh-eyes')}
				</Button>
			</div>

			{error && (
				<Notice status="error" isDismissible={false}>
					{error.message ||
						__('Could not load analytics.', 'fresh-eyes')}
				</Notice>
			)}

			{!data && !error && <Spinner />}

			{data && (
				<>
					<div className="ai-feedback-analytics-stats">
						<Stat
							label={__('Total reviews', 'fresh-eyes')}
							value={String(data.total_reviews)}
						/>
						<Stat
							label={__('Total feedback items', 'fresh-eyes')}
							value={String(data.total_feedback)}
						/>
						<Stat
							label={__('Average items per review', 'fresh-eyes')}
							value={Number(data.avg_feedback).toFixed(1)}
						/>
					</div>

					<div className="ai-feedback-analytics-grid">
						<ReviewsOverTime days={data.by_day} />
						<BarList
							title={__('Models', 'fresh-eyes')}
							column={__('Model', 'fresh-eyes')}
							rows={toRows(data.by_model)}
						/>
						<BarList
							title={__('Severity', 'fresh-eyes')}
							column={__('Severity', 'fresh-eyes')}
							rows={toRows(data.by_severity).map((row) => ({
								...row,
								label: severityLabels[row.label] || row.label,
							}))}
						/>
						<BarList
							title={__('Categories', 'fresh-eyes')}
							column={__('Category', 'fresh-eyes')}
							rows={toRows(data.by_category).map((row) => ({
								...row,
								label: categoryLabels[row.label] || row.label,
							}))}
						/>
						<BarList
							title={__('Top users', 'fresh-eyes')}
							column={__('User', 'fresh-eyes')}
							rows={data.top_users.map((user) => ({
								key: user.user_id,
								label: user.name,
								count: user.reviews,
							}))}
						/>
					</div>

					<p className="description">
						{sprintf(
							/* translators: %d: number of days kept */
							__(
								'Usage is counted per day and kept for %d days.',
								'fresh-eyes'
							),
							365
						)}
					</p>
				</>
			)}
		</div>
	);
}
