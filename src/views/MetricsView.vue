<template>
	<div class="metrics-view">
		<header class="page-header">
			<div><p class="eyebrow">EVA AI</p><h1>{{ $t('Usage metrics') }}</h1><p>{{ $t('See how many model tokens your requests used, grouped by model and day.') }}</p></div>
			<div class="metrics-actions"><div class="period-switcher" role="group" :aria-label="$t('Metrics period')"><button v-for="value in [7, 30, 90]" :key="value" type="button" :class="{ active: days === value }" :aria-pressed="days === value" @click="load(value)">{{ value }} {{ $t('days') }}</button></div><button type="button" class="metrics-download" :disabled="loading || !hasCurrentData" @click="downloadCsv">{{ $t('Download CSV') }}</button><button type="button" class="metrics-download" :disabled="loading || !hasCurrentData" @click="printReport">{{ $t('Print / Save as PDF') }}</button><span class="metrics-refresh-status" role="status" aria-live="polite">{{ $t('Refreshes automatically every minute') }}<template v-if="updatedLabel"> · {{ updatedLabel }}</template></span></div>
		</header>
		<div v-if="error" class="metrics-error" role="alert"><span>{{ error }}</span><button type="button" class="metrics-retry" @click="load()">{{ $t('Try again') }}</button></div>
		<div v-if="loading" class="metrics-loading">{{ $t('Loading metrics…') }}</div>
		<template v-else-if="hasCurrentData">
			<section class="metrics-cards" :aria-label="$t('Usage totals')"><div v-for="card in cards" :key="card.label" class="metrics-card"><span>{{ card.label }}</span><strong>{{ card.money ? formatMoney(card.value) : format(card.value) }}</strong><small>{{ card.hint }}</small></div></section>
			<section class="metrics-panel feedback-metrics" :aria-label="$t('Answer feedback')"><h2>{{ $t('Answer feedback') }}</h2><p class="metrics-note">{{ $t('All-time feedback on assistant answers') }}</p><div class="feedback-totals"><div><strong>{{ format(feedbackStats.helpful) }}</strong><span>{{ $t('Helpful') }}</span></div><div><strong>{{ format(feedbackStats.notHelpful) }}</strong><span>{{ $t('Not helpful') }}</span></div><div><strong>{{ format(feedbackStats.bookmarked) }}</strong><span>{{ $t('Bookmarked') }}</span></div></div></section>
			<section v-if="totals.total_tokens" class="metrics-insights" :aria-label="$t('Usage breakdown')">
				<div class="metrics-panel token-split"><h2>{{ $t('Total tokens') }}</h2><div class="split-chart" :style="{ '--input': inputShare + '%' }" aria-hidden="true"><span>{{ format(totals.total_tokens) }}</span></div><div class="chart-legend"><span><i class="input-dot"></i>{{ $t('Input tokens') }} · {{ inputShare }}%</span><span><i class="output-dot"></i>{{ $t('Output tokens') }} · {{ 100 - inputShare }}%</span></div></div>
				<div class="metrics-panel"><h2>{{ $t('Top models by token use') }}</h2><div class="model-bars"><div v-for="row in byModel.slice(0, 5)" :key="row.provider + row.model" class="model-bar"><span :title="row.provider + ' / ' + row.model">{{ row.provider }} / {{ row.model }}</span><div><i :style="{ width: (Number(row.total_tokens || 0) / maxModel * 100) + '%' }"></i></div><strong>{{ format(row.total_tokens) }}</strong></div></div></div>
			</section>
			<section class="metrics-panel"><h2>{{ $t('Model usage details') }}</h2><p v-if="!byModel.length" class="metrics-empty">{{ $t('No model usage has been recorded in this period.') }}</p><div v-else class="metrics-table-scroll"><table><thead><tr><th>{{ $t('Provider') }}</th><th>{{ $t('Model') }}</th><th>{{ $t('Requests') }}</th><th>{{ $t('Average response') }}</th><th>{{ $t('Input tokens') }}</th><th>{{ $t('Output tokens') }}</th><th>{{ $t('Total tokens') }}</th></tr></thead><tbody><tr v-for="row in byModel" :key="row.provider + row.model"><td>{{ row.provider }}</td><td class="mono">{{ row.model }}</td><td>{{ format(row.requests) }}</td><td>{{ format(row.average_duration_ms) }} ms</td><td>{{ format(row.input_tokens) }}</td><td>{{ format(row.output_tokens) }}</td><td>{{ format(row.total_tokens) }}<small v-if="row.estimated_requests"> · {{ $t('estimated') }}</small></td></tr></tbody></table></div></section>
			<section class="metrics-panel pricing-panel">
				<h2>{{ $t('Estimated model costs') }}</h2>
				<p class="metrics-note">{{ $t('Enter the prices from your provider. Estimates use USD per million tokens and your recorded token counts; local model costs are not included unless you enter a rate.') }}</p>
				<form v-if="byModel.length" @submit.prevent="savePricing">
					<div class="metrics-table-scroll"><table><thead><tr><th>{{ $t('Provider') }}</th><th>{{ $t('Model') }}</th><th>{{ $t('Input USD / 1M tokens') }}</th><th>{{ $t('Output USD / 1M tokens') }}</th><th>{{ $t('Estimated cost') }}</th></tr></thead>
						<tbody><tr v-for="row in byModel" :key="priceKey(row)"><td>{{ row.provider }}</td><td class="mono">{{ row.model }}</td><td><input :id="'price-in-' + priceKey(row)" v-model="ensurePrice(row).input_per_million" type="number" min="0" max="10000" step="any" inputmode="decimal"><label class="visually-hidden" :for="'price-in-' + priceKey(row)">{{ $t('Input USD / 1M tokens for {model}', { model: row.model }) }}</label></td><td><input :id="'price-out-' + priceKey(row)" v-model="ensurePrice(row).output_per_million" type="number" min="0" max="10000" step="any" inputmode="decimal"><label class="visually-hidden" :for="'price-out-' + priceKey(row)">{{ $t('Output USD / 1M tokens for {model}', { model: row.model }) }}</label></td><td>{{ row.estimated_cost_usd === null || row.estimated_cost_usd === undefined ? $t('Not configured') : formatMoney(row.estimated_cost_usd) }}</td></tr></tbody>
					</table></div>
					<div class="pricing-actions"><button class="metrics-download" type="submit" :disabled="savingPricing">{{ savingPricing ? $t('Saving…') : $t('Save prices') }}</button><span v-if="pricingStatus" role="status">{{ pricingStatus }}</span></div>
					<p class="metrics-note">{{ $t('All estimates are approximate. Provider prices change; update these rates from your provider invoice or pricing page.') }}</p>
				</form>
				<p v-else class="metrics-empty">{{ $t('Model usage will appear here after your first request.') }}</p>
			</section>
			<section class="metrics-panel"><h2>{{ $t('Daily usage') }}</h2><div v-if="daily.length" class="daily-list"><div v-for="row in daily" :key="row.day" class="daily-row"><time>{{ row.day }}</time><div class="daily-bar"><span :style="{ width: (row.total_tokens / maxDaily * 100) + '%' }"></span></div><strong>{{ format(row.total_tokens) }}</strong></div></div><p v-else class="metrics-empty">{{ $t('No daily usage has been recorded in this period.') }}</p></section>
			<section class="metrics-panel"><h2>{{ $t('Slow tool calls') }}</h2><p class="metrics-note">{{ $t('Only tool calls slower than 100 ms are listed.') }}</p><p v-if="!slowTools.length" class="metrics-empty">{{ $t('No slow tool calls have been recorded in this period.') }}</p><div v-else class="metrics-table-scroll"><table><thead><tr><th>{{ $t('Tool') }}</th><th>{{ $t('Calls') }}</th><th>{{ $t('Average') }}</th><th>{{ $t('Maximum') }}</th><th>{{ $t('Errors') }}</th></tr></thead><tbody><tr v-for="row in slowTools" :key="row.tool"><td class="mono">{{ formatToolName(row.tool) }}</td><td>{{ format(row.calls) }}</td><td>{{ format(row.avg_duration_ms) }} ms</td><td>{{ format(row.max_duration_ms) }} ms</td><td>{{ format(row.errors) }}</td></tr></tbody></table></div></section>
		</template>
		<div v-else-if="!error" class="metrics-empty">{{ $t('Usage metrics are not available yet.') }}</div>
	</div>
</template>

<script>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { api, errMsg } from '../lib/api'
import { translate as t } from '../lib/i18n'
import { formatToolName } from '../lib/chat-utils'
import { buildMetricsCsv } from '../lib/metrics-export'

export default {
	name: 'MetricsView',
	setup() {
		const days = ref(30); const loading = ref(true); const error = ref(''); const dataDays = ref(null); const data = ref({ totals: {}, by_model: [], daily: [], slow_tools: [] })
		const feedbackStats = ref({ helpful: 0, notHelpful: 0, bookmarked: 0 })
		const prices = ref({}); const pricesLoaded = ref(false); const savingPricing = ref(false); const pricingStatus = ref('')
		const lastUpdated = ref(0)
		const updatedLabel = computed(() => lastUpdated.value
			? t('Updated {time}', { time: new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' }).format(lastUpdated.value) })
			: '')
		let requestId = 0; let refreshTimer = null
		const hasCurrentData = computed(() => dataDays.value === days.value)
		const totals = computed(() => data.value.totals || {}); const byModel = computed(() => data.value.by_model || []); const daily = computed(() => data.value.daily || []); const slowTools = computed(() => data.value.slow_tools || [])
		const maxDaily = computed(() => Math.max(1, ...daily.value.map(row => Number(row.total_tokens) || 0))); const maxModel = computed(() => Math.max(1, ...byModel.value.map(row => Number(row.total_tokens) || 0))); const inputShare = computed(() => Math.round((Number(totals.value.input_tokens || 0) / Math.max(1, Number(totals.value.total_tokens || 0))) * 100)); const format = value => Number(value || 0).toLocaleString()
		const estimatedCost = computed(() => byModel.value.reduce((sum, row) => sum + (Number(row.estimated_cost_usd) || 0), 0))
		const priceKey = row => JSON.stringify([row.provider, row.model])
		const ensurePrice = row => {
			const key = priceKey(row)
			if (!prices.value[key]) prices.value[key] = { provider: row.provider, model: row.model, input_per_million: '', output_per_million: '' }
			return prices.value[key]
		}
		const formatMoney = value => new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD', minimumFractionDigits: 4, maximumFractionDigits: 6 }).format(Number(value || 0))
		const cards = computed(() => [
			{ label: t('Requests'), value: totals.value.requests, hint: t('Model requests in the selected period') },
			{ label: t('Input tokens'), value: totals.value.input_tokens, hint: t('Tokens sent to the models') },
			{ label: t('Output tokens'), value: totals.value.output_tokens, hint: t('Tokens generated by the models') },
			{ label: t('Total tokens'), value: totals.value.total_tokens, hint: totals.value.estimated_requests ? t('{count} requests use estimates.', { count: totals.value.estimated_requests }) : t('Exact provider usage reported') },
			...(byModel.value.some(row => row.estimated_cost_usd !== null && row.estimated_cost_usd !== undefined) ? [{ label: t('Estimated spend'), value: estimatedCost.value, hint: t('USD estimate from configured rates'), money: true }] : []),
		])
		const load = async (period = days.value, background = false) => {
			if (background && (loading.value || !hasCurrentData.value)) return
			const currentRequest = ++requestId
			days.value = period
			if (!background) {
				loading.value = true
				error.value = ''
			}
			try {
				const [result, feedback, settings] = await Promise.all([
					api('GET', 'metrics', { days: period }),
					api('GET', 'feedback/stats').catch(() => null),
					pricesLoaded.value ? Promise.resolve(null) : api('GET', 'settings').catch(() => null),
				])
				if (currentRequest !== requestId) return
				if (settings) {
					let savedPrices = settings.model_pricing || []
					if (typeof savedPrices === 'string') { try { savedPrices = JSON.parse(savedPrices) } catch (_) { savedPrices = [] } }
					if (Array.isArray(savedPrices)) {
						prices.value = Object.fromEntries(savedPrices.map(row => [priceKey(row), { ...row, input_per_million: String(row.input_per_million), output_per_million: String(row.output_per_million) }]))
					}
				}
				if (settings) pricesLoaded.value = true
				data.value = result
				for (const row of result.by_model || []) ensurePrice(row)
				if (feedback) feedbackStats.value = feedback
				dataDays.value = period
				lastUpdated.value = Date.now()
				error.value = ''
			} catch (e) {
				if (currentRequest === requestId) error.value = t('Could not load usage metrics: {error}', { error: errMsg(e) })
			} finally {
				if (currentRequest === requestId && !background) loading.value = false
			}
		}
		const savePricing = async () => {
			pricingStatus.value = ''
			const pricing = Object.values(prices.value).filter(row => row.input_per_million !== '' && row.output_per_million !== '').map(row => ({
				provider: row.provider,
				model: row.model,
				input_per_million: Number(row.input_per_million),
				output_per_million: Number(row.output_per_million),
			}))
			savingPricing.value = true
			try {
				await api('PUT', 'metrics/pricing', { prices: pricing })
				pricingStatus.value = t('Prices saved')
				await load(days.value, true)
			} catch (e) {
				pricingStatus.value = t('Could not save prices: {error}', { error: errMsg(e) })
			} finally { savingPricing.value = false }
		}
		const downloadCsv = () => {
			if (!hasCurrentData.value) return
			const content = buildMetricsCsv({ days: days.value, totals: totals.value, byModel: byModel.value, daily: daily.value, slowTools: slowTools.value, feedbackStats: feedbackStats.value })
			const url = URL.createObjectURL(new Blob([content], { type: 'text/csv;charset=utf-8' }))
			const link = document.createElement('a')
			link.href = url
			link.download = `eva-usage-metrics-${new Date().toISOString().slice(0, 10)}-${days.value}d.csv`
			document.body.appendChild(link)
			link.click()
			link.remove()
			setTimeout(() => URL.revokeObjectURL(url), 0)
		}
		const printReport = () => window.print()
		const refreshWhenVisible = () => {
			if (document.visibilityState === 'visible') load(days.value, true)
		}
		onMounted(() => {
			load(days.value)
			refreshTimer = window.setInterval(refreshWhenVisible, 60000)
			document.addEventListener('visibilitychange', refreshWhenVisible)
		})
		onBeforeUnmount(() => {
			if (refreshTimer !== null) window.clearInterval(refreshTimer)
			document.removeEventListener('visibilitychange', refreshWhenVisible)
			requestId++
		})
		return { days, loading, error, hasCurrentData, totals, byModel, daily, slowTools, feedbackStats, maxDaily, maxModel, inputShare, cards, format, formatMoney, priceKey, ensurePrice, savingPricing, pricingStatus, savePricing, load, downloadCsv, printReport, updatedLabel }
	},
}
</script>

<style scoped>
.metrics-refresh-status { color: var(--color-text-maxcontrast); font-size: .8rem; }
.feedback-metrics { margin-top: 0; }.feedback-totals { display: flex; gap: 32px; flex-wrap: wrap; }.feedback-totals div { display: grid; gap: 3px; }.feedback-totals strong { font-size: 1.35rem; }.feedback-totals span { color: var(--color-text-maxcontrast); }
.pricing-panel table { width: 100%; }.pricing-panel input { box-sizing: border-box; width: min(100%, 11rem); }.pricing-actions { display: flex; align-items: center; gap: 12px; margin-top: 12px; }.visually-hidden { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
.metrics-panel, .metrics-insights { min-width: 0; }.metrics-table-scroll { width: 100%; max-width: 100%; min-width: 0; overflow-x: auto; -webkit-overflow-scrolling: touch; }
@media print { .metrics-actions, .metrics-error, .metrics-loading, .pricing-actions, .metrics-refresh-status { display: none !important; }.metrics-panel, .metrics-cards { break-inside: avoid; box-shadow: none !important; }.metrics-table-scroll { overflow: visible; }.metrics-view { max-width: none; }.pricing-panel input { border: 0; background: transparent; color: inherit; } }
</style>
