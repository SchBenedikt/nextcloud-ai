<template>
	<div class="docs-view">
		<header class="page-header">
			<div class="header-copy">
				<h1 class="docs-title">{{ $t('Documents') }}</h1>
				<p class="page-intro">{{ $t('Review the files in your personal EVA knowledge base and inspect their indexed text.') }}</p>
			</div>
			<div class="header-actions">
				<NcButton variant="primary" :loading="indexing" :disabled="indexControlsLocked" @click="startIndex">{{ $t('Index files & emails') }}</NcButton>
				<NcActions :aria-label="$t('More indexing options')">
					<NcActionButton :disabled="indexControlsLocked" :close-after-click="true" @click="startMailIndex">{{ $t('Only emails') }}</NcActionButton>
					<NcActionButton :disabled="indexControlsLocked" :close-after-click="true" @click="startTalkIndex">{{ $t('Index Talk chats') }}</NcActionButton>
				</NcActions>
				<NcButton v-if="indexingActive" variant="tertiary-no-background" :loading="stopping" :disabled="indexStatus?.indexStopping" @click="stopIndex">{{ $t('Stop') }}</NcButton>
			</div>
		</header>

		<NcNoteCard v-if="indexingActive" type="info">
			<strong>{{ indexStatus?.indexStopping ? $t('Stopping indexing…') : indexStatus?.indexMode === 'mail' ? $t('Email indexing is running') : indexStatus?.indexMode === 'talk' ? $t('Chat indexing is running') : $t('Indexing is running') }}</strong>
			<span>{{ $t('The job continues on the server even if you close this page.') }}</span>
		</NcNoteCard>
		<NcNoteCard v-if="!indexStatusKnown && indexStatusLoading" type="info">{{ $t('Checking indexing status…') }}</NcNoteCard>
		<NcNoteCard v-if="indexStatusError" type="error"><span>{{ $t('Indexing status could not be loaded: {error}', { error: indexStatusError }) }}</span><NcButton variant="tertiary" :loading="indexStatusLoading" :disabled="indexStatusLoading" @click="loadStatus">{{ $t('Try again') }}</NcButton></NcNoteCard>
		<NcNoteCard v-if="progress" type="info">{{ progress }}</NcNoteCard>

		<p v-if="documentsLoaded" class="docs-summary">{{ $t('{count} documents', { count: total }) }} · {{ fmtSize(totalSize) }}</p>

		<section class="docs-toolbar">
			<NcTextField v-model="search" :label="$t('Search documents')" :placeholder="$t('File name or path')" :disabled="loading || loadingMore" @keydown.enter="load" />
			<details class="docs-filters"><summary>{{ $t('Filters & sorting') }}</summary><div class="docs-filter-fields">
			<NcSelect v-model="filterType" :input-label="$t('Type')" :options="typeOptions" label="label" :reduce="option => option.value" :clearable="false" :disabled="loading || loadingMore" @update:model-value="load" />
			<NcTextField v-model="filterFolder" :label="$t('Folder')" :placeholder="$t('Folder path')" :disabled="loading || loadingMore" @keydown.enter="load" />
			<NcSelect v-model="filterSize" :input-label="$t('Minimum size')" :options="sizeOptions" label="label" :reduce="option => option.value" :clearable="false" :disabled="loading || loadingMore" @update:model-value="load" />
			<NcSelect v-model="filterSort" :input-label="$t('Sort')" :options="sortOptions" label="label" :reduce="option => option.value" :clearable="false" :disabled="loading || loadingMore" @update:model-value="load" />
			</div></details>
				<NcButton variant="secondary" :loading="loading" :disabled="loadingMore" @click="load">{{ $t('Refresh') }}</NcButton>
		</section>

		<section class="docs-body">
			<div v-if="documentsLoadError" class="docs-load-error" role="alert">
				<span>{{ $t('Documents could not be loaded: {error}', { error: documentsLoadError }) }}</span>
				<NcButton variant="tertiary" :disabled="loading" @click="load">{{ $t('Retry') }}</NcButton>
			</div>
			<NcEmptyContent v-if="documentsLoaded && !loading && !documentsLoadError && !docs.length" class="docs-empty">
				<template #icon><NcIconSvgWrapper :path="mdiFileDocumentOutline" :size="48" /></template>
				<template #name>{{ $t('No documents indexed yet') }}</template>
				<template #description>
					{{ $t('Use one of the indexing options above to add content, then ask EVA about it.') }}
				</template>
			</NcEmptyContent>

			<div v-if="(loading && !docs.length) || (!documentsLoaded && !documentsLoadError)" class="docs-loading" role="status">{{ $t('Loading documents…') }}</div>
			<table v-if="docs.length" class="docs-table">
				<thead>
					<tr>
						<th class="docs-caret-head"></th>
						<th>{{ $t('Path') }}</th>
						<th>{{ $t('Type') }}</th>
						<th>{{ $t('Size') }}</th>
						<th>{{ $t('Chunks') }}</th>
						<th>{{ $t('Indexed') }}</th>
					</tr>
				</thead>
				<tbody>
					<template v-for="d in docs" :key="d.id">
						<tr
							class="docs-row"
							:class="{ open: expanded.has(d.id) }"
							tabindex="0"
							:aria-expanded="expanded.has(d.id)"
							:aria-label="d.path + ' , ' + d.chunks + ' chunks'"
							@click="toggle(d.id, d.chunks)"
							@keydown.enter.prevent="toggle(d.id, d.chunks)"
							@keydown.space.prevent="toggle(d.id, d.chunks)">
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
									<div v-if="!chunkCache.get(d.id) || chunkCache.get(d.id).status === 'loading'" class="docs-chunk-loading">
										<NcLoadingIcon :size="16" /> {{ $t('Loading {count} chunks …', { count: d.chunks || 0 }) }}
									</div>
									<div v-else-if="chunkCache.get(d.id).status === 'error'" class="docs-chunk-state docs-chunk-state-error" role="alert">
										<div><strong>{{ $t('Chunks could not be loaded.') }}</strong> {{ chunkCache.get(d.id).error }}</div>
										<NcButton variant="tertiary" @click.stop="loadChunks(d.id, d.chunks, true)">{{ $t('Retry') }}</NcButton>
									</div>
									<template v-else>
										<div v-for="c in chunkCache.get(d.id).chunks" :key="c.index" class="docs-chunk">
											<span class="docs-chunk-idx">#{{ c.index + 1 }}</span>
											<span v-if="c.provenance?.section" class="docs-chunk-location">{{ c.provenance.section }}</span>
											<span class="docs-chunk-text">{{ c.content }}</span>
										</div>
										<div v-if="!chunkCache.get(d.id).chunks.length" class="docs-chunk-loading">
											{{ $t('No indexed chunk rows are available for this document.') }}
										</div>
										<p v-if="chunkCache.get(d.id).moreError" role="alert">{{ $t('Chunks could not be loaded.') }} {{ chunkCache.get(d.id).moreError }}</p>
										<div v-if="chunkCache.get(d.id).status === 'ready' && chunkCache.get(d.id).chunks.length < chunkCache.get(d.id).expected" class="docs-chunk-more">
									<NcButton v-if="!chunkCache.get(d.id).loadingMore" variant="secondary" @click.stop="loadMoreChunks(d.id)">
												{{ $t('Load {count} more chunks', { count: chunkCache.get(d.id).expected - chunkCache.get(d.id).chunks.length }) }}
											</NcButton>
											<span v-else><NcLoadingIcon :size="16" /> {{ $t('Loading chunks …') }}</span>
										</div>
									</template>
								</div>
							</td>
						</tr>
					</template>
				</tbody>			</table>
				<div v-if="docs.length" class="docs-pagination" role="status">
					<span>{{ $t('Showing {shown} of {total} documents', { shown: docs.length, total }) }}</span>
					<div v-if="loadMoreError" class="docs-pagination-error" role="alert">
						<span>{{ loadMoreError }}</span>
					<NcButton variant="tertiary" @click="loadMore">{{ $t('Retry') }}</NcButton>
					</div>
					<NcButton v-else-if="hasMore" variant="secondary" :loading="loadingMore" :disabled="loading || loadingMore" @click="loadMore">{{ $t('Load more') }}</NcButton>
					<span v-else class="docs-pagination-end">{{ $t('All matching documents are loaded.') }}</span>
				</div>
		</section>
	</div>
</template>

<script>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { api, errMsg } from '../lib/api'
import { translate as t } from '../lib/i18n'
import { NcActions, NcActionButton, NcSelect, NcNoteCard } from '@nextcloud/vue'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiChevronDown, mdiChevronRight, mdiFileDocumentOutline } from '@mdi/js'

export default {
	name: 'DocumentsView',
	components: { NcIconSvgWrapper, NcActions, NcActionButton, NcSelect, NcNoteCard },
	setup() {
		const docs = ref([])
		const total = ref(0)
		const totalChunks = ref(0)
		const totalSize = ref(0)
		const search = ref('')
		// Document filters and sorting (Issue #88).
		const typeOptions = [{ value: '', label: t('All types') }, ...['text', 'application', 'image', 'audio', 'video', 'message'].map(value => ({ value, label: value + '/*' }))]
		const sizeOptions = [{ value: '', label: t('Any size') }, { value: '10000', label: '≥ 10 KB' }, { value: '100000', label: '≥ 100 KB' }, { value: '1000000', label: '≥ 1 MB' }]
		const sortOptions = [['date-desc', 'Newest first'], ['date-asc', 'Oldest first'], ['name-asc', 'Name A–Z'], ['name-desc', 'Name Z–A'], ['size-desc', 'Largest first'], ['size-asc', 'Smallest first'], ['chunks-desc', 'Most chunks first']].map(([value, label]) => ({ value, label: t(label) }))
		const filterType = ref('')
		const filterFolder = ref('')
		const filterSize = ref('')
		const filterSort = ref('date-desc')
		const loading = ref(false)
		const loadingMore = ref(false)
		const documentsLoaded = ref(false)
		const documentsLoadError = ref('')
		const pageSize = 100
		const hasMore = ref(true)
		const loadMoreError = ref('')
		const indexing = ref(false)
		const stopping = ref(false)
		const indexStatus = ref(null)
		const indexStatusKnown = ref(false)
		const indexStatusLoading = ref(true)
		const indexStatusError = ref('')
		const progress = ref('')
		const indexingActive = computed(() => indexing.value || indexStatus.value?.indexing === true)
		const indexControlsLocked = computed(() => !indexStatusKnown.value || !!indexStatusError.value || indexingActive.value)
		let statusRequestInFlight = false
		let documentsRequestController = null
		const expanded = ref(new Set())
		const chunkCache = ref(new Map())


		const loadStatus = async () => {
			if (statusRequestInFlight) return
			statusRequestInFlight = true
			indexStatusLoading.value = true
			try {
				const status = await api('GET', 'index/status')
				if (!status || typeof status !== 'object') throw new Error(t('The indexing status response was invalid.'))
				indexStatus.value = status
				indexStatusKnown.value = true
				indexStatusError.value = ''
			} catch (error) {
				indexStatusError.value = errMsg(error)
				console.error('[eva-ai] status error', error)
			} finally {
				indexStatusLoading.value = false
				statusRequestInFlight = false
			}
		}

		const startIndex = async () => {
			if (indexControlsLocked.value) return
			indexing.value = true
			progress.value = t('Indexing is being queued in the background …')
			try {
				const response = await api('POST', 'index') || {}
				indexStatus.value = response.status || indexStatus.value
				progress.value = t('Indexing queued. It continues even if you close the website.')
			} catch (e) {
				progress.value = t('Indexing could not be queued: {error}', { error: errMsg(e) })
			} finally {
				indexing.value = false
				load()
			}
		}

		const startMailIndex = async () => {
			if (indexControlsLocked.value) return
			indexing.value = true
			progress.value = t('Email indexing is being queued in the background …')
			try {
				const response = await api('POST', 'mailIndex') || {}
				indexStatus.value = response.status || indexStatus.value
				progress.value = t('Email indexing queued. It continues even if you close the website.')
			} catch (e) {
				progress.value = t('Email indexing could not be queued: {error}', { error: errMsg(e) })
			} finally {
				indexing.value = false
			}
		}

		const startTalkIndex = async () => {
			if (indexControlsLocked.value) return
			indexing.value = true
			progress.value = t('Indexing your Nextcloud Talk chat histories is being queued in the background …')
			try {
				const response = await api('POST', 'talkIndex') || {}
				indexStatus.value = response.status || indexStatus.value
				progress.value = t('Chat indexing queued. It continues even if you close the website.')
			} catch (e) {
				progress.value = t('Chat indexing could not be queued: {error}', { error: errMsg(e) })
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
				progress.value = response?.stopping ? t('Stop requested. Indexing will finish the current cancellable request and then release its lock.') : t('Indexing stopped.')
				for (let i = 0; i < 30 && indexStatus.value?.indexStopping; i++) {
					await new Promise(resolve => window.setTimeout(resolve, 1000))
					await loadStatus()
				}
			} catch (e) {
				progress.value = t('Indexing could not be stopped: {error}', { error: errMsg(e) })
			} finally {
				stopping.value = false
				await loadStatus()
				await load()
			}
		}

		async function fetchDocuments(append = false) {
			if (append) {
				loadingMore.value = true
				loadMoreError.value = ''
			} else {
				loading.value = true
				documentsLoadError.value = ''
			}
			const offset = append ? docs.value.length : 0
			if (!append && documentsRequestController) documentsRequestController.abort()
			const requestController = new AbortController()
			documentsRequestController = requestController
			try {
				const [sort, dir] = filterSort.value.split('-')
				const params = {
					search: search.value,
					limit: pageSize,
					offset,
					sort,
					dir,
				}
				if (filterType.value) params.type = filterType.value
				if (filterFolder.value.trim()) params.folder = filterFolder.value.trim()
				if (filterSize.value) params.sizeMin = Number(filterSize.value)
				const data = await api('GET', 'documents', params, { signal: requestController.signal })
				const requiredTotals = ['total', 'totalChunks', 'totalSize']
				if (!data || typeof data !== 'object' || !Array.isArray(data.documents)
					|| requiredTotals.some(key => data[key] === null || data[key] === undefined || !Number.isFinite(Number(data[key])) || Number(data[key]) < 0)) {
					throw new Error(t('The documents response was incomplete.'))
				}
				if (disposed || requestController.signal.aborted) return
				const incoming = data.documents
				docs.value = append ? docs.value.concat(incoming) : incoming
				total.value = Number(data.total)
				totalChunks.value = Number(data.totalChunks)
				totalSize.value = Number(data.totalSize)
				hasMore.value = incoming.length === pageSize && docs.value.length < total.value
				if (!append) {
					documentsLoaded.value = true
					expanded.value = new Set()
					chunkCache.value = new Map()
				}
			} catch (e) {
				if (e?.name === 'CanceledError' || e?.code === 'ERR_CANCELED' || e?.name === 'AbortError') return
				if (!append) {
					documentsLoadError.value = errMsg(e)
				} else {
					loadMoreError.value = t('More documents could not be loaded. Please try again.')
				}
				console.error('[eva-ai] documents error', e)
			} finally {
				loading.value = false
				loadingMore.value = false
				if (documentsRequestController === requestController) documentsRequestController = null
			}
		}

		async function load() {
			if (loading.value || loadingMore.value) return
			await fetchDocuments(false)
		}

		async function loadMore() {
			if (loading.value || loadingMore.value || !hasMore.value) return
			await fetchDocuments(true)
		}

		function setChunkState(id, state) {
				const next = new Map(chunkCache.value)
				next.set(id, state)
				chunkCache.value = next
			}

			async function loadChunks(id, expectedChunks = 0, retry = false) {
				const cached = chunkCache.value.get(id)
				if (!retry && cached && cached.status !== 'error') return
				const fallbackExpected = Number(expectedChunks) || 0
				setChunkState(id, { status: 'loading', chunks: [], expected: fallbackExpected, error: '' })
				let expected = fallbackExpected
				try {
					const data = await api('POST', 'documentChunks', { id, limit: 200, offset: 0 })
					const source = Array.isArray(data)
						? data
						: Array.isArray(data?.chunks)
							? data.chunks
							: Array.isArray(data?.data?.chunks)
								? data.data.chunks
								: Array.isArray(data?.data) ? data.data : []
					const chunks = source.map((chunk, index) => {
						const rawIndex = chunk.index ?? chunk.chunk_index
						const numericIndex = rawIndex !== null && rawIndex !== undefined && String(rawIndex).trim() !== ''
							? Number(rawIndex)
							: NaN
						return {
							index: Number.isFinite(numericIndex) ? numericIndex : index,
							content: String(chunk.content ?? ''),
							provenance: chunk.provenance || {},
						}
					})
					const reportedExpected = data?.document?.chunks
					if (reportedExpected !== null && reportedExpected !== undefined && String(reportedExpected).trim() !== '' && Number.isFinite(Number(reportedExpected))) {
						expected = Number(reportedExpected)
					}
					if (!chunks.length && expected > 0) {
						throw new Error(`The server reported ${expected} chunks, but returned no chunk rows.`)
					}
					setChunkState(id, { status: 'ready', chunks, expected, error: '', loadingMore: false })
				} catch (e) {
					const message = e instanceof Error ? e.message : String(e)
					setChunkState(id, { status: 'error', chunks: [], expected, error: message })
					console.error('[eva-ai] chunks error', e)
				}
			}

			async function loadMoreChunks(id) {
				const state = chunkCache.value.get(id)
				if (!state || state.status !== 'ready' || state.loadingMore) return
				setChunkState(id, { ...state, loadingMore: true, moreError: '' })
				try {
					const data = await api('POST', 'documentChunks', { id, limit: 200, offset: state.chunks.length })
					const source = Array.isArray(data)
						? data
						: Array.isArray(data?.chunks)
							? data.chunks
							: Array.isArray(data?.data?.chunks)
								? data.data.chunks
								: []
					const base = state.chunks.length
					const more = source.map((chunk, index) => {
						const rawIndex = chunk.index ?? chunk.chunk_index
						const numericIndex = rawIndex !== null && rawIndex !== undefined && String(rawIndex).trim() !== ''
							? Number(rawIndex)
							: NaN
						return {
							index: Number.isFinite(numericIndex) ? numericIndex : base + index,
							content: String(chunk.content ?? ''),
							provenance: chunk.provenance || {},
						}
					})
					setChunkState(id, { status: 'ready', chunks: [...state.chunks, ...more], expected: state.expected, error: '', loadingMore: false })
				} catch (e) {
					const stateNow = chunkCache.value.get(id) || state
					setChunkState(id, { ...stateNow, loadingMore: false, moreError: errMsg(e) })
					console.error('[eva-ai] chunks load-more error', e)
				}
			}


		function toggle(id, expectedChunks = 0) {
			const set = new Set(expanded.value)
			if (set.has(id)) {
				set.delete(id)
			} else {
				set.add(id)
				loadChunks(id, expectedChunks)
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
		let disposed = false
		const scheduleStatusPoll = () => {
			if (disposed) return
			if (statusTimer !== null) window.clearTimeout(statusTimer)
			// Keep active indexing responsive, but let idle pages check only
			// occasionally so an open Documents tab stays inexpensive.
			const delay = indexingActive.value ? 3000 : 30000
			statusTimer = window.setTimeout(async () => {
				if (!document.hidden) await loadStatus()
				scheduleStatusPoll()
			}, delay)
		}
		onMounted(async () => {
			await Promise.all([load(), loadStatus()])
			scheduleStatusPoll()
		})
		onUnmounted(() => {
			disposed = true
			documentsRequestController?.abort()
			if (statusTimer !== null) window.clearTimeout(statusTimer)
		})

		return { docs, total, totalChunks, totalSize, search, filterType, filterFolder, filterSize, filterSort, loading, loadingMore, documentsLoaded, documentsLoadError, hasMore, loadMoreError, indexing, stopping, indexStatus, indexStatusKnown, indexStatusLoading, indexStatusError, indexControlsLocked, indexingActive, progress, expanded, chunkCache, load, loadMore, loadChunks, loadMoreChunks, loadStatus, toggle, typeOptions, sizeOptions, sortOptions, startIndex, startMailIndex, startTalkIndex, stopIndex, fmtSize, fmtDate, mdiChevronDown, mdiChevronRight, mdiFileDocumentOutline }
	},
}
</script>

<style scoped lang="scss">
.docs-summary { margin: 0 0 20px; color: var(--color-text-maxcontrast); }
.docs-filters { position: relative; }
.docs-filters > summary { cursor: pointer; min-height: 44px; padding: 12px; border-radius: var(--border-radius); white-space: nowrap; }
.docs-filters > summary:hover { background: var(--color-background-hover); }
.docs-filter-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; position: absolute; z-index: 10; right: 0; top: 100%; width: min(480px, calc(100vw - 48px)); background: var(--color-main-background); border: 1px solid var(--color-border); border-radius: var(--border-radius-large); padding: 16px; box-shadow: 0 8px 24px #0002; }
@media (max-width: 600px) { .docs-filter-fields { position: static; width: 100%; grid-template-columns: 1fr; } }

.docs-view {
	width: 100%;
	max-width: var(--eva-content-width, 1180px);
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
.docs-title { margin: 0; font-size: 24px; font-weight: 600; }
.page-intro { margin: 8px 0 0; color: var(--color-text-maxcontrast); font-size: 14px; line-height: 1.55; }
.header-actions { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 8px; flex-shrink: 0; }

.docs-toolbar { display: flex; align-items: flex-end; gap: 12px; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid var(--color-border); flex-wrap: wrap; }
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
.docs-load-error { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 16px; border-bottom:1px solid var(--color-border); color:var(--color-error); }
.docs-loading { padding:24px 16px; color:var(--color-text-maxcontrast); }

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

.docs-row:focus-visible td {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
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
	min-width: 160px;
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
	max-height: min(58vh, 720px);
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


.docs-chunk-state {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	font-size: 13px;
}

.docs-chunk-state-error {
	border-color: var(--color-error);
	color: var(--color-error);
}

.docs-chunk-loading {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 12px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.docs-chunk-more {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 12px;
	font-size: 13px;
}

.docs-pagination {
	display: flex;
	align-items: center;
	justify-content: center;
	flex-wrap: wrap;
	gap: 12px;
	padding: 18px 12px 4px;
	border-top: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
.docs-pagination-end { color: var(--color-text-maxcontrast); }
.docs-pagination-error { display: inline-flex; align-items: center; gap: 10px; color: var(--color-error); }

@media (max-width: 800px) {
	.page-header { align-items: flex-start; flex-direction: column; }
	.header-actions { width: 100%; justify-content: flex-start; }
	.docs-toolbar { align-items: stretch; flex-direction: column; }
	.docs-toolbar :deep(.button-vue) { width: 100%; }
	.docs-table .docs-mime, .docs-table .docs-date, .docs-table th:nth-child(3), .docs-table th:nth-child(6) { display: none; }
	.docs-path { min-width: 0; }
	.docs-path-inner { max-width: min(42vw, 320px); }
	.docs-table th, .docs-table td { padding: 8px; }
	.docs-body { overflow-x: auto; }
}

@media (max-width: 500px) {
	.docs-view { padding: 18px 12px 36px; }
}
</style>
