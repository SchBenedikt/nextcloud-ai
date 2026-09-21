<template>
	<section class="agent-runs">
		<header class="agent-runs__header">
			<div>
				<h1>{{ $t('Agent runs') }}</h1>
				<p>{{ $t('Background work continues even when this page is closed. You can inspect and control each run here.') }}</p>
			</div>
			<NcButton class="agent-runs__refresh" type="button" variant="secondary" :disabled="loading" @click="load">{{ loading ? $t('Loading…') : $t('Refresh') }}</NcButton>
		</header>
		<p v-if="error" class="agent-runs__error" role="alert">{{ error }}</p>
		<p v-if="loading && !loaded" class="agent-runs__empty" role="status">{{ $t('Loading agent runs…') }}</p>
		<p v-else-if="loaded && !items.length" class="agent-runs__empty">{{ $t('No background runs yet.') }}</p>
		<div v-if="loaded && items.length" class="agent-runs__list">
			<article v-for="item in items" :key="item.id" class="agent-run" :class="'agent-run--' + item.status">
				<div class="agent-run__top">
					<div><strong>{{ item.message || $t('Background EVA request') }}</strong><small>{{ item.id }}</small></div>
					<span class="agent-run__status">{{ statusLabel(item.status) }}</span>
				</div>
				<div class="agent-run__meta">
					<span>{{ $t('Phase: {phase}', { phase: phaseLabel(item.phase || 'queued') }) }}</span><span>{{ $t('Steps: {count}', { count: item.steps || 0 }) }}</span>
					<span v-if="item.tool">{{ $t('Tool: {tool}', { tool: formatToolName(item.tool) }) }}</span><span v-if="item.attempts">{{ $t('Attempts: {count}', { count: item.attempts }) }}</span>
					<span v-if="item.status === 'pending' && item.queuedFor">{{ $t('Queued: {time}', { time: Math.floor(item.queuedFor / 60) + 'm ' + (item.queuedFor % 60) + 's' }) }}</span>
					<span v-if="item.deadline">{{ $t('Deadline: {date}', { date: formatDate(item.deadline) }) }}</span>
				</div>
				<details v-if="item.toolHistory && item.toolHistory.length" class="agent-run__trace">
					<summary>{{ $t('Execution trace ({count}) · {time} ms total', { count: item.toolHistory.length, time: traceElapsed(item) }) }}</summary>
					<ol>
						<li v-for="(step, index) in item.toolHistory" :key="index" class="agent-run__trace-step">
							<div class="agent-run__trace-head"><span class="agent-run__trace-index">#{{ index + 1 }}</span><strong>{{ formatToolName(step.tool || $t('Unknown tool')) }}</strong><span :class="'agent-run__trace-phase agent-run__trace-phase--' + step.phase">{{ phaseLabel(step) }}</span><small v-if="step.elapsed_ms !== undefined">{{ step.elapsed_ms }} ms</small><time>{{ formatDate(step.at) }}</time></div>
							<div class="agent-run__trace-grid">
								<div v-if="step.arguments" class="agent-run__trace-block"><span>{{ $t('Arguments') }}</span><pre>{{ formatJson(step.arguments) }}</pre></div>
								<div v-if="step.result !== null && step.result !== undefined" class="agent-run__trace-block"><span>{{ $t('Result') }}</span><pre>{{ formatJson(step.result) }}</pre></div>
								<div v-if="step.error" class="agent-run__trace-block agent-run__trace-block--error"><span>{{ $t('Error') }}</span><pre>{{ step.error }}</pre></div>
							</div>
						</li>
					</ol>
				</details>
				<p v-if="item.error" class="agent-run__error">{{ item.error }}</p>
				<details v-if="item.status === 'completed' && item.answer" class="agent-run__answer"><summary>{{ $t('Result') }}</summary><div>{{ item.answer }}</div></details>
				<div class="agent-run__actions">
					<NcButton v-if="item.status === 'pending'" type="button" variant="secondary" :loading="isBusy(item.id)" :disabled="isBusy(item.id)" @click="act('pause', item.id)">{{ $t('Pause') }}</NcButton>
					<NcButton v-if="item.status === 'paused'" type="button" variant="secondary" :loading="isBusy(item.id)" :disabled="isBusy(item.id)" @click="act('resume', item.id)">{{ $t('Resume') }}</NcButton>
					<NcButton v-if="item.status === 'failed'" type="button" variant="secondary" :loading="isBusy(item.id)" :disabled="isBusy(item.id)" @click="act('retry', item.id)">{{ $t('Retry') }}</NcButton>
					<NcButton v-if="['pending', 'running', 'paused'].includes(item.status)" type="button" variant="error" :loading="isBusy(item.id)" :disabled="isBusy(item.id)" @click="act('cancel', item.id)">{{ $t('Cancel') }}</NcButton>
				</div>
			</article>
		</div>
	</section>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { api, errMsg } from '../lib/api'
import { translate as t } from '../lib/i18n'
import { formatToolName } from '../lib/chat-utils'

const items = ref([])
const loading = ref(true)
const loaded = ref(false)
const error = ref('')
const busyItems = ref(new Set())
let timer = null
let requestId = 0

const load = async () => {
	const currentRequest = ++requestId
	loading.value = true
	try {
		const payload = await api('GET', 'backgroundChat')
		const runs = Array.isArray(payload) ? payload : payload?.items
		if (!Array.isArray(runs) || runs.some(item => !item || typeof item.id !== 'string' || typeof item.status !== 'string'
			|| (item.toolHistory !== undefined && item.toolHistory !== null && !Array.isArray(item.toolHistory)))) {
			throw new Error(t('The agent runs response was incomplete.'))
		}
		if (currentRequest === requestId) {
			items.value = runs
			loaded.value = true
			error.value = ''
		}
	} catch (e) {
		if (currentRequest === requestId) error.value = t('Could not load agent runs: {error}', { error: errMsg(e) })
	} finally {
		if (currentRequest === requestId) loading.value = false
	}
}
const act = async (operation, id) => {
	if (busyItems.value.has(id)) return
	const next = new Set(busyItems.value)
	next.add(id)
	busyItems.value = next
	try {
		if (operation === 'cancel') await api('DELETE', 'backgroundChat', { id })
		else await api('POST', 'backgroundChat/' + operation, { id })
		await load()
	} catch (e) { error.value = t('Agent run action failed: {error}', { error: errMsg(e) }) } finally {
		const remaining = new Set(busyItems.value)
		remaining.delete(id)
		busyItems.value = remaining
	}
}
const isBusy = (id) => busyItems.value.has(id)
const statusLabel = (status) => ({ pending: t('Pending'), running: t('Running'), paused: t('Paused'), failed: t('Failed'), completed: t('Completed'), cancelled: t('Cancelled') }[status] || status)
// BackgroundQueue timestamps are Unix seconds, while Date() expects
// milliseconds. Normalise both shapes so older records and API clients render
// an actionable deadline instead of a misleading date in January 1970.
const formatDate = (value) => {
	const numeric = Number(value)
	const millis = Number.isFinite(numeric) && numeric > 0 && numeric < 100000000000 ? numeric * 1000 : value
	const d = new Date(millis)
	return Number.isNaN(d.getTime()) ? String(value) : d.toLocaleString()
}
const formatJson = (value) => {
	if (typeof value === 'string') return value
	try { return JSON.stringify(value, null, 2) } catch (_) { return String(value) }
}
const traceElapsed = (item) => (item.toolHistory || []).reduce((total, step) => total + Math.max(0, Number(step.elapsed_ms) || 0), 0)
const phaseLabel = (step) => {
	if (typeof step === 'string') return step === 'queued' ? t('Queued') : step
	if (step.phase === 'tool_result') return step.ok === false ? t('Failed result') : t('Result')
	return ({ queued: t('Queued'), tool_start: t('Tool started'), thinking: t('Thinking'), completed: t('Completed'), failed: t('Failed') }[step.phase] || step.phase || t('Event'))
}
onMounted(() => { load(); timer = window.setInterval(load, 10000) })
onBeforeUnmount(() => { if (timer) window.clearInterval(timer) })
</script>

<style scoped>
.agent-runs { max-width: 1100px; margin: 0 auto; padding: 32px 40px 64px; color: var(--color-main-text); }
.agent-runs__header { display:flex; justify-content:space-between; align-items:flex-start; gap:24px; margin-bottom:24px; }
h1 { margin:0 0 8px; font-size:28px; } .agent-runs__header p { margin:0; color:var(--color-text-maxcontrast); }
.agent-runs__refresh { flex:none; } .agent-runs__error { color:var(--color-error); } .agent-runs__empty { padding:32px; text-align:center; color:var(--color-text-maxcontrast); }
.agent-runs__list { display:grid; gap:14px; } .agent-run { border:1px solid var(--color-border); border-radius:var(--border-radius-large); padding:18px; background:var(--color-main-background); box-shadow:0 2px 8px rgba(0,0,0,.06); }
.agent-run__top { display:flex; justify-content:space-between; gap:16px; } .agent-run__top strong { display:block; font-size:15px; } .agent-run__top small { display:block; margin-top:4px; color:var(--color-text-maxcontrast); font-family:monospace; }
.agent-run__status { text-transform:capitalize; font-weight:600; color:var(--color-primary-element); } .agent-run--failed .agent-run__status { color:var(--color-error); }
.agent-run__meta { display:flex; flex-wrap:wrap; gap:8px 18px; color:var(--color-text-maxcontrast); font-size:13px; } .agent-run__error { margin:12px 0 0; color:var(--color-error); white-space:pre-wrap; }
.agent-run__trace { margin-top:14px; border-top:1px solid var(--color-border); padding-top:10px; font-size:12px; } .agent-run__trace summary { cursor:pointer; color:var(--color-primary-element); font-weight:600; } .agent-run__trace ol { display:grid; gap:10px; margin:10px 0 0; padding-left:20px; } .agent-run__trace-step { display:block; padding:10px; border:1px solid var(--color-border); border-radius:var(--border-radius-large); background:var(--color-background-hover); } .agent-run__trace-head { display:flex; flex-wrap:wrap; gap:8px; align-items:baseline; } .agent-run__trace-head time { margin-left:auto; } .agent-run__trace-index { color:var(--color-text-maxcontrast); font-family:monospace; } .agent-run__trace time,.agent-run__trace span { color:var(--color-text-maxcontrast); } .agent-run__trace-phase--tool_result { color:var(--color-success) !important; font-weight:600; } .agent-run__trace-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px; margin-top:8px; } .agent-run__trace-block { min-width:0; } .agent-run__trace-block > span { display:block; margin-bottom:4px; font-weight:600; color:var(--color-main-text); } .agent-run__trace-block pre { margin:0; padding:8px; max-height:220px; overflow:auto; white-space:pre-wrap; overflow-wrap:anywhere; border-radius:var(--border-radius); background:var(--color-main-background); color:var(--color-main-text); font:11px/1.45 ui-monospace,SFMono-Regular,Menlo,monospace; } .agent-run__trace-block--error pre { color:var(--color-error); }
.agent-run__actions { display:flex; gap:8px; margin-top:16px; }
@media (max-width:700px) { .agent-runs { padding:24px 16px; } .agent-runs__header { flex-direction:column; } .agent-run__trace-grid { grid-template-columns:1fr; } .agent-run__trace-head time { width:100%; margin-left:0; } }
</style>
