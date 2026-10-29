<template>
	<div class="generation-verification" aria-live="polite">
		<h4>{{ $t('Verify staged generation') }}</h4>
		<NcLoadingIcon v-if="loading && !state" :name="$t('Loading verification progress')" />
		<NcNoteCard v-if="error" type="warning">{{ error }}</NcNoteCard>
		<template v-if="state">
			<p>{{ label }}</p>
			<NcProgressBar v-if="state.status !== 'not_started'" :value="progress" :aria-label="$t('Points verified')" />
			<p v-if="state.status !== 'not_started'">{{ $t('Points verified: {done} of {total}', { done: state.checked_count, total: state.expected_count }) }} · {{ $t('Vectors sampled: {count}', { count: state.sample_count }) }}</p>
			<NcNoteCard v-if="state.verified" type="success">{{ $t('SQL and remote point identities, revisions, source manifests, dimensions and sampled vectors match. Search still uses SQL.') }}</NcNoteCard>
			<GenerationShadowSettings v-if="state.verified" :key="`${user}:${generation}:shadow`" :user="user" :generation="generation" />
			<NcNoteCard v-else type="info">{{ $t('This read-only background check compares SQL chunks with the configured vector store. It never changes retrieval or sends source text to an embedding provider.') }}</NcNoteCard>
			<NcNoteCard v-if="state.error_code" type="warning">{{ failure }}</NcNoteCard>
			<div class="verification-actions">
				<NcButton v-if="['not_started', 'failed', 'stale', 'cancelled'].includes(state.status)" :disabled="!state.can_start || busy" :loading="busy" @click="control(state.status === 'not_started' ? 'start' : 'retry')">{{ state.status === 'not_started' ? $t('Start verification') : $t('Retry verification') }}</NcButton>
				<NcButton v-else-if="['queued', 'running'].includes(state.status)" :disabled="busy" :loading="busy" @click="control('pause')">{{ $t('Pause verification') }}</NcButton>
				<NcButton v-else-if="state.status === 'paused'" :disabled="!state.can_start || busy" :loading="busy" @click="control('resume')">{{ $t('Resume verification') }}</NcButton>
				<NcButton :disabled="busy || loading" variant="tertiary" @click="load">{{ $t('Refresh verification progress') }}</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { NcButton, NcLoadingIcon, NcNoteCard, NcProgressBar } from '@nextcloud/vue'
import { api, errMsg } from '../../lib/api'
import { translate as t } from '../../lib/i18n'
import GenerationShadowSettings from './GenerationShadowSettings.vue'

export default {
	name: 'GenerationVerificationSettings',
	components: { NcButton, NcLoadingIcon, NcNoteCard, NcProgressBar, GenerationShadowSettings },
	props: { user: { type: String, required: true }, generation: { type: String, required: true } },
	setup(props) {
		const state = ref(null), error = ref(''), loading = ref(false), busy = ref(false)
		let controller, epoch = 0, timer
		const path = `admin/vector-generations/${encodeURIComponent(props.user)}/${props.generation}/verification`
		const states = ['not_started', 'queued', 'running', 'paused', 'cancelled', 'failed', 'stale', 'verified']
		const label = computed(() => ({ not_started: t('Not started'), queued: t('Waiting for the next verification tick'), running: t('Comparing stored points'), paused: t('Verification paused'), cancelled: t('Verification stopped'), failed: t('Verification needs attention'), stale: t('Verification invalidated by source changes'), verified: t('Verified; search unchanged') })[state.value?.status])
		const progress = computed(() => state.value?.expected_count > 0 ? Math.min(100, 100 * state.value.checked_count / state.value.expected_count) : 0)
		const failure = computed(() => {
			const code = state.value?.error_code
			if (code === 'remote_point_missing') return t('Some SQL points are not present in the vector store. Allow the point-delivery queue to finish, then retry.')
			if (code === 'remote_payload_mismatch' || code === 'remote_vector_mismatch') return t('The stored point payload or sampled vector differs from SQL. The generation remains unavailable for search.')
			if (code === 'remote_count_mismatch') return t('The exact live-point count differs from SQL. Resolve pending or stale points, then retry.')
			if (code === 'new_sources_after_snapshot' || code === 'source_reconciliation_required' || code === 'sql_inventory_changed') return t('The indexed sources changed during the build. Reconcile the source index and prepare a fresh generation.')
			return t('Verification stopped safely. Check the storage connection and owner index, then retry.')
		})
		function accept(result) {
			if (!result || !states.includes(result.status) || typeof result.can_start !== 'boolean' || result.retrieval_changed !== false || typeof result.verified !== 'boolean'
				|| !['expected_count', 'checked_count', 'sample_count', 'heartbeat_at'].every(key => Number.isSafeInteger(result[key]) && result[key] >= 0)
				|| !(result.error_code === null || typeof result.error_code === 'string')) throw new Error(t('Verification progress could not be confirmed.'))
			state.value = result
		}
		async function request(action) {
			const current = ++epoch
			controller?.abort(); controller = new AbortController()
			error.value = ''; loading.value = true
			try {
				const result = await api(action ? 'POST' : 'GET', action ? `${path}/${action}` : path, {}, { signal: controller.signal, timeout: 10000, retries: 0 })
				if (current === epoch) accept(result)
			} catch (e) { if (current === epoch && !controller.signal.aborted) error.value = errMsg(e) }
			finally { if (current === epoch) loading.value = false }
		}
		async function load() { if (!busy.value && !loading.value) await request() }
		async function control(action) { if (busy.value || loading.value) return; busy.value = true; try { await request(action) } finally { busy.value = false } }
		onMounted(() => { load(); timer = setInterval(() => { if (document.visibilityState === 'visible' && ['queued', 'running'].includes(state.value?.status)) load() }, 5000) })
		onBeforeUnmount(() => { epoch++; controller?.abort(); clearInterval(timer) })
		return { state, error, loading, busy, label, progress, failure, load, control }
	},
}
</script>

<style scoped>
.generation-verification { border-top: 1px solid var(--color-border); margin-top: 20px; padding-top: 16px; }
.generation-verification h4 { margin: 0 0 12px; }
.generation-verification p { margin: 12px 0; }
.verification-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
</style>
