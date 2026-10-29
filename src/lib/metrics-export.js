/** Build a privacy-preserving CSV from the signed-in user's metrics response. */
export function buildMetricsCsv({ days, totals = {}, byModel = [], daily = [], slowTools = [], ragRequestDuration = {}, indexRuns = {}, generatedAt = new Date() } = {}) {
	const rows = [
		['report', 'EVA AI usage metrics'],
		['period_days', days ?? ''],
		['generated_at', generatedAt instanceof Date ? generatedAt.toISOString() : String(generatedAt)],
		[],
		['summary', 'value'],
		['requests', totals.requests ?? 0],
		['input_tokens', totals.input_tokens ?? 0],
		['output_tokens', totals.output_tokens ?? 0],
		['total_tokens', totals.total_tokens ?? 0],
		['estimated_requests', totals.estimated_requests ?? 0],
		['estimated_cost_usd', byModel.reduce((sum, row) => sum + (Number(row.estimated_cost_usd) || 0), 0)],
		[],
		['model_usage'],
		['provider', 'model', 'requests', 'input_tokens', 'output_tokens', 'total_tokens', 'estimated_requests', 'average_duration_ms', 'maximum_duration_ms', 'estimated_cost_usd'],
		...byModel.map(row => [row.provider, row.model, row.requests, row.input_tokens, row.output_tokens, row.total_tokens, row.estimated_requests, row.average_duration_ms, row.max_duration_ms, row.estimated_cost_usd ?? '']),
		[],
		['daily_usage'],
		['day', 'requests', 'input_tokens', 'output_tokens', 'total_tokens'],
		...daily.map(row => [row.day, row.requests, row.input_tokens, row.output_tokens, row.total_tokens]),
		[],
		['slow_tool_calls_over_100ms'],
		['tool', 'calls', 'average_duration_ms', 'maximum_duration_ms', 'errors'],
		...slowTools.map(row => [row.tool, row.calls, row.avg_duration_ms, row.max_duration_ms, row.errors]),
		[],
		['end_to_end_eva_request_duration'],
		['outcome', 'requests', 'p50_ms', 'p95_ms', 'p99_ms'],
		...Object.entries(ragRequestDuration.outcomes || {}).map(([outcome, row]) => [outcome, row.count, row.p50_ms, row.p95_ms, row.p99_ms]),
		[],
		['indexing_run_performance'],
		['mode', 'outcome', 'runs', 'processed_items', 'file_chunks', 'average_file_chunks_per_second', 'run_p50_ms', 'run_p95_ms', 'run_p99_ms'],
		...Object.entries(indexRuns.modes || {}).flatMap(([mode, outcomes]) => Object.entries(outcomes || {}).map(([outcome, row]) => [mode, outcome, row.runs, row.items, row.file_chunks, row.average_file_chunks_per_second, row.p50_ms, row.p95_ms, row.p99_ms])),
	]

	const cell = value => {
		let text = value === null || value === undefined ? '' : String(value)
		// Excel and other spreadsheet applications evaluate cells starting with
		// these characters as formulas. Prefix text values before CSV quoting.
		if (/^[\u0000-\u0020]*[=+\-@]/.test(text)) text = "'" + text
		return '"' + text.replace(/"/g, '""') + '"'
	}
	return '\uFEFF' + rows.map(row => row.map(cell).join(',')).join('\r\n') + '\r\n'
}
