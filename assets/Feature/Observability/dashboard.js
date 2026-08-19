(function() {
	'use strict';

	const { createElement: h, render, useState, useEffect } = wp.element;
	const { __ } = wp.i18n;

	function MetricsDashboard() {
		const [range, setRange] = useState('7');
		const [ability, setAbility] = useState('');
		const [abilities, setAbilities] = useState([]);
		const [metrics, setMetrics] = useState(null);
		const [error, setError] = useState('');
		const [loading, setLoading] = useState(true);

		useEffect(() => { fetchMetrics(); }, [range, ability]);

		async function fetchMetrics() {
			setLoading(true);
			setError('');
			try {
				const params = new URLSearchParams({ action: 'saltus_get_metrics', nonce: window.saltusMetrics.nonce, range, ability });
				const response = await fetch(window.saltusMetrics.ajaxUrl + '?' + params);
				const data = await response.json();
				if (data.success) { setMetrics(data.data.metrics); setAbilities(data.data.abilities || []); }
				else { setError(data.data?.message || __('Failed to load metrics', 'saltus-framework')); setMetrics(null); }
			} catch (err) { setError(__('Network error loading metrics', 'saltus-framework')); setMetrics(null); }
			finally { setLoading(false); }
		}

		const statusMessage = loading ? __('Loading metrics...', 'saltus-framework') : error ? error : __('Metrics loaded', 'saltus-framework');
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
			!loading && !error && metrics ? h('div', { className: 'saltus-metrics-content' },
				h('div', { className: 'saltus-metrics-cards' },
					h(MetricCard, { title: __('Total Calls', 'saltus-framework'), value: metrics.total_calls.toLocaleString() }),
					h(MetricCard, { title: __('Error Rate', 'saltus-framework'), value: (metrics.error_rate * 100).toFixed(2) + '%' }),
					h(MetricCard, { title: __('Avg Latency', 'saltus-framework'), value: metrics.avg_latency_ms.toFixed(1) + ' ms' })
				),
				metrics.sampling?.is_sampled ? h('div', { className: 'saltus-metrics-sampling-notice', role: 'note' }, __('Metrics are sampled. Counts are recorded audit rows, not estimated total calls.', 'saltus-framework')) : null,
				h('div', { className: 'saltus-metrics-chart' }, h('h2', null, __('Calls per Day', 'saltus-framework')), h('canvas', { id: 'saltus-calls-chart', 'aria-label': __('Bar chart showing calls per day', 'saltus-framework') }), h('div', { className: 'saltus-chart-summary' }, __('Chart displays daily call volume. Data available as table below.', 'saltus-framework'))),
				h('div', { className: 'saltus-metrics-table' }, h('h2', null, __('Per-Ability Breakdown', 'saltus-framework')), h(MetricsTable, { data: metrics.per_ability || [], kind: 'ability' })),
				h('div', { className: 'saltus-metrics-table' }, h('h2', null, __('Per-Client Breakdown', 'saltus-framework')), h(MetricsTable, { data: metrics.per_client || [], kind: 'client' }))
			) : null
		);
	}

	function MetricCard({ title, value }) { return h('div', { className: 'saltus-metric-card' }, h('h3', null, title), h('div', { className: 'saltus-metric-value' }, value)); }

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
		return h('table', { className: 'wp-list-table widefat fixed striped' }, h('thead', null, h('tr', null, columns.map(([key, label]) => h(SortableHeader, { key, columnKey: key, currentSortKey: sortKey, sortDir, onSort: toggleSort, label })))), h('tbody', null, sorted.map(row => h('tr', { key: row[nameKey] }, h('td', null, row[nameKey]), h('td', null, row.call_count.toLocaleString()), h('td', null, row.error_count), h('td', null, row.avg_duration_ms.toFixed(1)), h('td', null, row.p95_duration_ms.toFixed(1))))));
	}

	function SortableHeader({ columnKey, currentSortKey, sortDir, onSort, label }) {
		const isSorted = currentSortKey === columnKey;
		const ariaSort = isSorted ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none';
		return h('th', { scope: 'col', 'aria-sort': ariaSort }, h('button', { type: 'button', className: 'saltus-sort-button', onClick: () => onSort(columnKey), 'aria-label': label + (isSorted ? ' (' + (sortDir === 'asc' ? __('sorted ascending', 'saltus-framework') : __('sorted descending', 'saltus-framework')) + ')' : '') }, label, isSorted ? h('span', { className: 'sortable-indicator', 'aria-hidden': 'true' }, sortDir === 'asc' ? ' ^' : ' v') : null));
	}

	const container = document.getElementById('saltus-metrics-dashboard');
	if (container) render(h(MetricsDashboard), container);
})();
