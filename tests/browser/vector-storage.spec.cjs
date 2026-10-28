const { test, expect } = require('@playwright/test')
const fs = require('node:fs/promises')
const path = require('node:path')

async function openStorage(page, { failSave = false } = {}) {
  const calls = []
  const errors = []
  let requested = false
  page.on('pageerror', error => errors.push(error.message))
  await page.route('http://127.0.0.1:4173/**', async route => {
    const url = new URL(route.request().url())
    if (url.pathname === '/') {
      return route.fulfill({ contentType: 'text/html', body: '<!doctype html><html lang="en"><head><meta name="requesttoken" content="test-token"></head><body><div id="vector-status-root"></div></body></html>' })
    }
    if (url.pathname.endsWith('.js')) {
      return route.fulfill({ contentType: 'text/javascript', body: await fs.readFile(path.join(__dirname, '../../node_modules/.cache/eva-ai-browser', path.basename(url.pathname))) })
    }
    const endpoint = url.pathname.split('/api/')[1] || ''
    const method = route.request().method()
    calls.push({ endpoint, method, body: route.request().postDataJSON() })
    if (endpoint === 'admin/vector-storage/points' && method === 'POST') {
      if (failSave) return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ error: { message: 'Unavailable' } }) })
      requested = calls.at(-1).body.enabled
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ocs: { data: { points_requested: requested } } }) })
    }
    const data = { available: true, retrieval_backend: 'sql_legacy', connection: { configured: false, enabled: false }, erasure_worker_enabled: false,
      chunk_sync_available: true, points_requested: requested, point_worker_enabled: false, staged_chunks: 0, queue: { pending: 0, processing: 0, dead: 0 } }
    return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ocs: { data } }) })
  })
  await page.goto('http://127.0.0.1:4173/')
  await page.addScriptTag({ url: 'http://127.0.0.1:4173/vectorstatus.js' })
  return { calls, errors }
}

test('point delivery requires explicit native consent and can be paused separately', async ({ page }) => {
  const { calls, errors } = await openStorage(page)
  const checkbox = page.getByRole('checkbox', { name: 'Allow prepared vector point delivery', exact: true })
  await expect(checkbox).not.toBeChecked()
  expect(calls.filter(call => call.method === 'POST')).toEqual([])
  await page.getByText('Allow prepared vector point delivery', { exact: true }).click()
  await expect.poll(() => calls.filter(call => call.method === 'POST').at(-1)?.body.enabled).toBe(true)
  await expect(checkbox).toBeChecked()
  await expect(checkbox).toBeEnabled()
  await page.getByText('Allow prepared vector point delivery', { exact: true }).click()
  await expect.poll(() => calls.filter(call => call.method === 'POST').at(-1)?.body.enabled).toBe(false)
  await expect(checkbox).not.toBeChecked()
  expect(errors).toEqual([])
})

test('failed consent save restores server state and shows an actionable error', async ({ page }) => {
  const { errors } = await openStorage(page, { failSave: true })
  const checkbox = page.getByRole('checkbox', { name: 'Allow prepared vector point delivery', exact: true })
  await page.getByText('Allow prepared vector point delivery', { exact: true }).click()
  await expect(page.getByText('Point delivery settings could not be saved. Refresh the storage status before retrying.', { exact: true })).toBeVisible()
  await expect(checkbox).not.toBeChecked()
  await expect(page.getByRole('button', { name: 'Disable storage requests', exact: true })).toBeVisible()
  expect(errors).toEqual([])
})
