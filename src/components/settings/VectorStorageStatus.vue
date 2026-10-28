<template>
	<NcSettingsSection :name="$t('Knowledge storage')"
		:description="$t('Search uses the built-in database. Optional vector storage is being prepared for larger knowledge bases.')">
		<NcLoadingIcon v-if="loading" :name="$t('Loading storage status')" />
		<NcNoteCard v-else-if="error" type="warning">
			{{ $t('Storage status could not be loaded. Retry or check the database migrations. This does not indicate an empty queue.') }}
		</NcNoteCard>
		<template v-else-if="status">
			<dl class="storage-status">
				<div><dt>{{ $t('Search backend') }}</dt><dd>{{ $t('Built-in database (SQL)') }}</dd></div>
				<div><dt>{{ $t('Optional Qdrant connection') }}</dt><dd>{{ !status.connection.configured ? $t('Not configured') : status.connection.enabled ? $t('Configured · requests allowed') : $t('Configured · requests disabled') }}</dd></div>
				<div><dt>{{ $t('Generation cleanup') }}</dt><dd>{{ status.erasure_worker_enabled ? $t('Enabled') : $t('Paused') }}</dd></div>
				<div><dt>{{ $t('Chunk synchronization') }}</dt><dd>{{ status.point_worker_enabled ? $t('Enabled') : $t('Paused') }}</dd></div>
				<div><dt>{{ $t('Generation chunks in SQL') }}</dt><dd>{{ status.staged_chunks }}</dd></div>
				<div><dt>{{ $t('Waiting operations') }}</dt><dd>{{ status.queue.pending }}</dd></div>
				<div><dt>{{ $t('Claimed operations') }}</dt><dd>{{ status.queue.processing }}</dd></div>
				<div><dt>{{ $t('Operations needing attention') }}</dt><dd>{{ status.queue.dead }}</dd></div>
			</dl>
			<NcCheckboxRadioSwitch :model-value="status.points_requested" :disabled="pointSaving || loading" @update:model-value="setPointDelivery">{{ $t('Allow prepared vector point delivery') }}</NcCheckboxRadioSwitch>
			<p class="storage-hint">{{ $t('Enabling also resumes generation cleanup when storage requests are allowed. Disabling pauses point delivery; use Disable storage requests to stop all transfers. This does not backfill files or switch search.') }}</p>
			<NcNoteCard v-if="pointError" type="error">{{ pointError }}</NcNoteCard>
			<NcNoteCard v-if="status.queue.dead > 0" type="warning">
				{{ $t('Some background operations exhausted their retries. An administrator needs to inspect them before retrying.') }}
			</NcNoteCard>
			<p class="storage-hint">{{ $t('Configuration status is not a connection test. Generation cleanup only removes retired search collections; it does not index files or switch search backends.') }}</p>
			<p class="storage-hint">{{ $t('Removing a file clears its local index and queues cleanup for prepared vector points. Renaming or changing a source discards its prepared entries for rebuilding. Prepared point updates and cleanup run only when their delivery switches allow them.') }}</p>
			<p v-if="status.staged_chunks > 0" class="storage-hint">{{ $t('Prepared generation chunks are separate from the SQL search index. Their presence does not mean migration is verified or active.') }}</p>
		</template>
		<NcButton variant="secondary" :disabled="loading" @click="refresh">{{ $t('Refresh storage status') }}</NcButton>
		<VectorConnectionSettings :enabled="status?.connection?.enabled === true" @changed="refresh" />
	</NcSettingsSection>
</template>

<script>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, NcSettingsSection } from '@nextcloud/vue'
import { api } from '../../lib/api'
import { translate as t } from '../../lib/i18n'
import VectorConnectionSettings from './VectorConnectionSettings.vue'

export default {
	name: 'VectorStorageStatus',
	components: { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, NcSettingsSection, VectorConnectionSettings },
	setup() {
		const status = ref(null)
		const loading = ref(false)
		const error = ref(false)
		const pointSaving = ref(false)
		const pointError = ref('')
		let controller
		async function refresh() {
			if (loading.value) return
			controller = new AbortController()
			loading.value = true
			error.value = false
			status.value = null
			try {
				const result = await api('GET', 'admin/vector-storage', null, { signal: controller.signal, retries: 0, timeout: 10000 })
				if (result?.available !== true || result.retrieval_backend !== 'sql_legacy'
					|| !Number.isSafeInteger(result.staged_chunks) || result.staged_chunks < 0
					|| result.chunk_sync_available !== true || !result.connection
					|| typeof result.points_requested !== 'boolean' || typeof result.point_worker_enabled !== 'boolean'
					|| ['configured', 'enabled'].some(key => typeof result.connection[key] !== 'boolean')
					|| typeof result.erasure_worker_enabled !== 'boolean'
					|| !result.queue || ['pending', 'processing', 'dead'].some(key => !Number.isSafeInteger(result.queue[key]) || result.queue[key] < 0)) {
					throw new Error('Invalid storage status')
				}
				status.value = result
			} catch (e) {
				if (!controller.signal.aborted) error.value = true
			} finally {
				loading.value = false
			}
		}
		async function setPointDelivery(enabled) {
			if (pointSaving.value || loading.value || typeof enabled !== 'boolean') return
			pointSaving.value = true
			pointError.value = ''
			try {
				await api('POST', 'admin/vector-storage/points', { enabled }, { retries: 0 })
				await refresh()
			} catch (_) {
				pointError.value = t('Point delivery settings could not be saved. Refresh the storage status before retrying.')
				await refresh()
			} finally { pointSaving.value = false }
		}
		onMounted(refresh)
		onBeforeUnmount(() => controller?.abort())
		return { status, loading, error, refresh, pointSaving, pointError, setPointDelivery }
	},
}
</script>

<style scoped>
.storage-status { margin: 16px 0; }
.storage-status > div { display: flex; flex-wrap: wrap; gap: 4px 20px; padding: 6px 0; }
.storage-status dt { flex: 1 1 220px; font-weight: 600; }
.storage-status dd { flex: 1 1 220px; margin: 0; }
.storage-hint { color: var(--color-text-maxcontrast); margin: 12px 0; }
</style>
