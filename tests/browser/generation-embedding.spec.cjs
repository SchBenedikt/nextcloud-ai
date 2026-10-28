const { test, expect } = require('@playwright/test')
const fs = require('node:fs/promises')
const path = require('node:path')

async function openGenerations(page, fail = false) {
  const calls = [], errors = []
  const id = 'a'.repeat(32)
  page.on('pageerror', error => errors.push(error.message))
  await page.route('http://127.0.0.1:4173/**', async route => {
    const url = new URL(route.request().url())
    if (url.pathname === '/') return route.fulfill({ contentType: 'text/html', body: '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="requesttoken" content="test-token"></head><body><main id="generation-root"></main></body></html>' })
    if (url.pathname.endsWith('.js')) return route.fulfill({ contentType: 'text/javascript', body: await fs.readFile(path.join(__dirname, '../../node_modules/.cache/eva-ai-browser', path.basename(url.pathname))) })
    calls.push({ path: url.pathname, method: route.request().method() })
    if (url.pathname.endsWith('/check')) {
      return route.fulfill({ status: fail ? 503 : 200, contentType: 'application/json', body: JSON.stringify({ ocs: { data: fail ? { error: { message: 'The provider returned a different model or vector space.' } } : { available: true, generation_id: id, dimension: 2, retrieval_changed: false, document_content_sent: false } } }) })
    }
    const items = url.pathname.endsWith('/bob') ? [] : [{ id, status: 'building', embedding_provider: 'ollama', embedding_model: 'exact-embed', dimension: 2, chunk_size: 900, chunk_overlap: 120, created_at: 1 }]
    return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ocs: { data: { items, next_after: null } } }) })
  })
  await page.goto('http://127.0.0.1:4173/')
  await page.addScriptTag({ url: 'http://127.0.0.1:4173/generations.js' })
  return { calls, errors }
}

test('native generation selection only probes after explicit consent', async ({ page }) => {
  const { calls, errors } = await openGenerations(page)
  expect(calls).toEqual([])
  await expect(page.getByLabel('Generation owner', { exact: true })).toBeVisible()
  await page.getByRole('combobox').first().click()
  await page.getByRole('option', { name: 'Alice', exact: true }).click()
  await expect(page.getByRole('combobox')).toHaveCount(2)
  await page.getByRole('combobox').nth(1).click()
  await page.getByRole('option').first().click()
  await expect(page.getByText('Vector dimension', { exact: true })).toBeVisible()
  expect(calls.filter(call => call.method === 'POST')).toEqual([])
  await page.getByRole('button', { name: 'Check embedding model', exact: true }).click()
  await expect(page.getByText('The provider returned the expected model and vector dimension. Search is unchanged.', { exact: true })).toBeVisible()
  expect(calls.filter(call => call.method === 'POST')).toHaveLength(1)
  expect(errors).toEqual([])
})

test('a failed provider check stays actionable without automatic retries', async ({ page }) => {
  const { calls, errors } = await openGenerations(page, true)
  await page.getByRole('combobox').first().click()
  await page.getByRole('option', { name: 'Alice', exact: true }).click()
  await expect(page.getByRole('combobox')).toHaveCount(2)
  await page.getByRole('combobox').nth(1).click()
  await page.getByRole('option').first().click()
  await page.getByRole('button', { name: 'Check embedding model', exact: true }).click()
  await expect(page.getByText(/The provider returned a different model or vector space/)).toBeVisible()
  await expect(page.getByRole('button', { name: 'Check embedding model', exact: true })).toBeEnabled()
  expect(calls.filter(call => call.method === 'POST')).toHaveLength(1)
  expect(errors).toEqual([])
})
