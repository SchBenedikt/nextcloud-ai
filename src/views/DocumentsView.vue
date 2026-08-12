<template>
	<div class="docs-view">
		<header class="page-header">
			<div class="header-copy">
				<p class="eyebrow">EVA AI</p>
				<h2 class="docs-title">Documents</h2>
				<p class="page-intro">Review the files in your personal EVA knowledge base and inspect their indexed text.</p>
			</div>
			<div class="header-actions">
				<NcButton type="primary" :loading="indexing" :disabled="indexingActive" @click="startIndex">Index files &amp; emails</NcButton>
				<NcButton type="secondary" :disabled="indexingActive" @click="startMailIndex">Only emails</NcButton>
				<NcButton v-if="indexingActive" type="tertiary-no-background" :loading="stopping" :disabled="indexStatus?.indexStopping" @click="stopIndex">Stop</NcButton>
			</div>
		</header>

		<div v-if="indexingActive" class="callout indexing-callout" role="status">
			<strong>{{ indexStatus?.indexStopping ? 'Stopping indexing…' : indexStatus?.indexMode === 'mail' ? 'Email indexing is running' : 'Indexing is running' }}</strong>
			<span>The job continues on the server even if you close this page.</span>
		</div>
		<div v-if="progress" class="callout" role="status">{{ progress }}</div>

		<div class="summary-grid" aria-label="Index summary">
			<div class="summary-card">
				<span class="summary-label">Documents</span>
				<strong>{{ total }}</strong>
				<small>Indexed files in your knowledge base</small>
			</div>
			<div class="summary-card">
				<span class="summary-label">Text chunks</span>
				<strong>{{ totalChunks }}</strong>
				<small>Searchable sections</small>
			</div>
			<div class="summary-card">
				<span class="summary-label">Indexed size</span>
				<strong>{{ fmtSize(totalSize) }}</strong>
				<small>Content currently available to EVA</small>
			</div>
		</div>

		<section class="docs-toolbar">
			<NcTextField v-model="search" label="Search documents" :label-outside="true" placeholder="File name or path" @keydown.enter="load" />
			<NcButton type="secondary" :loading="loading" @click="load">Refresh</NcButton>
		</section>

		<section class="docs-body">
			<NcEmptyContent v-if="!loading && !docs.length" class="docs-empty">
				<template #icon><span>📄</span></template>
				<template #name>No documents indexed yet</template>
				<template #description>
					Use „Start indexing“ to scan your files, then ask Eva about them.
				</template>
			</NcEmptyContent>

			<table v-else class="docs-table">
				<thead>
					<tr>
						<th class="docs-caret-head"></th>
						<th>Path</th>
						<th>Type</th>
						<th>Size</th>
						<th>Chunks</th>
						<th>Indexed</th>
					</tr>
				</thead>
				<tbody>
					<template v-for="d in docs" :key="d.id">
						<tr class="docs-row" :class="{ open: expanded.has(d.id) }" @click="toggle(d.id)">
							<td class="docs-caret">
								<svg width="14" height="14" viewBox="0 0 24 24"><path :d="expanded.has(d.id) ? mdiChevronDown : mdiChevronRight" fill="currentColor" /></svg>
							</td>
							<td class="docs-path">
								<span class="docs-path-inner" :title="d.path">/{{ d.path }}</span>
							</td>
							<td class="docs-mime">{{ d.mime }}</td>
							<td>{{ fmtSize(d.size) }}</td>
							<td class="docs-chunks">{{ d.chunks }}</td>
							<td class="docs-date">{{ fmtDate(d.indexedAt) }}</td>
						</tr>
						<tr v-if="expanded.has(d.id)" class="docs-chunkrow">
							<td colspan="6">
								<div class="docs-chunklist">
									<div v-if="chunkCache.get(d.id) === undefined" class="docs-chunk-loading">
										<NcLoadingIcon :size="16" /> Loading chunks …
									</div>
									<template v-else>
										<div v-for="c in chunkCache.get(d.id) || []" :key="c.index" class="docs-chunk">
											<span class="docs-chunk-idx">#{{ c.index + 1 }}</span>
											<span class="docs-chunk-text">{{ c.content }}</span>
										</div>
										<div v-if="!(chunkCache.get(d.id) || []).length" class="docs-chunk-loading">
											No chunks.
										</div>
									</template>
								</div>
							</td>
						</tr>
					</template>
				</tbody>			</table>
		</section>
	</div>
</template>

<script>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { api } from '../lib/api'
import { mdiChevronDown, mdiChevronRight } from '@mdi/js'

export default {
	name: 'DocumentsView',
	setup() {
		const docs = ref([])
		const total = ref(0)
		const totalChunks = ref(0)
		const totalSize = ref(0)
		const search = ref('')
		const loading = ref(false)
		const indexing = ref(false)
		const stopping = ref(false)
		const indexStatus = ref(null)
		const progress = ref('')
		const indexingActive = computed(() => indexing.value || indexStatus.value?.indexing === true)
		const expanded = ref(new Set())
		const chunkCache = ref(new Map())


		const loadStatus = async () => {
			try {
				indexStatus.value = await api('GET', 'status')
			} catch (e) {
				console.error('[eva-ai] status error', e)
			}
		}

		const startIndex = async () => {
			if (indexingActive.value) return
			indexing.value = true
			progress.value = 'Indexing is being queued in the background …'
			try {
				const response = await api('POST', 'index') || {}
				indexStatus.value = response.status || indexStatus.value
				progress.value = 'Indexing queued. It continues even if you close the website.'
			} catch (e) {
				progress.value = 'Indexing could not be queued: ' + e
			} finally {
				indexing.value = false
				load()
			}
		}

		const startMailIndex = async () => {
			if (indexingActive.value) return
			indexing.value = true
			progress.value = 'Email indexing is being queued in the background …'
			try {
				const response = await api('POST', 'mailIndex') || {}
				indexStatus.value = response.status || indexStatus.value
				progress.value = 'Email indexing queued. It continues even if you close the website.'
			} catch (e) {
				progress.value = 'Email indexing could not be queued: ' + e
			} finally {
				indexing.value = false
			}
		}

		const stopIndex = async () => {
			if (stopping.value) return
			stopping.value = true
			try {
				const response = await api('POST', 'indexStop') || {}
				indexStatus.value = response.status || indexStatus.value
				progress.value = 'Indexing stopped.'
			} catch (e) {
				progress.value = 'Indexing could not be stopped: ' + e
			} finally {
				stopping.value = false
				await loadStatus()
				await load()
			}
		}

		async function load() {
			loading.value = true
			try {
				const data = await api('GET', 'documents', { search: search.value, limit: 500 })
				docs.value = data.documents || []
				total.value = data.total || docs.value.length
				totalChunks.value = data.totalChunks || 0
				totalSize.value = data.totalSize || 0
			} catch (e) {
				docs.value = []
				total.value = 0
				totalChunks.value = 0
				totalSize.value = 0
				console.error('[eva-ai] documents error', e)
			} finally {
				loading.value = false
			}
		}

		async function loadChunks(id) {
			if (chunkCache.value.has(id)) return
			chunkCache.value.set(id, undefined)
			try {
				const data = await api('POST', 'documentChunks', { id })
				chunkCache.value.set(id, (data && data.chunks) || [])
			} catch (e) {
				chunkCache.value.set(id, [])
				console.error('[eva-ai] chunks error', e)
			}
		}

		function toggle(id) {
			const set = new Set(expanded.value)
			if (set.has(id)) {
				set.delete(id)
			} else {
				set.add(id)
				loadChunks(id)
			}
			expanded.value = set
		}

		function fmtSize(b) {
			if (b == null) return '—'
			const n = Number(b)
			if (n < 1024) return n + ' B'
			if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB'
			return (n / (1024 * 1024)).toFixed(1) + ' MB'
		}

		function fmtDate(ts) {
			if (!ts) return '—'
			const d = new Date(Number(ts) * 1000)
			if (isNaN(d)) return '—'
			return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
				+ ' ' + d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
		}

		let statusTimer = null
		onMounted(async () => {
			await Promise.all([load(), loadStatus()])
			statusTimer = window.setInterval(loadStatus, 3000)
		})
		onUnmounted(() => {
			if (statusTimer !== null) window.clearInterval(statusTimer)
		})

		return { docs, total, totalChunks, totalSize, search, loading, indexing, stopping, indexStatus, indexingActive, progress, expanded, chunkCache, load, loadStatus, toggle, startIndex, startMailIndex, stopIndex, fmtSize, fmtDate, mdiChevronDown, mdiChevronRight }
	},
}
</script>

<style scoped lang="scss">
.docs-view {
	width: 100%;
	max-width: 1120px;
	margin: 0 auto;
	padding: 24px clamp(16px, 3vw, 36px) 48px;
	box-sizing: border-box;
}

.page-header {
	display: flex;
	align-items: flex-end;
	justify-content: space-between;
	gap: 24px;
	margin-bottom: 24px;
}

.header-copy { max-width: 700px; }
.eyebrow { margin: 0 0 4px; color: var(--color-primary-element); font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
.docs-title { margin: 0; font-size: clamp(24px, 3vw, 32px); font-weight: 700; letter-spacing: -.02em; }
.page-intro { margin: 8px 0 0; color: var(--color-text-maxcontrast); font-size: 14px; line-height: 1.55; }
.header-actions { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 8px; flex-shrink: 0; }

.callout {	margin: 0 0 16px; padding: 12px 14px; border: 1px solid var(--color-border); border-radius: 8px; color: var(--color-text-maxcontrast); font-size: 13px; }
.indexing-callout { display: flex; align-items: center; gap: 8px; border-color: color-mix(in srgb, var(--color-primary-element) 42%, var(--color-border)); }
.summary-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
.summary-card { min-height: 76px; padding: 14px; border: 1px solid var(--color-border); border-radius: 8px; background: var(--color-main-background); box-sizing: border-box; }
.summary-label { display: block; color: var(--color-text-maxcontrast); font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
.summary-card strong { display: block; margin-top: 3px; font-size: 20px; }
.summary-card small { display: block; margin-top: 4px; color: var(--color-text-maxcontrast); font-size: 12px; }
.docs-toolbar { display: flex; align-items: flex-end; gap: 12px; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid var(--color-border); }
.docs-toolbar > :first-child { flex: 1; min-width: 0; }

.docs-body {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 9px;
	overflow: hidden;
}

.docs-empty {
	padding: 40px 0;
}

.docs-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 13px;
}

.docs-table th,
.docs-table td {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.docs-table th {
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}

.docs-caret-head {
	width: 28px;
}

.docs-row {
	cursor: pointer;
}

.docs-row:hover td {
	background: var(--color-background-hover);
}

.docs-row.open td {
	background: var(--color-background-hover);
}

.docs-caret {
	width: 28px;
	color: var(--color-text-maxcontrast);
}

.docs-progress {
	margin: 4px 0 10px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.docs-path {
	font-family: monospace;
	min-width: 260px;
}

.docs-path-inner {
	overflow: hidden;
	text-overflow: ellipsis;
	max-width: 420px;
	display: inline-block;
	vertical-align: middle;
}


.docs-mime {
	color: var(--color-text-maxcontrast);
}

.docs-chunks {
	text-align: center;
}

.docs-date {
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.docs-chunkrow td {
	background: var(--color-background-hover);
	padding: 4px 12px 14px;
}

.docs-chunklist {
	max-height: 340px;
	overflow-y: auto;
}

.docs-chunk {
	display: flex;
	gap: 10px;
	padding: 8px 10px;
	margin-bottom: 6px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 8px;
	font-size: 12px;
}

.docs-chunk-idx {
	flex-shrink: 0;
	font-family: monospace;
	font-weight: 700;
	color: var(--color-primary-element);
}

.docs-chunk-text {
	white-space: pre-wrap;
	word-break: break-word;
	max-height: 140px;
	overflow-y: auto;
	line-height: 1.5;
}

.docs-chunk-loading {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 12px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

@media (max-width: 800px) {
	.page-header { align-items: flex-start; flex-direction: column; }
	.header-actions { width: 100%; justify-content: flex-start; }
	.summary-grid { grid-template-columns: 1fr; }
	.docs-toolbar { align-items: stretch; flex-direction: column; }
	.docs-toolbar :deep(.button-vue) { width: 100%; }
	.docs-table { min-width: 720px; }
	.docs-body { overflow-x: auto; }
}

@media (max-width: 500px) {
	.docs-view { padding: 18px 12px 36px; }
}
</style>
