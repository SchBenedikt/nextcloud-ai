<template>
	<div class="docs-view">
		<div class="docs-header">
			<h2 class="docs-title">Indexed documents</h2>
			<div class="docs-actions">
				<NcTextField
					:value="search"
					:label="'Search…'"
					:label-outside="true"
					:placeholder="'File name'"
					@update:value="search = $event"
					@keydown.enter="load" />
				<NcButton type="secondary" class="doc-refresh" :loading="indexing" @click="startIndex">
					<template #icon>
						<svg width="18" height="18" viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27a6.5 6.5 0 1 0-.7.7l.27.28v.79l5 4.99L20.49 19l-4.99-5Zm-6 0A4.5 4.5 0 1 1 14 9.5 4.5 4.5 0 0 1 9.5 14Z" fill="currentColor" /></svg>
					</template>
					Start indexing
				</NcButton>
			</div>
		</div>

		<p v-if="progress" class="docs-progress">{{ progress }}</p>

		<div class="docs-stats" v-if="total">
			<span class="docs-stat">{{ total }} documents</span>
			<span class="docs-stat-dot">·</span>
			<span class="docs-stat">{{ totalChunks }} chunks</span>
			<span class="docs-stat-dot">·</span>
			<span class="docs-stat">{{ fmtSize(totalSize) }} indexed</span>
		</div>

		<div class="docs-body">
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
								<button
									class="docs-ask"
									title="Ask Eva about this document"
									@click.stop="askAbout(d)">
									<svg width="14" height="14" viewBox="0 0 24 24"><path :d="mdiChatProcessing" fill="currentColor" /></svg>
								</button>
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
				</tbody>
			</table>
		</div>
	</div>
</template>

<script>
import { ref, onMounted } from 'vue'
import { api } from '../lib/api'
import { mdiRefresh, mdiChevronDown, mdiChevronRight, mdiChatProcessing } from '@mdi/js'

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
		const progress = ref('')
		const expanded = ref(new Set())
		const chunkCache = ref(new Map())

		const askAbout = (d) => {
			const prompt = 'Please summarize the following file from my files and list the most important points: "' + (d.path || '') + '" — please read the file using your tools.'
			if (typeof window !== 'undefined' && window.dispatchEvent) {
				window.dispatchEvent(new CustomEvent('eva-ai:ask-about', { detail: { prompt } }))
			}
		}

		const startIndex = async () => {
			indexing.value = true
			progress.value = 'Indexing running … (may take a while depending on your files)'
			try {
				const res = await api('POST', 'index') || {}
				progress.value = 'Processed: ' + (res.processed ?? 0) + ' · Skipped: ' + (res.skipped ?? 0) + ' · Found: ' + (res.total_seen ?? 0)
				if (res.error) progress.value += ' · Error: ' + res.error
			} catch (e) {
				progress.value = 'Indexing failed: ' + e
			} finally {
				indexing.value = false
				load()
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

		onMounted(load)

		return { docs, total, totalChunks, totalSize, search, loading, indexing, progress, expanded, chunkCache, load, toggle, askAbout, startIndex, fmtSize, fmtDate, mdiRefresh, mdiChevronDown, mdiChevronRight, mdiChatProcessing }
	},
}
</script>

<style scoped lang="scss">
.docs-view {
	padding: 16px 20px;
	box-sizing: border-box;
}

.docs-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
	margin-bottom: 12px;
}

.docs-title,
.docs-header h2 {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
}

.docs-actions {
	display: flex;
	gap: 8px;
	align-items: center;
}

.doc-refresh {
	min-height: 48px;
	min-width: 190px;
	padding: 0 32px;
	font-size: 16px;
	font-weight: 600;
	justify-content: center;
}

.docs-stats {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 12px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.docs-stat {
	font-weight: 600;
}

.docs-stat-dot {
	opacity: 0.5;
}

.docs-body {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 12px;
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

.docs-ask {
	margin-left: 8px;
	vertical-align: middle;
	border: none;
	background: transparent;
	color: var(--color-text-maxcontrast, #888);
	border-radius: 6px;
	padding: 3px 5px;
	cursor: pointer;
}

.docs-ask:hover {
	background: var(--color-primary-light, #e8f0f7);
	color: var(--color-primary-element, #00679c);
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
</style>
