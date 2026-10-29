<template>
	<div class="generation-build" aria-live="polite">
		<h4>{{ $t('Background build') }}</h4>
		<NcLoadingIcon v-if="loading && !state" :name="$t('Loading build progress')" />
		<NcNoteCard v-if="error" type="warning">{{ error }}</NcNoteCard>
		<template v-if="state">
			<p>{{ label }}</p>
			<template v-if="state.status !== 'not_started'">
				<NcProgressBar :value="progress" :aria-label="$t('Documents staged')" />
				<p>{{ $t('Documents staged: {done} of {total}', { done: state.documents_done, total: state.total_documents }) }} · {{ $t('Chunks staged: {count}', { count: state.staged_chunks }) }}</p>
			</template>
			<NcNoteCard v-if="state.status === 'staged'" type="info">{{ $t('Source processing finished. Complete index verification is still required before search can change.') }}</NcNoteCard>
			<NcNoteCard v-else-if="!state.can_start" type="info">{{ $t('Enable the owner’s source index, prepare the generation on the current storage destination and allow point delivery. Search continues to use SQL.') }}</NcNoteCard>
			<NcNoteCard v-if="state.error_code" type="warning">{{ failure }}</NcNoteCard>
			<template v-if="state.status === 'not_started'">
				<p>{{ $t('The build reads the owner’s indexed sources and sends text to the generation’s fixed embedding provider. It runs in the background and does not change search.') }}</p>
				<NcCheckboxRadioSwitch v-model="confirmed" :disabled="!state.can_start || busy">{{ $t('Allow document processing for this generation') }}</NcCheckboxRadioSwitch>
			</template>
			<div class="generation-build-actions">
				<NcButton v-if="state.status === 'not_started'" :disabled="!state.can_start || !confirmed || busy" :loading="busy" @click="control('start')">{{ $t('Start background build') }}</NcButton>
				<NcButton v-else-if="['queued', 'running'].includes(state.status)" :disabled="busy" :loading="busy" @click="control('pause')">{{ $t('Pause build') }}</NcButton>
				<NcButton v-else-if="['paused', 'failed', 'cancelled'].includes(state.status)" :disabled="!state.can_start || busy" :loading="busy" @click="control('resume')">{{ $t('Resume build') }}</NcButton>
				<NcButton :disabled="busy || loading" variant="tertiary" @click="load">{{ $t('Refresh build progress') }}</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, NcProgressBar } from '@nextcloud/vue'
import { api, errMsg } from '../../lib/api'
import { translate as t } from '../../lib/i18n'

export default {
	name: 'GenerationBuildSettings',
	components: { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, NcProgressBar },
	props: { user: { type: String, required: true }, generation: { type: String, required: true } },
	setup(props) {
		const state = ref(null), error = ref(''), loading = ref(false), busy = ref(false), confirmed = ref(false)
		let controller, epoch = 0, timer
		const path = `admin/vector-generations/${encodeURIComponent(props.user)}/${props.generation}/build`
		const states = ['not_started', 'queued', 'running', 'paused', 'cancelled', 'failed', 'staged']
		const label = computed(() => ({ not_started: t('Not started'), queued: t('Waiting for the next background tick'), running: t('Processing sources'), paused: t('Build paused'), cancelled: t('Build stopped'), failed: t('Build needs attention'), staged: t('Staged; not verified') })[state.value?.status])
		const progress = computed(() => state.value?.total_documents > 0 ? Math.min(100, 100 * state.value.documents_done / state.value.total_documents) : 0)
		const failure = computed(() => {
			const code = state.value?.error_code
			if (code === 'owner_index_paused') return t('The owner paused ordinary indexing. Enable it again before resuming this build.')
			if (code === 'source_extraction_failed') return t('A source could not be extracted. Check source access, local conversion tools and transport timeouts, then resume.')
			if (['source_changed', 'source_unavailable'].includes(code)) return t('A source changed or is no longer accessible. Reconcile the owner’s ordinary index, then resume the build.')
			if (['source_too_large', 'source_chunk_limit'].includes(code)) return t('A source exceeds the build budget. Review its size and the generation’s chunk settings before retrying.')
			return t('The build stopped safely. Check the fixed embedding model, provider policy and storage connection, then resume.')
		})
		function accept(result) {
			if (!result || !states.includes(result.status) || typeof result.can_start !== 'boolean' || result.retrieval_changed !== false || result.verified !== false
				|| !['documents_done', 'total_documents', 'staged_chunks', 'heartbeat_at'].every(key => Number.isSafeInteger(result[key]) && result[key] >= 0)
				|| !(result.error_code === null || typeof result.error_code === 'string')) throw new Error(t('Build progress could not be confirmed.'))
			state.value = result
		}
		async function request(action) {
			const current = ++epoch
			controller?.abort(); controller = new AbortController()
			error.value = ''; loading.value = true
			try {
				const result = await api(action ? 'POST' : 'GET', action ? `${path}/${action}` : path, action ? { confirm_document_transfer: confirmed.value } : {}, { signal: controller.signal, timeout: 10000, retries: 0 })
				if (current === epoch) accept(result)
			} catch (e) { if (current === epoch && !controller.signal.aborted) error.value = errMsg(e) }
			finally { if (current === epoch) loading.value = false }
		}
		async function load() { if (!busy.value && !loading.value) await request() }
		async function control(action) {
			if (busy.value || loading.value) return
			busy.value = true
			try { await request(action) } finally { busy.value = false }
		}
		onMounted(() => {
			load()
			timer = setInterval(() => { if (document.visibilityState === 'visible' && ['queued', 'running'].includes(state.value?.status)) load() }, 5000)
		})
		onBeforeUnmount(() => { epoch++; controller?.abort(); clearInterval(timer) })
		return { state, error, loading, busy, confirmed, label, progress, failure, load, control }
	},
}
</script>

<style scoped>
.generation-build { border-top: 1px solid var(--color-border); margin-top: 20px; padding-top: 16px; }
.generation-build h4 { margin: 0 0 12px; }
.generation-build p { margin: 12px 0; }
.generation-build-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
</style>
