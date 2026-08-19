(function() {
	'use strict';

	const { createElement: h, render, useState, useEffect } = wp.element;
	const { __, _n, sprintf } = wp.i18n;

	function MetricsDashboard() {
		const [range, setRange] = useState('7');
		const [ability, setAbility] = useState('');
		const [abilities, setAbilities] = useState([]);
		const [metrics, setMetrics] = useState(null);
		const [error, setError] = useState('');
		const [loading, setLoading] = useState(true);

		useEffect(() => {
			const controller = new AbortController();
			fetchMetrics(controller.signal);
			return () => controller.abort();
		}, [range, ability]);

		async function fetchMetrics(signal) {
			setLoading(true);
			setError('');
			try {
				const params = new URLSearchParams({ action: 'saltus_get_metrics', nonce: window.saltusMetrics.nonce, range, ability });
				const response = await fetch(window.saltusMetrics.ajaxUrl + '?' + params, { signal });
				const data = await response.json();
				// A superseded request must not write state: the filter it was issued
				// for is no longer the one on screen. Checked after every await
				// because abort only rejects the fetch, not a response already in
				// flight through json().
				if (signal.aborted) { return; }
				if (data.success) { setMetrics(data.data.metrics); setAbilities(data.data.abilities || []); }
				else { setError(data.data?.message || __('Failed to load metrics', 'saltus-framework')); setMetrics(null); }
			} catch (err) {
				if (signal.aborted) { return; }
				setError(__('Network error loading metrics', 'saltus-framework')); setMetrics(null);
			}
			finally { if (!signal.aborted) { setLoading(false); } }
		}

		const statusMessage = loading ? __('Loading metrics...', 'saltus-framework') : error ? error : __('Metrics loaded', 'saltus-framework');
		const sampling = (metrics && metrics.sampling) || {};
		const sampled = sampling.is_sampled === true;

		return h('div', { className: 'saltus-metrics-container' },
			h('div', { className: 'saltus-metrics-status', role: 'status', 'aria-live': 'polite', 'aria-atomic': 'true' }, statusMessage),
			h('div', { className: 'saltus-metrics-filters' },
				h('label', { htmlFor: 'saltus-range-select' },
					__('Date Range:', 'saltus-framework'),
					h('select', { id: 'saltus-range-select', value: range, onChange: e => setRange(e.target.value), disabled: loading },
						h('option', { value: '7' }, __('Last 7 days', 'saltus-framework')),
						h('option', { value: '30' }, __('Last 30 days', 'saltus-framework')),
						h('option', { value: '90' }, __('Last 90 days', 'saltus-framework'))
					)
				),
				h('label', { htmlFor: 'saltus-ability-select' },
					__('Ability:', 'saltus-framework'),
					h('select', { id: 'saltus-ability-select', value: ability, onChange: e => setAbility(e.target.value), disabled: loading },
						h('option', { value: '' }, __('All abilities', 'saltus-framework')),
						abilities.map(ab => h('option', { key: ab, value: ab }, ab))
					)
				)
			),
			error && !loading ? h('div', { className: 'saltus-metrics-error', role: 'alert' }, error) : null,
			h('div', { className: 'saltus-metrics-content', 'aria-busy': loading ? 'true' : 'false' },
				metrics ? h('div', { className: 'saltus-metrics-cards' },
					h(MetricCard, { title: __('Total Calls', 'saltus-framework'), value: orPlaceholder(formatCount(metrics.total_calls)), hint: sampled ? __('Recorded audit rows.', 'saltus-framework') : '' }),
					sampled ? h(MetricCard, { title: __('Estimated Total Calls', 'saltus-framework'), value: orPlaceholder(formatCount(sampling.estimated_total_calls)), hint: estimateHint(sampling) }) : null,
					h(MetricCard, { title: __('Error Rate', 'saltus-framework'), value: orPlaceholder(formatPercent(metrics.error_rate)) }),
					h(MetricCard, { title: __('Avg Latency', 'saltus-framework'), value: orPlaceholder(formatFixed(metrics.avg_latency_ms, 1, ' ms')) })
				) : null,
				sampled ? h(SamplingNotice, { sampling }) : null,
				h(CallsChart, { series: toDailySeries(metrics && metrics.daily_calls), loading, error }),
				metrics ? h('div', { className: 'saltus-metrics-table' }, h('h2', null, __('Per-Ability Breakdown', 'saltus-framework')), h(MetricsTable, { data: metrics.per_ability || [], kind: 'ability' })) : null,
				metrics ? h('div', { className: 'saltus-metrics-table' }, h('h2', null, __('Per-Client Breakdown', 'saltus-framework')), h(MetricsTable, { data: metrics.per_client || [], kind: 'client' })) : null
			)
		);
	}

	function MetricCard({ title, value, hint = '' }) {
		return h('div', { className: 'saltus-metric-card' },
			h('h3', null, title),
			h('div', { className: 'saltus-metric-value' }, value),
			hint ? h('p', { className: 'saltus-metric-hint' }, hint) : null
		);
	}

	function MetricsTable({ data, kind = 'ability' }) {
		const [sortKey, setSortKey] = useState('call_count');
		const [sortDir, setSortDir] = useState('desc');
		const isClient = kind === 'client';
		const nameKey = isClient ? 'client_identifier' : 'ability';
		const nameLabel = isClient ? __('Client', 'saltus-framework') : __('Ability', 'saltus-framework');
		const sorted = [...data].sort((a, b) => {
			const aVal = a[sortKey] || 0, bVal = b[sortKey] || 0;
			if (typeof aVal === 'string' || typeof bVal === 'string') return sortDir === 'asc' ? String(aVal).localeCompare(String(bVal)) : String(bVal).localeCompare(String(aVal));
			return sortDir === 'asc' ? aVal - bVal : bVal - aVal;
		});
		function toggleSort(key) { if (sortKey === key) setSortDir(sortDir === 'asc' ? 'desc' : 'asc'); else { setSortKey(key); setSortDir('desc'); } }
		if (data.length === 0) return h('p', null, isClient ? __('No client metrics available for this period.', 'saltus-framework') : __('No ability metrics available for this period.', 'saltus-framework'));
		const columns = [[nameKey, nameLabel], ['call_count', __('Calls', 'saltus-framework')], ['error_count', __('Errors', 'saltus-framework')], ['avg_duration_ms', __('Avg Latency (ms)', 'saltus-framework')], ['p95_duration_ms', __('P95 Latency (ms)', 'saltus-framework')]];
		return h('table', { className: 'wp-list-table widefat fixed striped' },
			h('thead', null, h('tr', null, columns.map(([key, label]) => h(SortableHeader, { key, columnKey: key, currentSortKey: sortKey, sortDir, onSort: toggleSort, label })))),
			h('tbody', null, sorted.map(row => h('tr', { key: row[nameKey] },
				h('td', null, row[nameKey]),
				h('td', null, orPlaceholder(formatCount(row.call_count))),
				h('td', null, orPlaceholder(formatCount(row.error_count))),
				h('td', null, orPlaceholder(formatFixed(row.avg_duration_ms, 1))),
				h('td', null, orPlaceholder(formatFixed(row.p95_duration_ms, 1)))
			)))
		);
	}

	function SortableHeader({ columnKey, currentSortKey, sortDir, onSort, label }) {
		const isSorted = currentSortKey === columnKey;
		const ariaSort = isSorted ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none';
		return h('th', { scope: 'col', 'aria-sort': ariaSort }, h('button', { type: 'button', className: 'saltus-sort-button', onClick: () => onSort(columnKey), 'aria-label': label + (isSorted ? ' (' + (sortDir === 'asc' ? __('sorted ascending', 'saltus-framework') : __('sorted descending', 'saltus-framework')) + ')' : '') }, label, isSorted ? h('span', { className: 'sortable-indicator', 'aria-hidden': 'true' }, sortDir === 'asc' ? ' ^' : ' v') : null));
	}

	/**
	 * Sampling disclosure. The recorded counts and the extrapolation they support
	 * mean different things, so the notice names both, and the per-rate list is
	 * the only place a window with several rates can be read at all: `sample_rate`
	 * is null there, so the cards can report one rate for it.
	 */
	function SamplingNotice({ sampling }) {
		const rates = Array.isArray(sampling.sample_rates) ? sampling.sample_rates : [];
		return h('div', { className: 'saltus-metrics-sampling-notice' },
			h('p', null, __('Metrics are sampled. Counts above are recorded audit rows; the estimated total extrapolates them to the traffic they stand for.', 'saltus-framework')),
			rates.length > 1 ? h('dl', { className: 'saltus-sampling-rates' }, rates.map((entry, index) => [
				h('dt', { key: 'rate-' + index }, sprintf(
					/* translators: %s: sampling rate as a percentage. */
					__('%s sample', 'saltus-framework'),
					orPlaceholder(formatPercent(entry && entry.rate))
				)),
				h('dd', { key: 'count-' + index }, sprintf(
					/* translators: %s: number of recorded audit rows. */
					__('%s recorded rows', 'saltus-framework'),
					orPlaceholder(formatCount(entry && entry.call_count))
				))
			])) : null
		);
	}

	/** Chart geometry, in user units. The viewBox scales it to the container. */
	const CHART_WIDTH = 720;
	const CHART_HEIGHT = 260;
	const CHART_PADDING = { top: 12, right: 12, bottom: 28, left: 56 };

	/**
	 * Daily call volume as a bar chart, with the same numbers in a table below it.
	 *
	 * The SVG is one labelled image: its title and summary are announced, but bar
	 * height is not a value a screen reader can read, so the table is the
	 * accessible copy of the data rather than a convenience. It sits in a
	 * `details` to be reachable by keyboard without a custom widget. The chart
	 * carries its own loading, empty, and error states because it is the one
	 * region that renders before any metrics arrive.
	 */
	function CallsChart({ series, loading, error }) {
		const heading = h('h2', { id: 'saltus-calls-chart-title' }, __('Calls per Day', 'saltus-framework'));

		if (error) {
			return h('div', { className: 'saltus-metrics-chart' }, heading,
				h('p', { className: 'saltus-chart-state' }, __('Daily call volume is unavailable because the metrics could not be loaded.', 'saltus-framework'))
			);
		}

		if (loading && series.length === 0) {
			return h('div', { className: 'saltus-metrics-chart' }, heading,
				h('p', { className: 'saltus-chart-state' }, __('Loading daily call volume...', 'saltus-framework'))
			);
		}

		if (series.length === 0) {
			return h('div', { className: 'saltus-metrics-chart' }, heading,
				h('p', { className: 'saltus-chart-state' }, __('No calls were recorded in this period, so there is nothing to plot.', 'saltus-framework'))
			);
		}

		const peak = series.reduce((highest, point) => Math.max(highest, point.calls), 0);
		const axisMax = niceAxisMax(peak);
		const plotWidth = CHART_WIDTH - CHART_PADDING.left - CHART_PADDING.right;
		const plotHeight = CHART_HEIGHT - CHART_PADDING.top - CHART_PADDING.bottom;
		const baseline = CHART_PADDING.top + plotHeight;
		const slot = plotWidth / series.length;
		// Leaves a gap between bars without letting a long window collapse them to
		// hairlines; below ~3 user units a bar stops reading as a bar at all.
		const barWidth = Math.max(3, slot * 0.7);
		const labelEvery = Math.ceil(series.length / 7);

		const total = series.reduce((sum, point) => sum + point.calls, 0);
		const busiest = series.reduce((highest, point) => (point.calls > highest.calls ? point : highest), series[0]);
		const summary = sprintf(
			/* translators: 1: number of days, 2: total call count, 3: date, 4: call count on that date. */
			__('%1$s days plotted, %2$s calls in total. Busiest day %3$s with %4$s calls.', 'saltus-framework'),
			formatCount(series.length),
			formatCount(total),
			busiest.date,
			formatCount(busiest.calls)
		);

		return h('div', { className: 'saltus-metrics-chart' }, heading,
			h('svg', {
				className: 'saltus-chart-svg',
				viewBox: '0 0 ' + CHART_WIDTH + ' ' + CHART_HEIGHT,
				preserveAspectRatio: 'xMidYMid meet',
				role: 'img',
				'aria-labelledby': 'saltus-calls-chart-title saltus-calls-chart-summary'
			},
				[0, axisMax / 2, axisMax].map(value => {
					const y = baseline - (value / axisMax) * plotHeight;
					return h('g', { key: 'grid-' + value, className: 'saltus-chart-gridline' },
						h('line', { x1: CHART_PADDING.left, y1: y, x2: CHART_PADDING.left + plotWidth, y2: y }),
						h('text', { x: CHART_PADDING.left - 8, y: y + 4, textAnchor: 'end' }, formatCount(value))
					);
				}),
				series.map((point, index) => {
					const height = axisMax > 0 ? (point.calls / axisMax) * plotHeight : 0;
					return h('rect', {
						key: point.date,
						className: 'saltus-chart-bar',
						x: CHART_PADDING.left + index * slot + (slot - barWidth) / 2,
						y: baseline - height,
						width: barWidth,
						height
					},
						// Hover readout for pointer users. The table carries the same
						// numbers for everyone else.
						h('title', null, sprintf(
							/* translators: 1: date, 2: call count. */
							__('%1$s: %2$s calls', 'saltus-framework'),
							point.date,
							formatCount(point.calls)
						))
					);
				}),
				series.map((point, index) => (
					index % labelEvery === 0 || index === series.length - 1
						? h('text', {
							key: 'label-' + point.date,
							className: 'saltus-chart-axis-label',
							x: CHART_PADDING.left + index * slot + slot / 2,
							y: baseline + 18,
							textAnchor: 'middle'
						}, point.date.slice(5))
						: null
				))
			),
			h('p', { className: 'saltus-chart-summary', id: 'saltus-calls-chart-summary' }, summary),
			h('details', { className: 'saltus-chart-data' },
				h('summary', null, sprintf(
					/* translators: %s: number of days in the table. */
					_n('Show the %s plotted day as a table', 'Show the %s plotted days as a table', series.length, 'saltus-framework'),
					formatCount(series.length)
				)),
				h('table', { className: 'wp-list-table widefat fixed striped' },
					h('thead', null, h('tr', null,
						h('th', { scope: 'col' }, __('Date', 'saltus-framework')),
						h('th', { scope: 'col' }, __('Calls', 'saltus-framework'))
					)),
					h('tbody', null, series.map(point => h('tr', { key: point.date },
						h('th', { scope: 'row' }, point.date),
						h('td', null, formatCount(point.calls))
					)))
				)
			)
		);
	}

	/**
	 * Turn the date-keyed `daily_calls` map into a date-ascending series.
	 *
	 * Only days the server actually rolled up are present, and gaps are left as
	 * gaps: filling them would mean deciding a calendar here, in a timezone the
	 * browser does not share with the rollups.
	 *
	 * @param {object|undefined} daily Date-keyed call counts from the response.
	 * @return {Array<{date: string, calls: number}>} Plottable points.
	 */
	function toDailySeries(daily) {
		if (!daily || typeof daily !== 'object') { return []; }
		return Object.keys(daily)
			.sort()
			.map(date => ({ date, calls: num(daily[date]) }))
			.filter(point => point.calls !== null && point.calls >= 0);
	}

	/**
	 * Round a peak up to a value whose halves are still whole numbers, so the
	 * gridline labels never read as fractions of a call.
	 *
	 * @param {number} peak Highest plotted value.
	 * @return {number} Axis maximum, at least 2.
	 */
	function niceAxisMax(peak) {
		if (peak <= 2) { return 2; }
		const magnitude = Math.pow(10, Math.floor(Math.log10(peak)));
		const step = [1, 2, 4, 6, 10, 20].find(candidate => candidate * magnitude >= peak);
		return step * magnitude;
	}

	/**
	 * Coerce a response value to a finite number, or null when it is not one.
	 *
	 * Every number on screen arrives over HTTP, so no field is guaranteed to be
	 * present or numeric. Formatting an absent one directly throws inside render
	 * and takes the whole dashboard down with it, so each value is coerced first
	 * and a single cell degrades instead.
	 *
	 * @param {unknown} value Raw response value.
	 * @return {number|null} The number, or null when unusable.
	 */
	function num(value) {
		if (typeof value !== 'number' && typeof value !== 'string') { return null; }
		if (typeof value === 'string' && value.trim() === '') { return null; }
		const parsed = Number(value);
		return Number.isFinite(parsed) ? parsed : null;
	}

	/**
	 * @param {unknown} value Raw response value.
	 * @return {string|null} Locale-formatted whole count, or null.
	 */
	function formatCount(value) {
		const parsed = num(value);
		return parsed === null ? null : Math.round(parsed).toLocaleString();
	}

	/**
	 * @param {unknown} value    Raw response value.
	 * @param {number}  decimals Decimal places to keep.
	 * @param {string}  suffix   Unit appended to the result.
	 * @return {string|null} Fixed-precision value, or null.
	 */
	function formatFixed(value, decimals, suffix = '') {
		const parsed = num(value);
		return parsed === null ? null : parsed.toFixed(decimals) + suffix;
	}

	/**
	 * @param {unknown} value Raw response value, as a fraction of 1.
	 * @return {string|null} Percentage, trailing zeros trimmed, or null.
	 */
	function formatPercent(value) {
		const parsed = num(value);
		if (parsed === null) { return null; }
		return parseFloat((parsed * 100).toFixed(2)) + '%';
	}

	/**
	 * Fall back to a placeholder when a value could not be formatted.
	 *
	 * The dash is hidden from assistive technology and paired with wording,
	 * because a lone dash announces as nothing at all.
	 *
	 * @param {string|null} formatted Result of a formatter.
	 * @return {string|object} The value, or the placeholder markup.
	 */
	function orPlaceholder(formatted) {
		if (formatted !== null) { return formatted; }
		return h('span', { className: 'saltus-metric-unavailable' },
			h('span', { 'aria-hidden': 'true' }, '\u2014'),
			h('span', { className: 'screen-reader-text' }, __('Not available', 'saltus-framework'))
		);
	}

	/**
	 * Describe what the estimate was extrapolated from.
	 *
	 * @param {object} sampling The payload's sampling block.
	 * @return {string} Hint text for the estimate card.
	 */
	function estimateHint(sampling) {
		const rate = formatPercent(sampling.sample_rate);
		if (rate === null) {
			return __('Extrapolated from several sampling rates, listed below.', 'saltus-framework');
		}
		return sprintf(
			/* translators: %s: sampling rate as a percentage. */
			__('Extrapolated from a %s sample.', 'saltus-framework'),
			rate
		);
	}

	const container = document.getElementById('saltus-metrics-dashboard');
	if (container) render(h(MetricsDashboard), container);
})();
