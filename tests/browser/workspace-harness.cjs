const path = require('node:path')
const fs = require('node:fs/promises')
const vm = require('node:vm')

const root = path.resolve(__dirname, '../..')
const cache = path.join(root, 'node_modules/.cache/eva-ai-browser')

async function openWorkspace(page, { view = '', width = 1280, chats = [], fileIds = [], backgroundItems = [] } = {}) {
	const calls = []
	const errors = []
	page.on('pageerror', error => errors.push(String(error)))
	page.on('console', message => { if (message.type() === 'warning' && message.text().includes('not defined')) errors.push(message.text()) })
	const source = await fs.readFile(path.join(root, 'src/lib/settings-defaults.js'), 'utf8')
	const settings = vm.runInNewContext('(' + source.slice(source.indexOf('return ') + 7, source.lastIndexOf('}')).trim() + ')')
	const status = () => ({ settings, models: ['test-chat', 'test-embedding'], provider: { roles: {} }, ollamaOnline: true, indexing: false, documents: 0, chunks: 0, groq: { keyConfigured: false }, providerKeys: {}, limits: {} })
	await page.setViewportSize({ width, height: 900 })
	await page.route('http://127.0.0.1:4173/**', async route => {
		const url = new URL(route.request().url())
		if (url.pathname === '/apps/eva_ai/' || url.pathname.startsWith('/apps/eva_ai/settings')) {
			await route.fulfill({ contentType: 'text/html', body: `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="requesttoken" content="test-token"><meta name="eva-ai-api" content="/ocs/v2.php/apps/eva_ai/api"><meta name="eva-ai-stream" content="/ocs/v2.php/apps/eva_ai/api/streamChat"></head><body><div id="header"></div><div id="content"><div id="eva_ai-root"></div></div><script>window.OC={requestToken:'test-token',currentUser:'alice',config:{modRewriteWorking:true},generateUrl:p=>p,L10N:{translate:(_app,t)=>t,register:()=>{}},getCapabilities:()=>({})};</script><style>:root{--color-main-background:#fff;--color-main-text:#222;--color-text-maxcontrast:#666;--color-border:#ddd;--color-background-hover:#f5f5f5;--color-primary-element:#00679c;--color-primary-element-text:#fff;--color-primary-element-light:#e7f2fa;--color-primary-element-light-text:#00679c;--default-clickable-area:44px;--border-radius:8px;--border-radius-large:12px;--border-radius-pill:22px;--header-height:50px;--body-container-height:calc(100vh - 50px)}*{box-sizing:border-box}body{margin:0;font:15px system-ui}button,input,textarea,select{font:inherit}#header{height:50px;background:#00679c}#content{height:calc(100vh - 50px);position:relative}#eva_ai-root{height:100%}</style></body></html>` })
			return
		}
		try {
			await route.fulfill({ body: await fs.readFile(path.join(cache, path.basename(url.pathname))), contentType: 'text/javascript' })
		} catch { await route.fulfill({ status: 404, body: 'Not found' }) }
	})
	await page.route('**/ocs/v2.php/apps/eva_ai/api/**', async route => {
		const request = route.request()
		const endpoint = new URL(request.url()).pathname.split('/api/')[1]
		const body = request.postDataJSON()
		calls.push({ endpoint, method: request.method(), body })
		let data = {}
		if (endpoint === 'chats') { if (request.method() === 'POST') { data = { id: 'new-chat', title: 'New chat', updated: 123, count: 0 }; chats.push(data) } else data = chats }
		else if (endpoint === 'status') data = status()
		else if (endpoint === 'settings' && request.method() === 'PUT') { Object.assign(settings, body); delete settings.custom_provider_api_key; data = settings }
		else if (endpoint === 'backgroundChat' && request.method() === 'GET') data = { items: backgroundItems }
		else if (endpoint === 'models') data = { models: [] }
		else if (endpoint === 'documents') data = { documents: [], total: 0, totalChunks: 0, totalSize: 0 }
		else if (endpoint === 'index/status') data = { indexing: false, indexStopping: false, indexMode: '', lastError: '' }
		else if (endpoint === 'fileContextStatus') data = { files: [{ fileId: 11, name: 'Sample notes.md' }], missing: [] }
		else if (endpoint === 'fileContextChat') data = { answer: '**Key point**\n\n- A readable answer\n\n<script>window.__unsafe = true</script>', sources: [] }
		else if (endpoint === 'health') data = { ok: true, checks: { chat_model: true }, index: {}, provider: { online: true }, queue: { active: 0 } }
		else if (endpoint.startsWith('chats/')) data = { id: endpoint.split('/')[1], title: 'A conversation', messages: [] }
		else if (endpoint === 'streamChat') { await route.fulfill({ contentType: 'application/x-ndjson', body: '{"type":"done","answer":"A verified reply","sources":[],"followups":[]}\n' }); return }
		await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ocs: { meta: { statuscode: 200 }, data } }) })
	})
	const query = new URLSearchParams()
	if (view) query.set('view', view)
	if (fileIds.length) query.set('fileIds', fileIds.join(','))
	await page.goto('http://127.0.0.1:4173/apps/eva_ai/?' + query)
	await page.addScriptTag({ url: 'http://127.0.0.1:4173/workspace.js' })
	return { calls, errors }
}

module.exports = { openWorkspace }
