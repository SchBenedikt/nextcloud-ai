const path = require('node:path')
const fs = require('node:fs/promises')

const root = path.resolve(__dirname, '../..')
const bundlePath = path.join(root, 'node_modules/.cache/eva-ai-browser/metricsview.js')

async function openMetrics(page, width = 1280) {
	await page.setViewportSize({ width, height: 900 })
	await page.route('http://127.0.0.1:4173/**', async (route) => {
		const pathname = new URL(route.request().url()).pathname
		if (pathname === '/metrics') {
			await route.fulfill({
				contentType: 'text/html',
				body: `<!doctype html><html lang="en"><head><meta name="requesttoken" content="test-token"></head><body><main id="metrics-root"></main><script>window.OC={requestToken:'test-token',generateUrl:(path)=>path,L10N:{translate:(_app,text)=>text,register:()=>{}}}</script></body></html>`,
			})
			return
		}
		try {
			const body = await fs.readFile(path.join(path.dirname(bundlePath), path.basename(pathname)))
			await route.fulfill({ body, contentType: 'text/javascript' })
		} catch (_) { await route.fulfill({ status: 404, body: 'Not found' }) }
	})
	await page.route('**/ocs/v2.php/apps/eva_ai/api/**', async route => {
		const request = route.request()
		const endpoint = new URL(request.url()).pathname.split('/api/').pop()
		let data = {}
		if (endpoint.startsWith('metrics')) data = {
			days: 30,
			totals: { requests: 2, input_tokens: 250000, output_tokens: 100000, total_tokens: 350000, estimated_requests: 0 },
			by_model: [{ provider: 'openai', model: 'gpt-test', requests: 2, input_tokens: 250000, output_tokens: 100000, total_tokens: 350000, estimated_requests: 0, average_duration_ms: 1200, max_duration_ms: 1800, estimated_cost_usd: 1.3 }],
			daily: [], slow_tools: [],
		}
		else if (endpoint === 'feedback/stats') data = { helpful: 2, notHelpful: 0, bookmarked: 1 }
		else if (endpoint === 'settings' && request.method() === 'GET') data = { model_pricing: '[{"provider":"openai","model":"gpt-test","input_per_million":2,"output_per_million":8}]' }
		else if (request.method() !== 'GET') {
			data = JSON.parse(request.postData() || '{}')
		}
		await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data } }) })
	})
	await page.goto('http://127.0.0.1:4173/metrics')
	await page.addScriptTag({ url: 'http://127.0.0.1:4173/metricsview.js' })
	await page.getByRole('heading', { name: 'Estimated model costs' }).waitFor()
}

module.exports = { openMetrics }
