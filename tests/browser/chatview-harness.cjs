const path = require('node:path')
const fs = require('node:fs/promises')

const root = path.resolve(__dirname, '../..')
const bundlePath = path.join(root, 'node_modules/.cache/eva-ai-browser/chatview.js')

function html() {
  return `<!doctype html><html lang="en"><head>
    <meta name="eva-ai-api" content="/apps/eva_ai/api">
    <meta name="eva-ai-stream" content="/apps/eva_ai/api/streamChat">
    <meta name="requesttoken" content="test-token">
  </head><body><main id="chat-root"></main><script>
    window.__calls = []
    window.__generatedImages = [
      { id: 11, name: 'eva-generated-1.png', mime: 'image/png', previewUrl: '/preview/11', downloadUrl: '/download/11' },
      { id: 12, name: 'eva-generated-2.webp', mime: 'image/webp', previewUrl: '/preview/12', downloadUrl: '/download/12' }
    ]
    window.fetch = async function (url, options) {
      const method = options && options.method || 'GET'
      let body = null
      try { body = options && options.body ? JSON.parse(options.body) : null } catch (_) {}
      window.__calls.push({ url: String(url), method, body })
      const path = String(url)
      let data = {}
      if (path.endsWith('/images') && method === 'GET') data = { images: window.__generatedImages }
      else if (path.endsWith('/images/generate') && method === 'POST') data = { images: window.__generatedImages }
      else if (path.endsWith('/chats') && method === 'POST') data = { id: 'generated-test-chat', rev: 1 }
      else if (path.endsWith('/backgroundChat') && method === 'GET') data = { items: [] }
      else if (path.endsWith('/streamChat') && method === 'POST') data = { stream: true }
      return new Response(JSON.stringify(data), { status: 200, headers: { 'Content-Type': 'application/json' } })
    }
  </script><style>html,body{margin:0;min-height:100%;}#chat-root{height:100vh;min-height:0}</style></body></html>`
}

async function openChatView(page) {
  page.on('pageerror', (error) => console.error('[chatview pageerror]', error))
  await page.route('http://127.0.0.1:4173/**', async (route) => {
    const pathname = new URL(route.request().url()).pathname
    if (pathname === '/harness') {
      await route.fulfill({ body: html(), contentType: 'text/html' })
      return
    }
    const name = path.basename(pathname)
    try {
      const body = await fs.readFile(path.join(path.dirname(bundlePath), name))
      await route.fulfill({ body, contentType: name.endsWith('.js') ? 'text/javascript' : 'application/octet-stream' })
    } catch (_) {
      await route.fulfill({ status: 404, body: 'Not found' })
    }
  })
  await page.goto('http://127.0.0.1:4173/harness')
  await page.addScriptTag({ url: 'http://127.0.0.1:4173/chatview.js' })
  await page.waitForSelector('.head .image-mode-toggle')
}

module.exports = { openChatView }
