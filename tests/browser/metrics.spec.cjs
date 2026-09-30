const { test, expect } = require('@playwright/test')
const { openMetrics } = require('./metrics-harness.cjs')

test('shows a transparent model cost estimate and saves per-user rates', async ({ page }) => {
	await openMetrics(page)
	const input = page.getByLabel('Input USD / 1M tokens for gpt-test')
	await expect(input).toHaveValue('2')
	await expect(page.locator('.pricing-panel')).toContainText('$1.3000')
	await expect(page.locator('.metrics-card').filter({ hasText: 'Estimated spend' })).toContainText('$1.3000')
	await input.fill('3')
	const requestPromise = page.waitForRequest(request => new URL(request.url()).pathname.endsWith('/api/metrics/pricing'))
	await page.getByRole('button', { name: 'Save prices' }).click()
	const request = await requestPromise
	expect(request.method()).toBe('PUT')
	expect(request.postDataJSON()).toEqual({ prices: [{ provider: 'openai', model: 'gpt-test', input_per_million: 3, output_per_million: 8 }] })
	await expect(page.getByRole('status').filter({ hasText: 'Prices saved' })).toBeVisible()
})

test('keeps metrics tables inside a narrow viewport', async ({ page }) => {
	await openMetrics(page, 375)
	const widths = await page.evaluate(() => ({ viewport: document.documentElement.clientWidth, page: document.documentElement.scrollWidth }))
	expect(widths.page).toBeLessThanOrEqual(widths.viewport)
})

test('allows administrators to filter metrics to a user without exposing pricing controls', async ({ page }) => {
	await openMetrics(page)
	const filter = page.getByRole('combobox', { name: 'Filter metrics by user' })
	await expect(filter).toBeVisible()
	const requestPromise = page.waitForRequest(request => new URL(request.url()).pathname.endsWith('/api/admin/users/alice/metrics'))
	await filter.selectOption('alice')
	await requestPromise
	const modelRow = page.getByRole('row').filter({ hasText: 'llama3.2' })
	await expect(modelRow).toContainText('5')
	await expect(page.locator('.pricing-panel')).toHaveCount(0)
})

test('opens the browser print flow for a PDF report', async ({ page }) => {
	await openMetrics(page)
	await page.evaluate(() => { window.__printed = false; window.print = () => { window.__printed = true } })
	await page.getByRole('button', { name: 'Print / Save as PDF' }).click()
	await expect.poll(() => page.evaluate(() => window.__printed)).toBe(true)
})
