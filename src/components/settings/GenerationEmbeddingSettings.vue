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
					<div><dt>{{ $t('Embedding provider') }}</dt><dd>{{ selected.embedding_provider === 'ollama' ? 'Ollama' : $t('OpenAI-compatible provider') }}</dd></div>
					<div><dt>{{ $t('Embedding model') }}</dt><dd>{{ selected.embedding_model }}</dd></div>
					<div><dt>{{ $t('Vector dimension') }}</dt><dd>{{ selected.dimension }}</dd></div>
					<div><dt>{{ $t('Generation state') }}</dt><dd>{{ stateLabel(selected.status) }}</dd></div>
				</dl>
				<p class="generation-hint">{{ $t('This check sends one public test sentence to the selected embedding provider. It does not read files, build the index or change search.') }}</p>
				<NcButton :disabled="!selected || selected.status !== 'building' || checking" :loading="checking" @click="check">{{ $t('Check embedding model') }}</NcButton>
				<NcNoteCard v-if="notice" :type="notice.type">{{ notice.text }}</NcNoteCard>
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
import { NcButton, NcLoadingIcon, NcNoteCard, NcSelect, NcSettingsSection } from '@nextcloud/vue'
import { api, errMsg } from '../../lib/api'
import { translate as t } from '../../lib/i18n'

export default {
	name: 'GenerationEmbeddingSettings',
	components: { NcButton, NcLoadingIcon, NcNoteCard, NcSelect, NcSettingsSection },
	props: { users: { type: Array, default: () => [] } },
	setup(props) {
		const owner = ref(null), selected = ref(null), items = ref([]), loading = ref(false), checking = ref(false)
		const cursor = ref(''), next = ref(null), error = ref(''), notice = ref(null)
		let epoch = 0, controller
		const options = computed(() => items.value.map(row => ({ ...row, label: `${row.embedding_model} · ${row.dimension} · ${row.id.slice(0, 8)}` })))
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
					if (!/^[a-f0-9]{32}$/.test(row.id) || typeof row.embedding_model !== 'string' || !['ollama', 'openai_compatible'].includes(row.embedding_provider)
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
		watch(owner, () => { cursor.value = ''; load('') })
		watch(selected, () => {
			if (checking.value) { controller?.abort(); epoch++; checking.value = false }
			notice.value = null
		})
		onBeforeUnmount(() => { epoch++; controller?.abort() })
		return { owner, ownerOptions, selected, items, options, loading, checking, cursor, next, error, notice, load, check, stateLabel }
	},
}
</script>

<style scoped>
.generation-details { margin: 16px 0; }
.generation-details > div { display: flex; flex-wrap: wrap; gap: 4px 20px; padding: 6px 0; }
.generation-details dt { flex: 1 1 220px; font-weight: 600; }
.generation-details dd { flex: 1 1 220px; margin: 0; overflow-wrap: anywhere; }
.generation-hint { color: var(--color-text-maxcontrast); margin: 12px 0; }
.generation-pagination { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
</style>
