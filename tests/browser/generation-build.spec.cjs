const { test, expect } = require('@playwright/test')
const fs = require('node:fs/promises')
const path = require('node:path')

async function open(page, { failed = false } = {}) {
  const calls = [], errors = []
  let state = { status: 'not_started', documents_done: 0, total_documents: 2, staged_chunks: 0, heartbeat_at: 0, error_code: null, can_start: true, retrieval_changed: false, verified: false }
  page.on('pageerror', e => errors.push(e.message))
  await page.route('http://127.0.0.1:4173/**', async route => {
    const url = new URL(route.request().url())
    if (url.pathname === '/') return route.fulfill({ contentType: 'text/html', body: '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="requesttoken" content="test-token"></head><body><main id="generation-root"></main></body></html>' })
    if (url.pathname.endsWith('.js')) return route.fulfill({ contentType: 'text/javascript', body: await fs.readFile(path.join(__dirname, '../../node_modules/.cache/eva-ai-browser', path.basename(url.pathname))) })
    calls.push({ path: url.pathname, method: route.request().method(), body: route.request().postDataJSON() })
    if (url.pathname.includes('/build')) {
      if (route.request().method() === 'POST') {
        const action = url.pathname.split('/').at(-1)
        if (action === 'start') expect(route.request().postDataJSON().confirm_document_transfer).toBe(true)
        if (failed) return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ ocs: { data: { error: { message: 'The build could not be updated.' } } } }) })
        state = { ...state, status: action === 'pause' ? 'paused' : 'queued', heartbeat_at: 1 }
      }
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ocs: { data: state } }) })
    }
    return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ocs: { data: { items: [{ id: 'a'.repeat(32), status: 'building', embedding_provider: 'ollama', embedding_model: 'exact-embed', dimension: 3, chunk_size: 900, chunk_overlap: 120, created_at: 1 }], next_after: null } } }) })
  })
  await page.goto('http://127.0.0.1:4173/')
  await page.addScriptTag({ url: 'http://127.0.0.1:4173/generations.js' })
  await page.getByRole('combobox').first().click()
  await page.getByRole('option').filter({ has: page.getByTitle('Alice (alice)', { exact: true }) }).click()
  await expect(page.getByRole('combobox')).toHaveCount(2)
  await page.getByRole('combobox').nth(1).click()
  await page.getByRole('option').first().click()
  await expect(page.getByText('Not started', { exact: true })).toBeVisible()
  return { calls, errors, setState: value => { state = { ...state, ...value } } }
}

test('native build consent, pause and resume only update background control', async ({ page }) => {
  const { calls, errors, setState } = await open(page)
  await expect(page.getByRole('button', { name: 'Start background build' })).toBeDisabled()
  expect(calls.filter(c => c.method === 'POST')).toHaveLength(0)
  await page.getByText('Allow document processing for this generation', { exact: true }).click()
  await page.getByRole('button', { name: 'Start background build' }).click()
  await expect(page.getByText('Waiting for the next background tick')).toBeVisible()
  await page.getByRole('button', { name: 'Pause build' }).click()
  await expect(page.getByText('Build paused')).toBeVisible()
  await page.getByRole('button', { name: 'Resume build' }).click()
  await expect(page.getByText('Waiting for the next background tick')).toBeVisible()
  setState({ status: 'staged', documents_done: 2, staged_chunks: 5 })
  await page.getByRole('button', { name: 'Refresh build progress' }).click()
  await expect(page.getByText('Staged; not verified', { exact: true })).toBeVisible()
  await expect(page.getByText('Source processing finished. Complete index verification is still required before search can change.', { exact: true })).toBeVisible()
  expect(calls.filter(c => c.method === 'POST').map(c => c.path.split('/').at(-1))).toEqual(['start', 'pause', 'resume'])
  expect(errors).toEqual([])
})

test('uncertain build controls remain retryable without automatic POST retries', async ({ page }) => {
  const { calls, errors } = await open(page, { failed: true })
  await page.getByText('Allow document processing for this generation', { exact: true }).click()
  await page.getByRole('button', { name: 'Start background build' }).click()
  await expect(page.getByRole('note').filter({ hasText: 'The build could not be updated.' })).toContainText('The build could not be updated.')
  await expect(page.getByRole('button', { name: 'Start background build' })).toBeEnabled()
  expect(calls.filter(c => c.method === 'POST')).toHaveLength(1)
  expect(errors).toEqual([])
})
