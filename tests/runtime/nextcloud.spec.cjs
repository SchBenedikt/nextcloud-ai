const fs = require('node:fs')
const { test, expect } = require('@playwright/test')

const base = process.env.EVA_TEST_BASE_URL?.replace(/\/$/, '')
const credentialsFile = process.env.EVA_TEST_CREDENTIALS_FILE

test.describe('authenticated Nextcloud runtime', () => {
	test.skip(!base || !credentialsFile, 'Requires a dedicated local audit account and credential file.')
	test.describe.configure({ mode: 'serial' })
	let context, page
	const errors = []
	const app = path => `${base}/index.php/apps/eva_ai/${path}`
	const api = path => `${base}/ocs/v2.php/apps/eva_ai/api/${path}?format=json`

	test.beforeAll(async ({ browser }) => {
		const credentials = JSON.parse(fs.readFileSync(credentialsFile, 'utf8'))
		if (!credentials.user.startsWith('eva-runtime-audit-')) throw new Error('Use a dedicated eva-runtime-audit-* account, never a personal account.')
		context = await browser.newContext({ viewport: { width: 1440, height: 1000 } })
		page = await context.newPage()
		page.on('pageerror', error => errors.push(error.message))
		await page.goto(app(''), { waitUntil: 'domcontentloaded' })
		if (new URL(page.url()).pathname.includes('/login')) {
			await page.locator('input[name="user"]').fill(credentials.user)
			await page.locator('input[name="password"]').fill(credentials.password)
			await page.getByRole('button', { name: 'Log in', exact: true }).click()
			await page.waitForURL(url => url.pathname.includes('/apps/eva_ai'), { waitUntil: 'domcontentloaded' })
		}
		await expect(page.getByRole('textbox', { name: 'Message EVA', exact: true })).toBeVisible()
	})

	test.afterAll(async () => { await context?.close() })

	test('native pages load without runtime errors', async () => {
		for (const [route, heading] of [['documents', 'Documents'], ['settings', 'AI models'], ['admin', 'Admin dashboard'], ['metrics', 'Usage metrics'], ['runs', 'Background tasks']]) {
			await page.goto(app(route), { waitUntil: 'domcontentloaded' })
			await expect(page.getByRole('heading', { name: heading, exact: true }).first()).toBeVisible()
			await expect(page.locator('#eva_ai-error')).not.toBeVisible()
		}
		expect(errors).toEqual([])
	})

	test('home fits the native mobile viewport without scrolling', async () => {
		await page.setViewportSize({ width: 390, height: 844 })
		try {
			await page.goto(app(''), { waitUntil: 'domcontentloaded' })
			await expect(page.getByRole('textbox', { name: 'Message EVA', exact: true })).toBeInViewport()
			await expect(page.getByRole('button', { name: 'Send', exact: true })).toBeInViewport()
			expect(await page.locator('.home-view').evaluate(element => element.scrollHeight <= element.clientHeight + 1)).toBe(true)
			expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
		} finally { await page.setViewportSize({ width: 1440, height: 1000 }) }
	})

	test('native settings autosave and survive reload', async () => {
		await page.goto(app('settings?section=knowledge'), { waitUntil: 'domcontentloaded' })
		const input = page.getByRole('spinbutton', { name: 'Sources per answer', exact: true })
		await expect(input).toBeEnabled()
		const original = await input.inputValue()
		const value = original === '7' ? '6' : '7'
		try {
			await input.fill(value)
			await input.blur()
			await expect.poll(async () => {
				const response = await context.request.get(api('settings'), { headers: { 'OCS-APIRequest': 'true' } })
				expect(response.ok()).toBe(true)
				return String((await response.json()).ocs.data.top_k)
			}).toBe(value)
			await page.reload({ waitUntil: 'domcontentloaded' })
			await expect(input).toHaveValue(value)
		} finally {
			const token = await page.locator('meta[name="requesttoken"]').getAttribute('content')
			const response = await context.request.put(api('settings'), { data: { top_k: original }, headers: { requesttoken: token, 'OCS-APIRequest': 'true' } })
			expect(response.ok()).toBe(true)
		}
	})

	test('two real chat requests finish and survive reload', async () => {
		test.setTimeout(180000)
		await page.goto(app(''), { waitUntil: 'domcontentloaded' })
		await page.getByRole('textbox', { name: 'Message EVA', exact: true }).fill('Reply exactly: EVA runtime ready.')
		await page.getByRole('button', { name: 'Send', exact: true }).click()
		await expect(page.locator('.rm.assistant').last()).toContainText('ready', { timeout: 75000 })
		await page.getByRole('textbox', { name: 'Message EVA', exact: true }).fill('Reply exactly: second request ready.')
		await page.getByRole('button', { name: 'Send message', exact: true }).click()
		await expect(page.locator('.rm.assistant').last()).toContainText('second request ready', { timeout: 75000 })
		await expect(page.getByRole('button', { name: 'Send message', exact: true })).toBeVisible()
		await page.reload({ waitUntil: 'domcontentloaded' })
		await expect(page.locator('.rm.assistant')).toHaveCount(2)
		await expect(page.locator('.rm.assistant').last()).toContainText('second request ready')
		expect(errors).toEqual([])
	})
})
