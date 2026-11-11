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
		{ label: __('Last 7 days', 'ai-feedback'), value: '7days' },
		{ label: __('Last 30 days', 'ai-feedback'), value: '30days' },
		{ label: __('Last 90 days', 'ai-feedback'), value: '90days' },
		{ label: __('All time', 'ai-feedback'), value: 'all' },
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
					<p>{__('No data for this period.', 'ai-feedback')}</p>
				) : (
					<table className="ai-feedback-analytics-bars">
						<thead className="screen-reader-text">
							<tr>
								<th scope="col">{column}</th>
								<th scope="col">
									{__('Count', 'ai-feedback')}
								</th>
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
				<h2>{__('Reviews over time', 'ai-feedback')}</h2>
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
					<summary>{__('Show data table', 'ai-feedback')}</summary>
					<table className="widefat striped">
						<thead>
							<tr>
								<th scope="col">{__('Date', 'ai-feedback')}</th>
								<th scope="col">
									{__('Reviews', 'ai-feedback')}
								</th>
								<th scope="col">
									{__('Feedback items', 'ai-feedback')}
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
		critical: __('Critical', 'ai-feedback'),
		important: __('Important', 'ai-feedback'),
		suggestion: __('Suggestion', 'ai-feedback'),
	};

	return (
		<div className="ai-feedback-analytics">
			<div className="ai-feedback-analytics-toolbar">
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={__('Period', 'ai-feedback')}
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
					{__('Export CSV', 'ai-feedback')}
				</Button>
			</div>

			{error && (
				<Notice status="error" isDismissible={false}>
					{error.message ||
						__('Could not load analytics.', 'ai-feedback')}
				</Notice>
			)}

			{!data && !error && <Spinner />}

			{data && (
				<>
					<div className="ai-feedback-analytics-stats">
						<Stat
							label={__('Total reviews', 'ai-feedback')}
							value={String(data.total_reviews)}
						/>
						<Stat
							label={__('Total feedback items', 'ai-feedback')}
							value={String(data.total_feedback)}
						/>
						<Stat
							label={__(
								'Average items per review',
								'ai-feedback'
							)}
							value={Number(data.avg_feedback).toFixed(1)}
						/>
					</div>

					<div className="ai-feedback-analytics-grid">
						<ReviewsOverTime days={data.by_day} />
						<BarList
							title={__('Models', 'ai-feedback')}
							column={__('Model', 'ai-feedback')}
							rows={toRows(data.by_model)}
						/>
						<BarList
							title={__('Severity', 'ai-feedback')}
							column={__('Severity', 'ai-feedback')}
							rows={toRows(data.by_severity).map((row) => ({
								...row,
								label: severityLabels[row.label] || row.label,
							}))}
						/>
						<BarList
							title={__('Categories', 'ai-feedback')}
							column={__('Category', 'ai-feedback')}
							rows={toRows(data.by_category)}
						/>
						<BarList
							title={__('Top users', 'ai-feedback')}
							column={__('User', 'ai-feedback')}
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
								'ai-feedback'
							),
							365
						)}
					</p>
				</>
			)}
		</div>
	);
}
