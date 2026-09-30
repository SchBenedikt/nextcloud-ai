/** Build a privacy-preserving CSV from the signed-in user's metrics response. */
export function buildMetricsCsv({ days, totals = {}, byModel = [], daily = [], slowTools = [], feedbackStats = {}, generatedAt = new Date() } = {}) {
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
		['helpful_answers_all_time', feedbackStats.helpful ?? 0],
		['not_helpful_answers_all_time', feedbackStats.notHelpful ?? 0],
		['bookmarked_answers_all_time', feedbackStats.bookmarked ?? 0],
		[],
		['model_usage'],
		['provider', 'model', 'requests', 'input_tokens', 'output_tokens', 'total_tokens', 'estimated_requests', 'average_response_ms', 'maximum_response_ms'],
		...byModel.map(row => [row.provider, row.model, row.requests, row.input_tokens, row.output_tokens, row.total_tokens, row.estimated_requests, row.average_duration_ms, row.max_duration_ms]),
		[],
		['daily_usage'],
		['day', 'requests', 'input_tokens', 'output_tokens', 'total_tokens'],
		...daily.map(row => [row.day, row.requests, row.input_tokens, row.output_tokens, row.total_tokens]),
		[],
		['slow_tool_calls_over_100ms'],
		['tool', 'calls', 'average_duration_ms', 'maximum_duration_ms', 'errors'],
		...slowTools.map(row => [row.tool, row.calls, row.avg_duration_ms, row.max_duration_ms, row.errors]),
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
