<template>
	<div class="generation-shadow">
		<h4>{{ $t('Shadow comparison') }}</h4>
		<p>{{ $t('Run an administrator-started, read-only comparison against this verified generation. It does not answer chats or change search. The sample is sent to the generation’s pinned embedding provider and vector store; only aggregate metrics are logged.') }}</p>
		<NcTextArea v-model="query" :label="$t('Sample query')" :placeholder="$t('Enter a short sample query')" :rows="2" :disabled="busy" />
		<NcTextField v-model="scope" :label="$t('Files folder scope (optional)')" :placeholder="$t('Documents/Projects')" :disabled="busy" />
		<div class="shadow-actions">
			<NcButton :disabled="busy || !query.trim()" :loading="busy" @click="compare">{{ $t('Compare top results') }}</NcButton>
		</div>
		<NcNoteCard v-if="error" type="warning">{{ error }}</NcNoteCard>
		<NcNoteCard v-if="result" type="success">
			{{ $t('Top {count}: {overlap} results overlap ({recall}% recall, {precision}% precision). First result matches: {first}.', { count: result.sql_candidates, overlap: result.overlap_at_k, recall: percent(result.recall_at_k), precision: percent(result.precision_at_k), first: result.top_result_matches ? $t('yes') : $t('no') }) }}
			<br>{{ $t('SQL scanned {rows} rows in {sql} ms; vector query took {vector} ms; embedding took {embedding} ms.', { rows: result.sql_scanned, sql: result.sql_ms, vector: result.vector_ms, embedding: result.embedding_ms }) }}
		</NcNoteCard>
	</div>
</template>

<script>
import { ref } from 'vue'
import { NcButton, NcNoteCard, NcTextArea, NcTextField } from '@nextcloud/vue'
import { api, errMsg } from '../../lib/api'
import { translate as t } from '../../lib/i18n'

export default {
	name: 'GenerationShadowSettings',
	components: { NcButton, NcNoteCard, NcTextArea, NcTextField },
	props: { user: { type: String, required: true }, generation: { type: String, required: true } },
	setup(props) {
		const query = ref(''), scope = ref(''), result = ref(null), error = ref(''), busy = ref(false)
		const path = `admin/vector-generations/${encodeURIComponent(props.user)}/${props.generation}/shadow`
		async function compare() {
			if (busy.value || !query.value.trim()) return
			busy.value = true; error.value = ''; result.value = null
			try {
				const data = await api('POST', path, { query: query.value, top_k: 10, scope_path: scope.value.trim() || null }, { timeout: 15000, retries: 0 })
				if (!data || data.retrieval_changed !== false || !['sql_candidates', 'vector_candidates', 'overlap_at_k', 'sql_scanned', 'sql_ms', 'embedding_ms', 'vector_ms'].every(key => Number.isSafeInteger(data[key]) && data[key] >= 0)
					|| typeof data.top_result_matches !== 'boolean' || !(data.recall_at_k === null || typeof data.recall_at_k === 'number') || !(data.precision_at_k === null || typeof data.precision_at_k === 'number')) throw new Error(t('The comparison result could not be confirmed.'))
				result.value = data
			} catch (e) { error.value = errMsg(e) }
			finally { busy.value = false }
		}
		const percent = value => value === null ? '—' : Math.round(value * 100)
		return { query, scope, result, error, busy, compare, percent }
	},
}
</script>

<style scoped>
.generation-shadow { border-top: 1px solid var(--color-border); margin-top: 20px; padding-top: 16px; }
.generation-shadow h4 { margin: 0 0 8px; }
.generation-shadow p { margin: 8px 0 12px; }
.generation-shadow :deep(textarea), .generation-shadow :deep(input) { max-width: 680px; }
.shadow-actions { display: flex; gap: 8px; margin: 12px 0; }
</style>
