<template>
	<div class="metrics-view">
		<header class="page-header">
			<div><p class="eyebrow">EVA AI</p><h1>{{ $t('Usage metrics') }}</h1><p>{{ $t('See how many model tokens your requests used, grouped by model and day.') }}</p></div>
			<div class="metrics-actions"><div class="period-switcher" role="group" :aria-label="$t('Metrics period')"><button v-for="value in [7, 30, 90]" :key="value" type="button" :class="{ active: days === value }" :aria-pressed="days === value" @click="load(value)">{{ value }} {{ $t('days') }}</button></div><button type="button" class="metrics-download" :disabled="loading || !hasCurrentData" @click="downloadCsv">{{ $t('Download CSV') }}</button><span class="metrics-refresh-status" role="status" aria-live="polite">{{ $t('Refreshes automatically every minute') }}<template v-if="updatedLabel"> · {{ updatedLabel }}</template></span></div>
		</header>
		<div v-if="error" class="metrics-error" role="alert"><span>{{ error }}</span><button type="button" class="metrics-retry" @click="load()">{{ $t('Try again') }}</button></div>
		<div v-if="loading" class="metrics-loading">{{ $t('Loading metrics…') }}</div>
		<template v-else-if="hasCurrentData">
			<section class="metrics-cards" :aria-label="$t('Usage totals')"><div v-for="card in cards" :key="card.label" class="metrics-card"><span>{{ card.label }}</span><strong>{{ format(card.value) }}</strong><small>{{ card.hint }}</small></div></section>
			<section class="metrics-panel feedback-metrics" :aria-label="$t('Answer feedback')"><h2>{{ $t('Answer feedback') }}</h2><p class="metrics-note">{{ $t('All-time feedback on assistant answers') }}</p><div class="feedback-totals"><div><strong>{{ format(feedbackStats.helpful) }}</strong><span>{{ $t('Helpful') }}</span></div><div><strong>{{ format(feedbackStats.notHelpful) }}</strong><span>{{ $t('Not helpful') }}</span></div><div><strong>{{ format(feedbackStats.bookmarked) }}</strong><span>{{ $t('Bookmarked') }}</span></div></div></section>
			<section v-if="totals.total_tokens" class="metrics-insights" :aria-label="$t('Usage breakdown')">
				<div class="metrics-panel token-split"><h2>{{ $t('Total tokens') }}</h2><div class="split-chart" :style="{ '--input': inputShare + '%' }" aria-hidden="true"><span>{{ format(totals.total_tokens) }}</span></div><div class="chart-legend"><span><i class="input-dot"></i>{{ $t('Input tokens') }} · {{ inputShare }}%</span><span><i class="output-dot"></i>{{ $t('Output tokens') }} · {{ 100 - inputShare }}%</span></div></div>
				<div class="metrics-panel"><h2>{{ $t('Top models by token use') }}</h2><div class="model-bars"><div v-for="row in byModel.slice(0, 5)" :key="row.provider + row.model" class="model-bar"><span :title="row.provider + ' / ' + row.model">{{ row.provider }} / {{ row.model }}</span><div><i :style="{ width: (Number(row.total_tokens || 0) / maxModel * 100) + '%' }"></i></div><strong>{{ format(row.total_tokens) }}</strong></div></div></div>
			</section>
			<section class="metrics-panel"><h2>{{ $t('Model usage details') }}</h2><p v-if="!byModel.length" class="metrics-empty">{{ $t('No model usage has been recorded in this period.') }}</p><table v-else><thead><tr><th>{{ $t('Provider') }}</th><th>{{ $t('Model') }}</th><th>{{ $t('Requests') }}</th><th>{{ $t('Average response') }}</th><th>{{ $t('Input tokens') }}</th><th>{{ $t('Output tokens') }}</th><th>{{ $t('Total tokens') }}</th></tr></thead><tbody><tr v-for="row in byModel" :key="row.provider + row.model"><td>{{ row.provider }}</td><td class="mono">{{ row.model }}</td><td>{{ format(row.requests) }}</td><td>{{ format(row.average_duration_ms) }} ms</td><td>{{ format(row.input_tokens) }}</td><td>{{ format(row.output_tokens) }}</td><td>{{ format(row.total_tokens) }}<small v-if="row.estimated_requests"> · {{ $t('estimated') }}</small></td></tr></tbody></table></section>
			<section class="metrics-panel"><h2>{{ $t('Daily usage') }}</h2><div v-if="daily.length" class="daily-list"><div v-for="row in daily" :key="row.day" class="daily-row"><time>{{ row.day }}</time><div class="daily-bar"><span :style="{ width: (row.total_tokens / maxDaily * 100) + '%' }"></span></div><strong>{{ format(row.total_tokens) }}</strong></div></div><p v-else class="metrics-empty">{{ $t('No daily usage has been recorded in this period.') }}</p></section>
			<section class="metrics-panel"><h2>{{ $t('Slow tool calls') }}</h2><p class="metrics-note">{{ $t('Only tool calls slower than 100 ms are listed.') }}</p><p v-if="!slowTools.length" class="metrics-empty">{{ $t('No slow tool calls have been recorded in this period.') }}</p><table v-else><thead><tr><th>{{ $t('Tool') }}</th><th>{{ $t('Calls') }}</th><th>{{ $t('Average') }}</th><th>{{ $t('Maximum') }}</th><th>{{ $t('Errors') }}</th></tr></thead><tbody><tr v-for="row in slowTools" :key="row.tool"><td class="mono">{{ formatToolName(row.tool) }}</td><td>{{ format(row.calls) }}</td><td>{{ format(row.avg_duration_ms) }} ms</td><td>{{ format(row.max_duration_ms) }} ms</td><td>{{ format(row.errors) }}</td></tr></tbody></table></section>
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
		const lastUpdated = ref(0)
		const updatedLabel = computed(() => lastUpdated.value
			? t('Updated {time}', { time: new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' }).format(lastUpdated.value) })
			: '')
		let requestId = 0; let refreshTimer = null
		const hasCurrentData = computed(() => dataDays.value === days.value)
		const totals = computed(() => data.value.totals || {}); const byModel = computed(() => data.value.by_model || []); const daily = computed(() => data.value.daily || []); const slowTools = computed(() => data.value.slow_tools || [])
		const maxDaily = computed(() => Math.max(1, ...daily.value.map(row => Number(row.total_tokens) || 0))); const maxModel = computed(() => Math.max(1, ...byModel.value.map(row => Number(row.total_tokens) || 0))); const inputShare = computed(() => Math.round((Number(totals.value.input_tokens || 0) / Math.max(1, Number(totals.value.total_tokens || 0))) * 100)); const format = value => Number(value || 0).toLocaleString()
		const cards = computed(() => [
			{ label: t('Requests'), value: totals.value.requests, hint: t('Model requests in the selected period') },
			{ label: t('Input tokens'), value: totals.value.input_tokens, hint: t('Tokens sent to the models') },
			{ label: t('Output tokens'), value: totals.value.output_tokens, hint: t('Tokens generated by the models') },
			{ label: t('Total tokens'), value: totals.value.total_tokens, hint: totals.value.estimated_requests ? t('{count} requests use estimates.', { count: totals.value.estimated_requests }) : t('Exact provider usage reported') },
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
				const [result, feedback] = await Promise.all([
					api('GET', 'metrics', { days: period }),
					api('GET', 'feedback/stats').catch(() => null),
				])
				if (currentRequest !== requestId) return
				data.value = result
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
		return { days, loading, error, hasCurrentData, totals, byModel, daily, slowTools, feedbackStats, maxDaily, maxModel, inputShare, cards, format, load, downloadCsv, updatedLabel }
	},
}
</script>

<style scoped>
.metrics-refresh-status { color: var(--color-text-maxcontrast); font-size: .8rem; }
.feedback-metrics { margin-top: 0; }.feedback-totals { display: flex; gap: 32px; flex-wrap: wrap; }.feedback-totals div { display: grid; gap: 3px; }.feedback-totals strong { font-size: 1.35rem; }.feedback-totals span { color: var(--color-text-maxcontrast); }
</style>
