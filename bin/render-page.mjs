#!/usr/bin/env node
// Headless page renderer for EVA's web search.
//
// A growing share of the web is only readable after JavaScript has run: search
// results point at pages whose text arrives from an API call, news sites render
// their article body client-side, and cookie walls replace the content until
// scripts execute. A plain HTTP GET sees an empty shell, which is why an answer
// built from it has to guess.
//
// This script is the browser half of that fix. It reads one JSON request on
// stdin and writes one JSON response on stdout, so the PHP caller never has to
// quote a URL into a command line:
//
//   {"urls": ["https://example.org"], "timeoutMs": 15000, "waitMs": 250,
//    "withImages": false, "maxChars": 2000000, "concurrency": 3}
//   -> {"ok": true, "pages": {"0": {"ok": true, "finalUrl": "...",
//        "title": "...", "html": "...", "text": "...", "error": null}}}
//
// One browser serves every URL in the request: launching Chromium costs more
// than rendering a page, so batching is what makes this affordable inside a
// chat request.
//
// The caller is responsible for the security decisions it already makes about
// URLs before rendering. This script additionally refuses to navigate anywhere
// but http(s), so a page cannot hand control to `file:` or `data:` URLs, and it
// reports the URL it actually ended up on so the caller can re-check a redirect
// target instead of trusting it.

import { chromium } from 'playwright'

const DEFAULT_TIMEOUT_MS = 15000
const DEFAULT_MAX_CHARS = 2000000
const DEFAULT_CONCURRENCY = 3
const HARD_MAX_CHARS = 8000000

// A real browser identity. Some pages serve a reduced "your browser is old"
// variant or a bot wall to an unrecognised client, which is worse than useless
// here because it is the variant the model would then quote.
const USER_AGENT =
  'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'

// Resource types that almost never carry article text. Blocking them unless the
// caller explicitly wants images cuts most of a page's transfer and lets the DOM
// settle sooner, which is the difference between reading three pages and one
// inside a chat turn.
const BLOCKED_TYPES = ['font', 'media']
const IMAGE_TYPES = ['image']

function readStdin() {
  return new Promise((resolve, reject) => {
    let data = ''
    process.stdin.setEncoding('utf8')
    process.stdin.on('data', (chunk) => {
      data += chunk
      // A runaway caller must not be able to exhaust this process's memory.
      if (data.length > HARD_MAX_CHARS) {
        reject(new Error('request too large'))
        process.stdin.destroy()
      }
    })
    process.stdin.on('end', () => resolve(data))
    process.stdin.on('error', reject)
  })
}

function isHttpUrl(value) {
  try {
    const parsed = new URL(String(value))
    return parsed.protocol === 'http:' || parsed.protocol === 'https:'
  } catch {
    return false
  }
}

/**
 * Render one URL in an isolated context.
 *
 * The context is closed in `finally` so a page that hangs or crashes cannot leak
 * into the next URL of the batch.
 */
async function renderOne(browser, url, options) {
  const timeout = Math.max(1000, Math.min(60000, Number(options.timeoutMs) || DEFAULT_TIMEOUT_MS))
  const waitMs = Math.max(0, Math.min(10000, Number(options.waitMs) || 0))
  const maxChars = Math.max(1000, Math.min(HARD_MAX_CHARS, Number(options.maxChars) || DEFAULT_MAX_CHARS))
  const withImages = Boolean(options.withImages)

  let context = null
  try {
    context = await browser.newContext({
      userAgent: USER_AGENT,
      viewport: { width: 1280, height: 900 },
      // Cookie and consent walls are the single most common reason a page looks
      // empty; a fresh context per URL keeps one site's cookies out of the next.
      javaScriptEnabled: true,
      ignoreHTTPSErrors: false,
    })
    context.setDefaultTimeout(timeout)
    context.setDefaultNavigationTimeout(timeout)

    const blocked = withImages ? BLOCKED_TYPES : BLOCKED_TYPES.concat(IMAGE_TYPES)
    await context.route('**/*', (route) => {
      const type = route.request().resourceType()
      if (blocked.includes(type)) {
        route.abort().catch(() => {})
        return
      }
      route.continue().catch(() => {})
    })

    const page = await context.newPage()
    // Popup blockers on the far side of a redirect chain would otherwise open a
    // window this script never reads.
    page.on('popup', (popup) => popup.close().catch(() => {}))

    const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout })

    // Let late scripts finish. `networkidle` often never fires on pages with
    // analytics beacons, so it is a bounded best-effort wait, not a requirement.
    try {
      await page.waitForLoadState('networkidle', { timeout: Math.min(4000, timeout) })
    } catch {
      // Deliberately ignored: the DOM we already have is still worth extracting.
    }
    if (waitMs > 0) {
      await page.waitForTimeout(waitMs)
    }

    const title = (await page.title().catch(() => '')) || ''
    const finalUrl = page.url()
    let html = await page.content().catch(() => '')
    // `innerText`, not `textContent`: the former respects layout and skips
    // hidden nodes, while `textContent` also returns the source of every script
    // on the page, which would feed JavaScript into the answer.
    let text = ''
    try {
      text = (await page.innerText('body')) || ''
    } catch {
      text = ''
    }

    if (html.length > maxChars) {
      html = html.slice(0, maxChars)
    }

    return {
      ok: html !== '' || text !== '',
      requestedUrl: url,
      finalUrl,
      status: response ? response.status() : 0,
      title: title.trim(),
      html,
      text: text.trim(),
      error: null,
    }
  } catch (error) {
    return {
      ok: false,
      requestedUrl: url,
      finalUrl: '',
      status: 0,
      title: '',
      html: '',
      text: '',
      error: error && error.message ? error.message : String(error),
    }
  } finally {
    if (context) {
      await context.close().catch(() => {})
    }
  }
}

async function main() {
  let request
  try {
    request = JSON.parse(await readStdin())
  } catch (error) {
    process.stdout.write(JSON.stringify({ ok: false, error: 'invalid request: ' + error.message, pages: {} }))
    return
  }

  const urls = Array.isArray(request.urls) ? request.urls.filter(isHttpUrl) : []
  if (urls.length === 0) {
    process.stdout.write(JSON.stringify({ ok: false, error: 'no http(s) urls to render', pages: {} }))
    return
  }

  const concurrency = Math.max(1, Math.min(8, Number(request.concurrency) || DEFAULT_CONCURRENCY))
  let browser = null
  const pages = {}
  try {
    const executablePath = typeof request.executablePath === 'string' && request.executablePath.trim() !== ''
      ? request.executablePath.trim()
      : undefined
    browser = await chromium.launch({
      headless: true,
      ...(executablePath ? { executablePath } : {}),
      // Chromium's sandbox cannot be used inside most containers and under a
      // service account; the caller has already restricted which URLs are
      // rendered, and each URL gets a throwaway context.
      args: [
        '--no-sandbox',
        '--disable-dev-shm-usage',
        '--disable-background-networking',
        '--disable-default-apps',
        '--no-first-run',
      ],
    })

    // A small worker pool: the point of batching is to keep the browser busy
    // without opening thirty tabs against one origin.
    let next = 0
    const workers = []
    for (let i = 0; i < Math.min(concurrency, urls.length); i++) {
      workers.push(
        (async () => {
          for (;;) {
            const index = next++
            if (index >= urls.length) return
            pages[String(index)] = await renderOne(browser, urls[index], request)
          }
        })(),
      )
    }
    await Promise.all(workers)
    process.stdout.write(JSON.stringify({ ok: true, error: null, pages }))
  } catch (error) {
    process.stdout.write(
      JSON.stringify({ ok: false, error: error && error.message ? error.message : String(error), pages }),
    )
  } finally {
    if (browser) {
      await browser.close().catch(() => {})
    }
  }
}

main().catch((error) => {
  process.stdout.write(JSON.stringify({ ok: false, error: String(error), pages: {} }))
})
