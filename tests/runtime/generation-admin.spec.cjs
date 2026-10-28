const fs = require('node:fs')
const { test, expect } = require('@playwright/test')

const base = process.env.EVA_TEST_BASE_URL?.replace(/\/$/, '')
const credentialsFile = process.env.EVA_TEST_CREDENTIALS_FILE
const regularFile = process.env.EVA_TEST_NONADMIN_CREDENTIALS_FILE
const metadataFile = process.env.EVA_TEST_GENERATION_METADATA_FILE
const counterFile = process.env.EVA_EMBED_FIXTURE_COUNTER

test.describe('installed generation administration', () => {
  test.skip(!base || !credentialsFile || !regularFile || !metadataFile || !counterFile, 'Requires disposable native audit owners and the loopback fixture.')
  test.describe.configure({ mode: 'serial' })
  let admin, regular, page, metadata
  const errors = []
  const api = path => `${base}/ocs/v2.php/apps/eva_ai/api/${path}?format=json`
  const requests = () => fs.existsSync(counterFile) ? Number(fs.readFileSync(counterFile, 'utf8')) : 0
  async function login(browser, file) {
    const credentials = JSON.parse(fs.readFileSync(file, 'utf8'))
    if (!credentials.user.startsWith('eva-runtime-audit-')) throw new Error('Use disposable audit accounts only.')
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } })
    const page = await context.newPage()
    await page.goto(`${base}/index.php/apps/eva_ai/`, { waitUntil: 'domcontentloaded' })
    await page.locator('input[name="user"]').fill(credentials.user)
    await page.locator('input[name="password"]').fill(credentials.password)
    await page.getByRole('button', { name: 'Log in', exact: true }).click()
    await page.waitForURL(url => url.pathname.includes('/apps/eva_ai'), { waitUntil: 'domcontentloaded' })
    return { context, page, user: credentials.user }
  }
  test.beforeAll(async ({ browser }) => {
    metadata = JSON.parse(fs.readFileSync(metadataFile, 'utf8'))
    admin = await login(browser, credentialsFile)
    regular = await login(browser, regularFile)
    page = admin.page
    page.on('pageerror', error => errors.push(error.message))
  })
  test.afterAll(async () => { await admin?.context.close(); await regular?.context.close() })

  test('metadata loads locally and the native explicit probe respects origin denial', async () => {
    const before = requests()
    await page.goto(`${base}/index.php/apps/eva_ai/admin`, { waitUntil: 'domcontentloaded' })
    await expect(page.getByRole('heading', { name: 'Embedding generations', exact: true })).toBeVisible()
    await page.getByLabel('Generation owner', { exact: true }).click()
    await page.getByRole('option').filter({ has: page.getByTitle(`EVA Runtime Audit (${metadata.user})`, { exact: true }) }).click()
    await expect(page.getByLabel('Prepared generation', { exact: true })).toBeVisible()
    await page.getByLabel('Prepared generation', { exact: true }).click()
    await page.getByRole('option').first().click()
    await expect(page.locator('.generation-details')).toContainText('fixture-embed')
    expect(requests()).toBe(before)
    await page.getByRole('button', { name: 'Check embedding model', exact: true }).click()
    await expect(page.getByRole('alert').filter({ hasText: 'The embedding destination is not allowed. Check the administrator origin policy.' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Check embedding model', exact: true })).toBeEnabled()
    expect(requests()).toBe(before)
    expect(errors).toEqual([])
  })

  test('actual metadata response omits destinations and credentials', async () => {
    const response = await admin.context.request.get(api(`admin/vector-generations/${encodeURIComponent(metadata.user)}`), { headers: { 'OCS-APIRequest': 'true' } })
    expect(response.ok()).toBe(true)
    const data = (await response.json()).ocs.data
    expect(data.items).toHaveLength(1)
    expect(data.items[0]).toMatchObject({ id: metadata.generation, dimension: 3, status: 'building' })
    expect(Object.keys(data.items[0]).sort()).toEqual(['id', 'status', 'embedding_provider', 'profile_id', 'embedding_model', 'dimension', 'chunk_size', 'chunk_overlap', 'created_at'].sort())
  })

  test('a normal account cannot list or probe an administrator generation', async () => {
    const before = requests()
    const token = await regular.page.locator('meta[name="requesttoken"]').getAttribute('content')
    const headers = { 'OCS-APIRequest': 'true', requesttoken: token }
    const listing = await regular.context.request.get(api(`admin/vector-generations/${encodeURIComponent(metadata.user)}`), { headers })
    expect(listing.status()).toBe(403)
    const probe = await regular.context.request.post(api(`admin/vector-generations/${encodeURIComponent(metadata.user)}/${metadata.generation}/check`), { data: {}, headers })
    expect(probe.status()).toBe(403)
    expect(requests()).toBe(before)
  })
})
