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
			<NcNoteCard v-if="state.status === 'staged' && !state.verified" type="info">{{ state.backend === 'sql' ? $t('Source processing finished. SQL generation vectors and current source manifests are checked automatically before activation.') : $t('Source processing finished. Verify all current SQL and remote points before this remote generation can be considered ready.') }}</NcNoteCard>
			<NcNoteCard v-if="state.active && state.retrieval_backend === 'sql_generation'" type="success">{{ $t('This verified SQL generation is serving search.') }}</NcNoteCard>
			<NcNoteCard v-else-if="state.active && state.retrieval_backend === 'qdrant_generation'" type="info">{{ $t('This verified Qdrant generation is serving search as an explicit canary. Roll back from verification status at any time.') }}</NcNoteCard>
			<NcNoteCard v-else-if="state.status === 'staged' && state.verified" type="success">{{ $t('The staged SQL generation is verified and will become active automatically when its source snapshot is current.') }}</NcNoteCard>
			<NcNoteCard v-if="state.status !== 'staged' && !state.can_start && !state.active" type="info">{{ $t('Enable the owner’s source index and confirm the selected generation backend is ready. Current search remains available while this generation is prepared.') }}</NcNoteCard>
			<NcNoteCard v-if="state.error_code" type="warning">{{ failure }}</NcNoteCard>
			<template v-if="state.status === 'not_started'">
				<p>{{ $t('The build reads the owner’s indexed sources and sends text to the generation’s fixed embedding provider. Remote providers may receive document text. Qdrant builds also send vectors to the configured vector store. Once a local SQL generation is verified and current, it becomes the search index automatically.') }}</p>
				<NcCheckboxRadioSwitch v-model="confirmed" :disabled="!state.can_start || busy">{{ $t('Allow document processing for this generation') }}</NcCheckboxRadioSwitch>
			</template>
			<template v-if="state.backend === 'qdrant' && state.include_text_payload && ['not_started', 'paused', 'failed', 'cancelled'].includes(state.status)">
				<NcNoteCard type="warning">{{ $t('This generation stores document text in Qdrant for native full-text search, and sends each search query there. This is separate from sending embeddings. Text is removed when its chunk is deleted or the generation is erased. Check the Qdrant operator’s access, backup and encryption settings.') }}</NcNoteCard>
				<NcCheckboxRadioSwitch v-model="confirmedText" :disabled="!state.can_start || busy">{{ $t('Allow document text storage and query search in Qdrant') }}</NcCheckboxRadioSwitch>
			</template>
			<div class="generation-build-actions">
				<NcButton v-if="state.status === 'not_started'" :disabled="!state.can_start || !confirmed || (state.include_text_payload && !confirmedText) || busy" :loading="busy" @click="control('start')">{{ $t('Start background build') }}</NcButton>
				<NcButton v-else-if="['queued', 'running'].includes(state.status)" :disabled="busy" :loading="busy" @click="control('pause')">{{ $t('Pause build') }}</NcButton>
				<NcButton v-else-if="['paused', 'failed', 'cancelled'].includes(state.status)" :disabled="!state.can_start || (state.include_text_payload && !confirmedText) || busy" :loading="busy" @click="control('resume')">{{ $t('Resume build') }}</NcButton>
				<NcButton :disabled="busy || loading" variant="tertiary" @click="load">{{ $t('Refresh build progress') }}</NcButton>
			</div>
		</template>
		<GenerationVerificationSettings v-if="state?.status === 'staged'" :key="`${user}:${generation}:verification`" :user="user" :generation="generation" />
	</div>
</template>

<script>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, NcProgressBar } from '@nextcloud/vue'
import { api, errMsg } from '../../lib/api'
import { translate as t } from '../../lib/i18n'
import GenerationVerificationSettings from './GenerationVerificationSettings.vue'

export default {
	name: 'GenerationBuildSettings',
	components: { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, NcProgressBar, GenerationVerificationSettings },
	props: { user: { type: String, required: true }, generation: { type: String, required: true } },
	setup(props) {
		const state = ref(null), error = ref(''), loading = ref(false), busy = ref(false), confirmed = ref(false), confirmedText = ref(false)
		let controller, epoch = 0, timer
		const path = `admin/vector-generations/${encodeURIComponent(props.user)}/${props.generation}/build`
		const states = ['not_started', 'queued', 'running', 'paused', 'cancelled', 'failed', 'staged']
		const label = computed(() => ({ not_started: t('Not started'), queued: t('Waiting for the next background tick'), running: t('Processing sources'), paused: t('Build paused'), cancelled: t('Build stopped'), failed: t('Build needs attention'), staged: state.value?.active ? (state.value?.retrieval_backend === 'qdrant_generation' ? t('Active Qdrant canary') : t('Active SQL generation')) : state.value?.verified ? t('Verified; activation pending') : t('Staged; not verified') })[state.value?.status])
		const progress = computed(() => state.value?.total_documents > 0 ? Math.min(100, 100 * state.value.documents_done / state.value.total_documents) : 0)
		const failure = computed(() => {
			const code = state.value?.error_code
			if (code === 'owner_index_paused') return t('The owner paused ordinary indexing. Enable it again before resuming this build.')
			if (code === 'source_extraction_failed') return t('A source could not be extracted. Check source access, local conversion tools and transport timeouts, then resume.')
			if (['source_changed', 'source_unavailable'].includes(code)) return t('A source changed or is no longer accessible. Reconcile the owner’s ordinary index, then resume the build.')
			if (['source_too_large', 'source_chunk_limit'].includes(code)) return t('A source exceeds the build budget. Review its size and the generation’s chunk settings before retrying.')
			return t('The build stopped safely. Check the fixed embedding model and provider configuration, then resume.')
		})
		function accept(result) {
			if (!result || !states.includes(result.status) || !['sql', 'qdrant'].includes(result.backend) || typeof result.include_text_payload !== 'boolean' || typeof result.can_start !== 'boolean' || result.retrieval_changed !== false || typeof result.verified !== 'boolean' || typeof result.active !== 'boolean' || !['sql_legacy', 'sql_generation', 'qdrant_generation'].includes(result.retrieval_backend)
				|| !['documents_done', 'total_documents', 'staged_chunks', 'heartbeat_at'].every(key => Number.isSafeInteger(result[key]) && result[key] >= 0)
				|| !(result.error_code === null || typeof result.error_code === 'string')) throw new Error(t('Build progress could not be confirmed.'))
			state.value = result
		}
		async function request(action) {
			const current = ++epoch
			controller?.abort(); controller = new AbortController()
			error.value = ''; loading.value = true
			try {
				const result = await api(action ? 'POST' : 'GET', action ? `${path}/${action}` : path, action ? { confirm_document_transfer: confirmed.value, confirm_text_transfer: confirmedText.value } : {}, { signal: controller.signal, timeout: 10000, retries: 0 })
				if (current === epoch) {
					accept(result)
					if (action) { confirmed.value = false; confirmedText.value = false }
				}
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
		return { state, error, loading, busy, confirmed, confirmedText, label, progress, failure, load, control }
	},
}
</script>

<style scoped>
.generation-build { border-top: 1px solid var(--color-border); margin-top: 20px; padding-top: 16px; }
.generation-build h4 { margin: 0 0 12px; }
.generation-build p { margin: 12px 0; }
.generation-build-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
</style>
