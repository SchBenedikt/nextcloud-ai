'use strict'

// The renderer is the difference between reading a page and reading its shell.
//
// Every page in this test builds its text in JavaScript, so a plain HTTP GET
// returns markup with no content at all. If the assertions below pass, a browser
// really ran and really executed the page's scripts - which is the whole claim
// the web search makes when it falls back to rendering.

const test = require('node:test')
const assert = require('node:assert')
const http = require('node:http')
const path = require('node:path')
const { spawn } = require('node:child_process')

const root = path.resolve(__dirname, '..')
const renderer = path.join(root, 'bin/render-page.mjs')

/** Serve fixtures on an ephemeral port and hand back the base URL. */
async function withServer(routes, run) {
  const server = http.createServer((req, res) => {
    const handler = routes[req.url]
    if (!handler) {
      res.writeHead(404, { 'content-type': 'text/html' })
      res.end('<!doctype html><title>missing</title><p>not found</p>')
      return
    }
    handler(res)
  })
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve))
  const { port } = server.address()
  try {
    return await run('http://127.0.0.1:' + port)
  } finally {
    await new Promise((resolve) => server.close(resolve))
  }
}

/**
 * Run the renderer with one request on stdin.
 *
 * The timeout is generous: a cold Chromium start on a loaded machine is slow,
 * and a flaky test would be worse than a slow one.
 */
function render(request, timeoutMs = 90000) {
  return new Promise((resolve, reject) => {
    const child = spawn(process.execPath, [renderer], { cwd: root })
    let out = ''
    let err = ''
    const timer = setTimeout(() => {
      child.kill('SIGKILL')
      reject(new Error('renderer did not finish in time'))
    }, timeoutMs)
    child.stdout.on('data', (chunk) => (out += chunk))
    child.stderr.on('data', (chunk) => (err += chunk))
    child.on('error', reject)
    child.on('close', () => {
      clearTimeout(timer)
      try {
        resolve(JSON.parse(out))
      } catch (error) {
        reject(new Error('renderer produced no JSON (' + error.message + '): ' + out.slice(0, 400) + err.slice(0, 400)))
      }
    })
    child.stdin.end(JSON.stringify(request))
  })
}

/**
 * Playwright without its browser binary is a setup problem, not a defect.
 *
 * CI installs Chromium before this file runs, so the skip is for a contributor's
 * machine; every other failure still fails, which is what keeps the suite from
 * going quietly green when the renderer itself is broken.
 */
function skipWithoutBrowser(t, result) {
  const message = String((result && result.error) || '')
  if (/Executable doesn't exist|playwright install/i.test(message)) {
    t.skip('Chromium is not installed here - run "npx playwright install chromium"')
    return true
  }
  return false
}

test('a page whose text only exists after JS runs is read correctly', async (t) => {
  const routes = {
    '/client-side': (res) => {
      res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' })
      res.end(
        '<!doctype html><html><head><title>Late content</title></head><body>' +
          '<div id="root">Loading…</div>' +
          '<script>' +
          'document.getElementById("root").textContent = "Die Wartung findet am Freitag statt.";' +
          'var p = document.createElement("p");' +
          'p.textContent = "Antwort nach JavaScript.";' +
          'document.body.appendChild(p);' +
          '</script></body></html>',
      )
    },
  }

  await withServer(routes, async (base) => {
    const result = await render({ urls: [base + '/client-side'], timeoutMs: 20000 })
    if (skipWithoutBrowser(t, result)) return

    assert.equal(result.ok, true, JSON.stringify(result))
    const page = result.pages['0']
    assert.equal(page.ok, true, JSON.stringify(page))
    assert.equal(page.title, 'Late content')
    // The static response contained only "Loading…"; anything more came from the
    // page's own script running inside the browser.
    assert.match(page.text, /Die Wartung findet am Freitag statt\./)
    assert.match(page.text, /Antwort nach JavaScript\./)
    assert.doesNotMatch(page.text, /Loading…/)
    assert.match(page.html, /Die Wartung findet am Freitag statt\./)
  })
})

test('the final URL is reported so the caller can re-check a redirect', async (t) => {
  const routes = {
    '/start': (res) => {
      res.writeHead(302, { location: '/landed' })
      res.end()
    },
    '/landed': (res) => {
      res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' })
      res.end('<!doctype html><title>Landed</title><p>Redirect target body.</p>')
    },
  }

  await withServer(routes, async (base) => {
    const result = await render({ urls: [base + '/start'], timeoutMs: 20000 })
    if (skipWithoutBrowser(t, result)) return
    const page = result.pages['0']
    assert.equal(page.ok, true, JSON.stringify(page))
    assert.equal(page.finalUrl, base + '/landed')
    assert.match(page.text, /Redirect target body\./)
  })
})

test('several URLs share one browser and keep their own result', async (t) => {
  const routes = {}
  for (const name of ['eins', 'zwei', 'drei']) {
    routes['/' + name] = (res) => {
      res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' })
      // The text is created by the page's own script, so a static fetch of this
      // response yields an empty body.
      res.end(
        '<!doctype html><html><head><title>' +
          name +
          '</title></head><body><div id="root"></div><script>document.getElementById("root").textContent = "Inhalt ' +
          name +
          '";</script></body></html>',
      )
    }
  }

  await withServer(routes, async (base) => {
    const urls = ['/eins', '/zwei', '/drei'].map((p) => base + p)
    const result = await render({ urls, timeoutMs: 20000, concurrency: 3 })
    if (skipWithoutBrowser(t, result)) return

    assert.equal(result.ok, true, JSON.stringify(result))
    // Index order is the caller's order, which is how a result is matched back to
    // the hit it came from.
    for (const [index, name] of ['eins', 'zwei', 'drei'].entries()) {
      const page = result.pages[String(index)]
      assert.equal(page.ok, true, JSON.stringify(page))
      assert.match(page.text, new RegExp('Inhalt ' + name))
    }
  })
})

test('a non-http scheme is refused before anything is launched', async () => {
  const result = await render({ urls: ['file:///etc/passwd', 'data:text/html,<p>x</p>'], timeoutMs: 20000 })
  assert.equal(result.ok, false)
  assert.match(result.error, /no http\(s\) urls/)
  assert.deepEqual(result.pages, {})
})

test('an unreachable URL fails that page without failing the request', async (t) => {
  const result = await render(
    { urls: ['http://127.0.0.1:9/nowhere'], timeoutMs: 8000, concurrency: 1 },
    60000,
  )
  if (skipWithoutBrowser(t, result)) return
  // Port 9 (discard) refuses immediately, so the page must report an error and
  // the caller must still get a well-formed response it can act on.
  assert.equal(result.ok, true, JSON.stringify(result))
  const page = result.pages['0']
  assert.equal(page.ok, false, JSON.stringify(page))
  assert.ok(page.error)
})
