const test = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const vm = require('node:vm')

const source = fs.readFileSync(`${__dirname}/../src/lib/metrics-export.js`, 'utf8').replace(/^export /gm, '')
function context() {
	const ctx = vm.createContext({})
	vm.runInContext(`${source}\nthis.buildMetricsCsv = buildMetricsCsv`, ctx)
	return ctx
}

test('CSV report includes the selected period and each supported metric section', () => {
	const csv = context().buildMetricsCsv({
		days: 7,
		generatedAt: '2026-09-30T00:00:00.000Z',
		totals: { requests: 2, input_tokens: 10, output_tokens: 5, total_tokens: 15, estimated_requests: 1 },
		feedbackStats: { helpful: 3, notHelpful: 1, bookmarked: 2 },
		byModel: [{ provider: 'ollama', model: 'llama', requests: 2, input_tokens: 10, output_tokens: 5, total_tokens: 15, estimated_requests: 1, average_duration_ms: 1234, max_duration_ms: 2000, estimated_cost_usd: 0.00005 }],
		daily: [{ day: '2026-09-29', requests: 2, input_tokens: 10, output_tokens: 5, total_tokens: 15 }],
		slowTools: [{ tool: 'search_files', calls: 1, avg_duration_ms: 120, max_duration_ms: 120, errors: 0 }],
	})
	assert.ok(csv.startsWith('\uFEFF'))
	assert.match(csv, /"period_days","7"/)
	assert.match(csv, /"estimated_cost_usd","0.00005"/)
	assert.match(csv, /"average_response_ms","maximum_response_ms","estimated_cost_usd"/)
	assert.match(csv, /"ollama","llama","2","10","5","15","1","1234","2000","0.00005"/)
	assert.match(csv, /"search_files","1","120","120","0"/)
	assert.match(csv, /"helpful_answers_all_time","3"/)
})

test('CSV quotes delimiters and neutralizes spreadsheet formula cells', () => {
	const csv = context().buildMetricsCsv({
		days: 30,
		byModel: [
			{ provider: '=HYPERLINK("https://bad.invalid")', model: '  =1+1', requests: 1 },
			{ provider: 'local', model: 'line 1\nline 2', requests: 1 },
		],
	})
	assert.match(csv, /"'=HYPERLINK\(""https:\/\/bad\.invalid""\)"/)
	assert.match(csv, /"'  =1\+1"/)
	assert.match(csv, /"line 1\nline 2"/)
})
