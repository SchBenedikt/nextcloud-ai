const { test, expect } = require('@playwright/test')
const { openWorkspace } = require('./workspace-harness.cjs')

test('home shares the chat list and sends its first prompt without loading diagnostics', async ({ page }) => {
	const { calls, errors } = await openWorkspace(page, { backgroundItems: [{ id: 'old-run', chatId: 'new-chat', status: 'completed' }] })
	// Nextcloud core gives section and button elements an explicit display.
	await page.addStyleTag({ content: 'section { display: block } button { display: inline-block }' })
	await expect(page.getByRole('textbox', { name: 'Message EVA' })).toBeVisible()
	await expect(page.getByRole('heading', { name: 'Your workspace', exact: true })).toHaveCount(0)
	await expect(page.locator('.home-view').getByRole('button', { name: 'New chat', exact: true })).toHaveCount(0)
	await page.getByRole('textbox', { name: 'Message EVA' }).fill('Help me plan my week')
	await page.getByRole('button', { name: 'Send', exact: true }).click()
	await expect(page.locator('.rm.user')).toContainText('Help me plan my week')
	await expect(page.locator('.rm.assistant')).toContainText('A verified reply')
	await expect(page.locator('.image-generator')).toBeHidden()
	await expect(page.locator('.head .pill')).toBeHidden()
	expect(calls.filter(call => ['stats', 'greeting', 'health', 'status'].includes(call.endpoint))).toEqual([])
	expect(errors).toEqual([])
})

test('settings load optional resources on demand and save a provider without JSON editing', async ({ page }) => {
	const { calls, errors } = await openWorkspace(page, { view: 'settings' })
	await expect(page.getByRole('button', { name: 'Add AI provider', exact: true })).toBeEnabled()
	expect(calls.some(call => ['health', 'connectors', 'plugins', 'apiKeys'].includes(call.endpoint))).toBe(false)
	await page.getByRole('button', { name: 'Add AI provider', exact: true }).click()
	const dialog = page.getByRole('dialog')
	await dialog.getByRole('textbox', { name: 'Display name', exact: true }).fill('My AI provider')
	await dialog.getByRole('textbox', { name: 'Chat model', exact: true }).fill('test-model')
	await dialog.getByRole('textbox', { name: 'API key', exact: true }).fill('synthetic-browser-key')
	await dialog.getByRole('button', { name: 'Save & use provider' }).click()
	await expect(page.getByText('Provider saved and selected.')).toBeVisible()
	const saved = calls.find(call => call.method === 'PUT' && call.endpoint === 'settings')
	expect(saved.body.chat_provider).toBe('openai')
	expect(JSON.parse(saved.body.provider_profiles)[0]).toMatchObject({ name: 'My AI provider', model: 'test-model' })
	expect(saved.body.custom_provider_api_key).toBe('synthetic-browser-key')
	await expect(page.getByRole('textbox', { name: 'Search chats', exact: true })).toHaveCount(0)
	await page.getByRole('link', { name: 'Documents', exact: true }).click()
	await expect(page).toHaveURL(/section=knowledge/)
	await page.goBack()
	await expect(page.getByRole('heading', { name: 'AI models', exact: true })).toBeVisible()
	await page.getByRole('link', { name: 'Advanced', exact: true }).click()
	await expect(page.getByRole('heading', { name: 'Diagnostics & activity' })).toBeVisible()
	await expect.poll(() => calls.filter(call => call.endpoint === 'health').length).toBe(1)
	expect(errors).toEqual([])
})

test('home and compact chat header fit a mobile screen', async ({ page }) => {
	const { errors } = await openWorkspace(page, { width: 390 })
	await expect(page.getByRole('textbox', { name: 'Message EVA' })).toBeVisible()
	expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
	await page.getByRole('textbox', { name: 'Message EVA' }).fill('A mobile question')
	await page.getByRole('button', { name: 'Send', exact: true }).click()
	await expect(page.locator('.rm.assistant')).toContainText('A verified reply')
	await expect(page.locator('.head .chat-actions')).toBeVisible()
	expect(await page.locator('.head').evaluate(element => element.getBoundingClientRect().height)).toBeLessThan(100)
	expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
	expect(errors).toEqual([])
})

test('document progress uses the lightweight endpoint and filters start collapsed', async ({ page }) => {
	const { calls, errors } = await openWorkspace(page)
	await page.locator('#app-navigation-vue').getByRole('link', { name: 'Documents', exact: true }).click()
	await expect(page.getByText('No documents indexed yet', { exact: true })).toBeVisible()
	await expect(page.locator('.docs-filters')).not.toHaveAttribute('open', '')
	await page.getByText('Filters & sorting', { exact: true }).click()
	await expect(page.locator('.docs-filter-fields')).toBeVisible()
	expect(calls.some(call => call.endpoint === 'index/status')).toBe(true)
	expect(calls.some(call => call.endpoint === 'status')).toBe(false)
	expect(errors).toEqual([])
})

test('local provider template can be saved without sending an API key', async ({ page }) => {
	const { calls } = await openWorkspace(page, { view: 'settings' })
	await page.getByRole('button', { name: 'Add AI provider', exact: true }).click()
	const dialog = page.getByRole('dialog')
	await dialog.getByRole('combobox').fill('LM Studio')
	await page.getByRole('option', { name: 'LM Studio', exact: true }).click()
	await expect(dialog.getByRole('checkbox', { name: 'Authenticate with an API key' })).not.toBeChecked()
	await dialog.getByRole('textbox', { name: 'Chat model', exact: true }).fill('local-model')
	await dialog.getByRole('button', { name: 'Save & use provider' }).click()
	await expect(page.getByText('Provider saved and selected.')).toBeVisible()
	const saved = calls.find(call => call.method === 'PUT' && call.endpoint === 'settings')
	expect(saved.body.custom_provider_api_key).toBeUndefined()
	expect(JSON.parse(saved.body.provider_profiles)[0]).toMatchObject({ id: 'lmstudio', auth: 'none', model: 'local-model' })
})

test('file-context replies render safe Markdown with an accessible composer', async ({ page }) => {
	const { errors } = await openWorkspace(page, { view: 'fileContext', fileIds: [11] })
	await page.getByRole('textbox', { name: 'Ask about these files…' }).fill('Summarize the notes')
	await page.getByRole('button', { name: 'Send', exact: true }).click()
	await expect(page.locator('.msg.assistant strong')).toHaveText('Key point')
	await expect(page.locator('.msg.assistant li')).toHaveText('A readable answer')
	expect(await page.evaluate(() => window.__unsafe)).toBeUndefined()
	expect(errors).toEqual([])
})

test('bookmarked conversations can be filtered without breaking navigation', async ({ page }) => {
	const { errors } = await openWorkspace(page, { chats: [
		{ id: 'saved', title: 'Saved source', updated: 2, bookmarkedCount: 1 },
		{ id: 'regular', title: 'Regular conversation', updated: 1, bookmarkedCount: 0 },
	] })
	await page.getByRole('link', { name: 'Bookmarked answers (1)', exact: true }).click()
	await expect(page.locator('#app-navigation-vue').getByRole('link', { name: 'Saved source', exact: true })).toBeVisible()
	await expect(page.locator('#app-navigation-vue').getByRole('link', { name: 'Regular conversation', exact: true })).toHaveCount(0)
	expect(errors).toEqual([])
})

test('all native settings sections render on mobile and retain browser navigation', async ({ page }) => {
	const { errors } = await openWorkspace(page, { view: 'settings', width: 390 })
	for (const [id, label] of [['knowledge', 'Documents'], ['tools', 'Tools & permissions'], ['automation', 'Automation'], ['personal', 'Personal & data'], ['advanced', 'Advanced']]) {
		const openNavigation = page.getByRole('button', { name: 'Open navigation', exact: true })
		if (await openNavigation.isVisible()) await openNavigation.click()
		await page.locator('#app-navigation-vue').getByRole('link', { name: label, exact: true }).click()
		await expect(page).toHaveURL(new RegExp('section=' + id))
		await expect(page.getByRole('heading', { name: label, exact: true })).toBeVisible()
		expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
	}
	expect(errors).toEqual([])
})

test('a delayed briefing response preserves newer edits in another settings section', async ({ page }) => {
	const { calls, errors, settings } = await openWorkspace(page, { view: 'settings' })
	let release
	let started
	const pending = new Promise(resolve => { started = resolve })
	const gate = new Promise(resolve => { release = resolve })
	await page.route('**/api/settings', async route => {
		const body = route.request().postDataJSON()
		if (!body.proactive_schedules || body.top_k) { await route.fallback(); return }
		const reply = { ...settings, ...body }
		Object.assign(settings, body)
		started()
		await gate
		await route.fulfill({ contentType: 'application/json', body: JSON.stringify(reply) })
	})
	await page.getByRole('link', { name: 'Automation', exact: true }).click()
	await page.getByText('Enable scheduled briefings', { exact: true }).click()
	await expect(page.getByRole('checkbox', { name: 'Enable scheduled briefings', exact: true })).toBeChecked()
	await page.getByRole('textbox', { name: 'What should EVA do?', exact: true }).fill('Summarize my calendar')
	await page.getByRole('button', { name: 'Add briefing', exact: true }).click()
	await pending
	await page.getByRole('link', { name: 'Documents', exact: true }).click()
	const sources = page.getByRole('spinbutton', { name: 'Sources per answer', exact: true })
	await sources.fill('7')
	release()
	await expect(sources).toHaveValue('7')
	await expect.poll(() => calls.some(call => call.method === 'PUT' && call.body?.top_k === '7')).toBe(true)
	expect(errors).toEqual([])
})

test('a delayed provider refresh preserves newer edits and autosaves them', async ({ page }) => {
	const { calls, errors, settings } = await openWorkspace(page, { view: 'settings' })
	await expect(page.getByRole('button', { name: 'Add AI provider', exact: true })).toBeEnabled()
	let release
	let started
	const pending = new Promise(resolve => { started = resolve })
	const gate = new Promise(resolve => { release = resolve })
	await page.route('**/api/status', async route => {
		const reply = { settings: { ...settings }, models: [], provider: { roles: {} }, indexing: false }
		started()
		await gate
		await route.fulfill({ contentType: 'application/json', body: JSON.stringify(reply) })
	})
	await page.getByRole('button', { name: 'Add AI provider', exact: true }).click()
	const dialog = page.getByRole('dialog')
	await dialog.getByRole('textbox', { name: 'Display name', exact: true }).fill('Test provider')
	await dialog.getByRole('textbox', { name: 'Chat model', exact: true }).fill('test-model')
	await dialog.getByRole('textbox', { name: 'API key', exact: true }).fill('synthetic-key')
	await dialog.getByRole('button', { name: 'Save & use provider' }).click()
	await pending
	await page.getByRole('link', { name: 'Documents', exact: true }).click()
	const sources = page.getByRole('spinbutton', { name: 'Sources per answer', exact: true })
	await sources.fill('7')
	release()
	await expect(sources).toHaveValue('7')
	await expect.poll(() => calls.some(call => call.method === 'PUT' && call.body?.top_k === '7')).toBe(true)
	expect(errors).toEqual([])
})

test('documents can retry a failed chunk request and load the remaining text', async ({ page }) => {
	const { errors } = await openWorkspace(page, { documents: [{ id: 7, path: 'Notes.md', name: 'Notes.md', size: 1000, chunks: 2, mime: 'text/markdown', indexedAt: 123 }] })
	let requests = 0
	await page.route('**/api/documentChunks', async route => {
		requests++
		if (requests === 1) { await route.fulfill({ status: 503, contentType: 'application/json', body: '{"message":"Temporarily unavailable"}' }); return }
		const { offset } = route.request().postDataJSON()
		await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ chunks: [{ index: offset, content: offset ? 'Remaining indexed text' : 'First indexed text' }], document: { chunks: 2 } }) })
	})
	await page.locator('#app-navigation-vue').getByRole('link', { name: 'Documents', exact: true }).click()
	await page.getByRole('row', { name: /Notes.md/ }).click()
	await expect(page.getByRole('alert')).toContainText('Chunks could not be loaded.')
	await page.getByRole('button', { name: 'Retry', exact: true }).click()
	await expect(page.getByText('First indexed text', { exact: true })).toBeVisible()
	await page.getByRole('button', { name: 'Load 1 more chunks', exact: true }).click()
	await expect(page.getByText('Remaining indexed text', { exact: true })).toBeVisible()
	await expect(page.getByText('Loading chunks …', { exact: true })).toHaveCount(0)
	expect(requests).toBe(3)
	expect(errors).toEqual([])
})

test('native document filters send the chosen type and sort to the API', async ({ page }) => {
	const { calls, errors } = await openWorkspace(page, { view: 'docs', width: 390 })
	await page.getByText('Filters & sorting', { exact: true }).click()
	await page.getByRole('combobox', { name: 'Type', exact: true }).fill('text')
	await page.getByRole('option', { name: 'text/*', exact: true }).click()
	await expect.poll(() => calls.filter(call => call.endpoint === 'documents').at(-1)?.query.type).toBe('text')
	await page.getByRole('combobox', { name: 'Sort', exact: true }).fill('Largest')
	await page.getByRole('option', { name: 'Largest first', exact: true }).click()
	await expect.poll(() => calls.filter(call => call.endpoint === 'documents').at(-1)?.query.sort).toBe('size')
	expect(calls.filter(call => call.endpoint === 'documents').at(-1).query.dir).toBe('desc')
	expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
	expect(errors).toEqual([])
})
