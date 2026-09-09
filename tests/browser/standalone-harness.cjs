// Shared browser-test harness for the standalone chat (Issue #133): builds the
// standalone page with a mocked API driven by window.__mock, so the real built
// bundle can be exercised without a Nextcloud or Ollama instance.
const path = require('node:path')
const fs = require('node:fs')

const root = path.resolve(__dirname, '../..')
const bundlePath = path.join(root, 'js/eva_ai_standalone.js')

const API_BASE = '/apps/eva_ai/api'
const STREAM_URL = API_BASE + '/chat/stream'

// The inline mock runs before the bundle and is driven by window.__mock,
// which each test seeds (openChat) or mutates (page.evaluate) before
// triggering an action.
function htmlWith(initialMock) {
  return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="eva-ai-api" content="${API_BASE}">
  <meta name="eva-ai-stream" content="${STREAM_URL}">
  <meta name="requesttoken" content="test-token">
</head>
<body>
  <div id="topbar"><a class="brand" href="/"><span>EVA</span></a><div class="spacer"></div></div>
  <div id="layout">
    <nav id="sidebar">
      <button id="newchat" class="nav-new">+ New chat</button>
      <div id="chatlist"></div>
      <div class="sidebar-spacer"></div>
      <a class="nav-item" href="/documents"><span class="nav-ico">📄</span> Documents</a>
      <a class="nav-item" href="/settings"><span class="nav-ico">⚙️</span> Settings</a>
    </nav>
    <div id="content">
      <div class="head">
        <div class="head-left"><h1>Chat with your files</h1></div>
        <div class="head-right"><button id="export" class="export-btn" disabled>&#11015; Export</button><span class="badge">eva_ai</span></div>
      </div>
      <div id="msgs">
        <div class="empty" id="empty"><div class="ico">💬</div><div class="t">Ask a question about your files</div><div class="d">Ask about notes, plans or files.</div></div>
      </div>
      <form class="form" id="form"><input id="q" type="text" autocomplete="off" placeholder="What does my note about X say?"><button type="submit" id="send">Send</button></form>
      <div class="err" id="err" style="display:none;"></div>
    </div>
  </div>
  <script>
  window.__mock = Object.assign({
    chats: [],
    createChat: { id: 'c1', title: '', rev: 1 },
    detail: null,
    confirmTool: { status: 200, data: { ok: true, result: { url: 'https://cloud.example/s/abc' } } },
    streamLines: [],
    streamMode: 'sync',
    streamController: null,
    saved: [],
    aborted: 0,
  }, ${initialMock || '{}'})
  window.fetch = function (url, opts) {
    opts = opts || {}
    const u = String(url)
    const method = opts.method || 'GET'
    let body = null
    try { body = opts.body ? JSON.parse(opts.body) : null } catch (_) {}
    window.__mock.saved.push({ url: u, method, body })

    const abortError = Object.assign(new Error('The operation was aborted.'), { name: 'AbortError' })
    const onAbort = new Promise((_, reject) => {
      if (!opts.signal) return
      if (opts.signal.aborted) {
        window.__mock.aborted++
        return reject(abortError)
      }
      opts.signal.addEventListener('abort', () => {
        window.__mock.aborted++
        reject(abortError)
      }, { once: true })
    })

    const json = (status, data) => Promise.race([onAbort, Promise.resolve(new Response(JSON.stringify(data), {
      status,
      headers: { 'Content-Type': 'application/json' },
    }))])

    if (u.includes('/chat/stream')) {
      if (window.__mock.streamMode === 'open') {
        // Stay open until the client aborts (Stop button): the abort must also
        // error the body stream so readNdjson surfaces the cancellation.
        return new Promise((resolve) => {
          const stream = new ReadableStream({
            start(controller) {
              window.__mock.streamController = controller
              if (opts.signal) {
                opts.signal.addEventListener('abort', () => {
                  // The generic listener above already counts the abort.
                  controller.error(abortError)
                }, { once: true })
              }
            }
          })
          resolve(new Response(stream, { status: 200, headers: { 'Content-Type': 'application/x-ndjson' } }))
        })
      }
      return Promise.race([onAbort, Promise.resolve().then(() => {
        const encoder = new TextEncoder()
        const stream = new ReadableStream({
          start(controller) {
            ;(window.__mock.streamLines || []).forEach((item) => controller.enqueue(encoder.encode(item + '\\n')))
            controller.close()
          }
        })
        return new Response(stream, { status: 200, headers: { 'Content-Type': 'application/x-ndjson' } })
      })])
    }

    if (u.endsWith('/api/chats') && method === 'GET') return json(200, window.__mock.chats)
    if (u.endsWith('/api/chats') && method === 'POST') return json(200, window.__mock.createChat)
    if (u.includes('/api/chats/') && method === 'GET') return json(200, window.__mock.detail)
    if (u.includes('/api/chats/') && method === 'POST') {
      window.__mock.messages = window.__mock.messages || []
      window.__mock.messages.push(body)
      return json(200, { ok: true, rev: window.__mock.messages.length + 1 })
    }
    if (u.includes('/confirmTool') && method === 'POST') {
      return json(window.__mock.confirmTool.status, window.__mock.confirmTool.data)
    }
    return json(404, { error: 'unhandled ' + u })
  }
  </script>
</body>
</html>`
}

async function openChat(page, initialMock) {
  if (!fs.existsSync(bundlePath)) {
    throw new Error('Missing ' + bundlePath + ' - run `npm run build` before the browser tests')
  }
  await page.setContent(htmlWith(initialMock))
  await page.addScriptTag({ path: bundlePath })
  // The initial refreshChats() list request must have been issued.
  await page.waitForFunction(() => window.__mock && window.__mock.saved.length > 0)
}

const line = (obj) => JSON.stringify(obj)

const messagePosts = (saved) => saved.filter((r) => r.url.includes('/messages') && r.method === 'POST')

module.exports = { openChat, htmlWith, line, messagePosts, bundlePath }