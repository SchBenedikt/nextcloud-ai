<template>
	<NcSettingsSection :name="$t('Embedding generations')"
		:description="$t('Inspect the fixed model and vector space of a prepared generation. Search continues to use SQL.')">
		<NcSelect v-model="owner" :options="ownerOptions" label="label" track-by="userId"
			input-id="generation-owner" :input-label="$t('Generation owner')" />
		<NcLoadingIcon v-if="loading" :name="$t('Loading generations')" />
		<template v-else-if="owner">
			<NcNoteCard v-if="error" type="warning">{{ error }}</NcNoteCard>
			<p v-else-if="!items.length">{{ $t('No generations have been prepared for this account.') }}</p>
			<template v-else>
				<NcSelect v-model="selected" :options="options" label="label" track-by="id"
					input-id="generation-selection" :input-label="$t('Prepared generation')" />
				<dl v-if="selected" class="generation-details">
					<div><dt>{{ $t('Search storage') }}</dt><dd>{{ selected.backend === 'qdrant' ? $t('Optional Qdrant') : $t('Built-in Nextcloud SQL') }}</dd></div>
					<div><dt>{{ $t('Embedding provider') }}</dt><dd>{{ selected.embedding_provider === 'ollama' ? 'Ollama' : $t('OpenAI-compatible provider') }}</dd></div>
					<div><dt>{{ $t('Embedding model') }}</dt><dd>{{ selected.embedding_model }}</dd></div>
					<div><dt>{{ $t('Vector dimension') }}</dt><dd>{{ selected.dimension }}</dd></div>
					<div><dt>{{ $t('Generation state') }}</dt><dd>{{ stateLabel(selected.status) }}</dd></div>
				</dl>
				<div v-if="selected?.backend === 'sql' && selected.status === 'active'" class="qdrant-setup">
					<h4>{{ $t('Optional Qdrant search') }}</h4>
					<p>{{ $t('SQL remains the default and rollback path. Configure and enable the optional Qdrant connection first; a new generation starts empty and does not change search.') }}</p>
					<NcCheckboxRadioSwitch v-model:checked="includeTextPayload" :disabled="creatingQdrant">
						{{ $t('Enable Qdrant full-text search (stores document text remotely)') }}
					</NcCheckboxRadioSwitch>
					<NcNoteCard v-if="includeTextPayload" type="warning">
						{{ $t('Document text is transferred only after a separate confirmation when the background build starts. Qdrant will retain searchable text until chunks are deleted or the generation is erased.') }}
					</NcNoteCard>
					<NcNoteCard v-if="qdrantError" type="error">{{ qdrantError }}</NcNoteCard>
					<NcCheckboxRadioSwitch v-if="includeTextPayload" v-model:checked="confirmTextStorage" :disabled="creatingQdrant">
						{{ $t('I explicitly allow this generation to store document text in Qdrant') }}
					</NcCheckboxRadioSwitch>
					<NcButton :disabled="creatingQdrant || (includeTextPayload && !confirmTextStorage)" :loading="creatingQdrant" @click="createQdrant">
						{{ $t('Create Qdrant generation') }}
					</NcButton>
				</div>
				<p class="generation-hint">{{ $t('This check sends one public test sentence to the selected embedding provider. It does not read files, build the index or change search.') }}</p>
				<NcButton :disabled="!selected || selected.status !== 'building' || checking" :loading="checking" @click="check">{{ $t('Check embedding model') }}</NcButton>
				<NcNoteCard v-if="notice" :type="notice.type">{{ notice.text }}</NcNoteCard>
				<GenerationBuildSettings v-if="selected" :key="`${owner.userId}:${selected.id}`" :user="owner.userId" :generation="selected.id" />
			</template>
			<div class="generation-pagination">
				<NcButton v-if="cursor" variant="tertiary" @click="load('')">{{ $t('First generations') }}</NcButton>
				<NcButton v-if="next" variant="tertiary" @click="load(next)">{{ $t('Next generations') }}</NcButton>
				<NcButton variant="tertiary" @click="load(cursor)">{{ $t('Refresh generations') }}</NcButton>
			</div>
		</template>
	</NcSettingsSection>
</template>

<script>
import { computed, ref, watch, onBeforeUnmount } from 'vue'
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, NcSelect, NcSettingsSection } from '@nextcloud/vue'
import { api, errMsg } from '../../lib/api'
import { translate as t } from '../../lib/i18n'
import GenerationBuildSettings from './GenerationBuildSettings.vue'

export default {
	name: 'GenerationEmbeddingSettings',
	components: { NcButton, NcLoadingIcon, NcNoteCard, NcSelect, NcSettingsSection, GenerationBuildSettings },
	props: { users: { type: Array, default: () => [] } },
	setup(props) {
		const owner = ref(null), selected = ref(null), items = ref([]), loading = ref(false), checking = ref(false)
		const includeTextPayload = ref(false), confirmTextStorage = ref(false), creatingQdrant = ref(false), qdrantError = ref('')
		const cursor = ref(''), next = ref(null), error = ref(''), notice = ref(null)
		let epoch = 0, controller
		const options = computed(() => items.value.map(row => ({ ...row, label: `${row.backend === 'qdrant' ? 'Qdrant' : 'SQL'} · ${row.embedding_model} · ${row.id.slice(0, 8)}` })))
		const userLabel = user => user.displayName && user.displayName !== user.userId ? `${user.displayName} (${user.userId})` : user.userId
		const ownerOptions = computed(() => props.users.map(user => ({ ...user, label: userLabel(user) })))
		const stateLabel = state => ({ building: t('Not verified'), active: t('Active'), retired: t('Retired'), failed: t('Failed') })[state] || t('Unknown')
		async function load(after = '') {
			const current = ++epoch
			controller?.abort(); controller = new AbortController()
			loading.value = !!owner.value; checking.value = false; selected.value = null; notice.value = null; error.value = ''; items.value = []; next.value = null
			if (!owner.value) return
			const user = owner.value.userId
			try {
				const result = await api('GET', `admin/vector-generations/${encodeURIComponent(user)}`, { after }, { signal: controller.signal, timeout: 10000, retries: 0 })
				if (current !== epoch) return
				if (!Array.isArray(result?.items) || result.items.length > 50 || (result.next_after !== null && !/^[a-f0-9]{32}$/.test(result.next_after))) throw new Error(t('Generation metadata could not be loaded.'))
				for (const row of result.items) {
					if (!/^[a-f0-9]{32}$/.test(row.id) || !['sql', 'qdrant'].includes(row.backend) || typeof row.include_text_payload !== 'boolean'
						|| typeof row.embedding_model !== 'string' || !['ollama', 'openai_compatible'].includes(row.embedding_provider)
						|| !Number.isSafeInteger(row.dimension) || row.dimension < 1 || row.dimension > 16384 || !['building', 'active', 'retired', 'failed'].includes(row.status)) throw new Error(t('Generation metadata could not be loaded.'))
				}
				items.value = result.items; next.value = result.next_after; cursor.value = after
			} catch (e) { if (current === epoch && !controller.signal.aborted) error.value = errMsg(e) }
			finally { if (current === epoch) loading.value = false }
		}
		async function check() {
			if (checking.value || loading.value || !selected.value || !owner.value) return
			const current = ++epoch
			controller?.abort(); controller = new AbortController()
			const user = owner.value.userId, id = selected.value.id, dimension = selected.value.dimension
			checking.value = true; notice.value = null
			try {
				const result = await api('POST', `admin/vector-generations/${encodeURIComponent(user)}/${id}/check`, {}, { signal: controller.signal, timeout: 35000, retries: 0 })
				if (current !== epoch) return
				if (result?.available !== true || result.generation_id !== id || result.dimension !== dimension || result.retrieval_changed !== false || result.document_content_sent !== false) throw new Error(t('The embedding check could not be confirmed.'))
				notice.value = { type: 'success', text: t('The provider returned the expected model and vector dimension. Search is unchanged.') }
			} catch (e) { if (current === epoch && !controller.signal.aborted) notice.value = { type: 'error', text: errMsg(e) } }
			finally { if (current === epoch) checking.value = false }
		}
		async function createQdrant() {
			if (creatingQdrant.value || !owner.value || !selected.value || selected.value.backend !== 'sql'
				|| selected.value.status !== 'active' || (includeTextPayload.value && !confirmTextStorage.value)) return
			creatingQdrant.value = true; qdrantError.value = ''
			try {
				const result = await api('POST', `admin/vector-generations/${encodeURIComponent(owner.value.userId)}/qdrant`, {
					source_generation_id: selected.value.id,
					include_text_payload: includeTextPayload.value,
					confirm_text_storage: confirmTextStorage.value,
				}, { retries: 0, timeout: 15000 })
				const generation = result?.generation
				if (!generation || !/^[a-f0-9]{32}$/.test(generation.id) || generation.backend !== 'qdrant'
					|| generation.status !== 'building' || generation.include_text_payload !== includeTextPayload.value
					|| result.prepared !== false || result.retrieval_changed !== false) throw new Error(t('The Qdrant generation could not be confirmed.'))
				items.value = [generation, ...items.value.filter(row => row.id !== generation.id)].slice(0, 50)
				selected.value = generation
				includeTextPayload.value = false; confirmTextStorage.value = false
			} catch (e) { qdrantError.value = errMsg(e) }
			finally { creatingQdrant.value = false }
		}
		watch(owner, () => { cursor.value = ''; load('') })
		watch(selected, () => {
			if (checking.value) { controller?.abort(); epoch++; checking.value = false }
			notice.value = null
			qdrantError.value = ''; includeTextPayload.value = false; confirmTextStorage.value = false
		})
		onBeforeUnmount(() => { epoch++; controller?.abort() })
		return { owner, ownerOptions, selected, items, options, loading, checking, cursor, next, error, notice,
			includeTextPayload, confirmTextStorage, creatingQdrant, qdrantError, load, check, createQdrant, stateLabel }
	},
}
</script>

<style scoped>
.generation-details { margin: 16px 0; }
.generation-details > div { display: flex; flex-wrap: wrap; gap: 4px 20px; padding: 6px 0; }
.generation-details dt { flex: 1 1 220px; font-weight: 600; }
.generation-details dd { flex: 1 1 220px; margin: 0; overflow-wrap: anywhere; }
.generation-hint { color: var(--color-text-maxcontrast); margin: 12px 0; }
.qdrant-setup { display: grid; gap: 10px; margin: 16px 0; padding: 16px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); }
.qdrant-setup h4, .qdrant-setup p { margin: 0; }
.generation-pagination { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
</style>
