<template>
	<div class="provider-settings">
		<p class="field-help">{{ t('Choose where chat requests are sent. Each provider keeps its own encrypted API key. File indexing uses Ollama separately.') }}</p>
		<ul class="provider-list">
			<li v-for="provider in providers" :key="provider.id" class="provider-row">
				<div class="provider-description"><strong>{{ provider.name }}</strong><small>{{ provider.model || t('Configure a model below') }}</small></div>
				<span v-if="active === provider.id" class="provider-active">{{ t('Active') }}</span>
				<NcButton variant="tertiary" :disabled="locked || saving" :aria-label="t('Configure {provider}', { provider: provider.name })" @click="edit(provider)">{{ t('Configure') }}</NcButton>
			</li>
		</ul>
		<NcButton variant="secondary" :disabled="locked || profiles.length >= 20" @click="add">{{ t('Add AI provider') }}</NcButton>
		<p v-if="notice" class="field-help" role="status">{{ notice }}</p>
		<NcModal v-if="draft" size="normal" :label-id="titleId" @close="close">
			<form class="provider-editor" @submit.prevent="save">
				<h2 :id="titleId">{{ editing ? t('Configure provider') : t('Add AI provider') }}</h2>
				<label v-if="!editing" for="provider-template">{{ t('Provider template') }}</label>
				<NcSelect v-if="!editing" input-id="provider-template" v-model="presetId" :options="presets" label="name" :reduce="item => item.id" :input-label="t('Provider template')" :label-outside="true" :clearable="false" @update:model-value="applyPreset" />
				<NcTextField v-if="!builtin" v-model="draft.name" :label="t('Display name')" :disabled="saving" required maxlength="120" />
				<NcTextField v-if="!builtin" v-model="draft.url" type="url" :label="t('API base URL')" :disabled="saving" required placeholder="https://api.example.com/v1" />
				<NcTextField v-model="draft.model" :label="t('Chat model')" :disabled="saving" required maxlength="128" :placeholder="t('Exact model ID from your provider')" />
				<NcCheckboxRadioSwitch v-if="!builtin" v-model="needsKey" type="switch" :disabled="saving">{{ t('Authenticate with an API key') }}</NcCheckboxRadioSwitch>
				<NcTextField v-if="needsKey" v-model="key" type="password" autocomplete="new-password" :label="t('API key')" :disabled="saving" maxlength="512" />
				<p v-if="needsKey" class="field-help">{{ keySaved ? t('A key is saved. Leave blank to keep it.') : t('Enter an API key. It is encrypted on the server and never returned to the browser.') }}</p>
				<p v-if="!builtin" class="field-help">{{ t('Uses the Chat Completions API. Model features vary by provider. The server must be able to reach this address.') }}</p>
				<details v-if="!builtin" class="provider-extra"><summary>{{ t('Image & audio models (optional)') }}</summary>
					<p class="field-help">{{ t('Only configure these when your provider supports the matching image or audio endpoint.') }}</p>
					<NcTextField v-for="field in mediaFields" :key="field.key" v-model="draft[field.key]" :label="field.label" :disabled="saving" maxlength="128" />
				</details>
				<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
				<div class="provider-editor__actions">
					<NcButton type="button" variant="tertiary" :disabled="saving" @click="close">{{ t('Cancel') }}</NcButton>
					<NcButton type="submit" variant="primary" :loading="saving" :disabled="saving || locked">{{ t('Save & use provider') }}</NcButton>
				</div>
			</form>
		</NcModal>
	</div>
</template>

<script>
import { computed, ref, useId } from 'vue'
import { NcButton, NcCheckboxRadioSwitch, NcModal, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { api, errMsg } from '../../lib/api'
import { translate as t } from '../../lib/i18n'

// Templates supply endpoints, never model IDs or prices that age silently.
const presets = [
	{ id: 'openai', name: 'OpenAI', url: 'https://api.openai.com/v1' },
	{ id: 'gemini', name: 'Google Gemini', url: 'https://generativelanguage.googleapis.com/v1beta/openai' },
	{ id: 'mistral', name: 'Mistral', url: 'https://api.mistral.ai/v1' },
	{ id: 'deepseek', name: 'DeepSeek', url: 'https://api.deepseek.com' },
	{ id: 'openrouter', name: 'OpenRouter', url: 'https://openrouter.ai/api/v1' },
	{ id: 'together', name: 'Together AI', url: 'https://api.together.ai/v1' },
	{ id: 'xai', name: 'xAI', url: 'https://api.x.ai/v1' },
	{ id: 'lmstudio', name: 'LM Studio', url: 'http://127.0.0.1:1234/v1', auth: 'none' },
	{ id: 'vllm', name: 'vLLM', url: 'http://127.0.0.1:8000/v1', auth: 'none' },
	{ id: 'custom', name: 'Custom OpenAI-compatible server', url: '' },
]

export default {
	name: 'ProviderSettings',
	components: { NcButton, NcCheckboxRadioSwitch, NcModal, NcNoteCard, NcSelect, NcTextField },
	props: { status: { type: Object, default: null }, locked: { type: Boolean, default: false } },
	emits: ['saved'],
	setup(props, { emit }) {
		const titleId = 'eva-provider-' + useId()
		const profiles = computed(() => {
			try { const value = JSON.parse(props.status?.settings?.provider_profiles || '[]'); return Array.isArray(value) ? value : [] } catch { return [] }
		})
		const active = computed(() => props.status?.settings?.chat_provider || 'ollama')
		const providers = computed(() => {
			const settings = props.status?.settings || {}
			const rows = [
				{ id: 'ollama', name: 'Ollama', model: settings.chat_model },
				{ id: 'groq', name: 'Groq', model: settings.groq_model },
				...profiles.value,
			]
			// Older installs may have a single custom provider outside the profile list.
			if (!rows.some(item => item.id === active.value)) rows.push({ id: active.value, name: active.value, url: settings.custom_provider_url, model: settings.custom_provider_model })
			return rows
		})
		const draft = ref(null)
		const editing = ref(false)
		const presetId = ref('openai')
		const key = ref('')
		const needsKey = ref(true)
		const saving = ref(false)
		const error = ref('')
		const notice = ref('')
		const builtin = computed(() => ['ollama', 'groq'].includes(draft.value?.id))
		const keySaved = computed(() => draft.value?.id === 'groq' ? props.status?.groq?.keyConfigured : props.status?.providerKeys?.[draft.value?.id] || (props.status?.customProvider?.providerId === draft.value?.id && props.status?.customProvider?.keyConfigured))
		const mediaFields = [
			{ key: 'image_model', label: t('Image model') }, { key: 'audio_model', label: t('Transcription model') },
			{ key: 'tts_model', label: t('Speech model') }, { key: 'tts_voice', label: t('Speech voice') },
		]
		const edit = provider => {
			draft.value = { ...provider }
			editing.value = true
			key.value = ''; error.value = ''; notice.value = ''
			needsKey.value = provider.id !== 'ollama' && provider.auth !== 'none'
		}
		const applyPreset = id => {
			const preset = presets.find(item => item.id === id) || presets[0]
			let candidate = preset.id
			for (let number = 2; providers.value.some(item => item.id === candidate); number++) candidate = preset.id + '-' + number
			draft.value = { id: candidate, name: preset.name, url: preset.url, model: '', auth: preset.auth || 'api_key' }
			needsKey.value = preset.auth !== 'none'
			key.value = ''; error.value = ''
		}
		const add = () => { editing.value = false; presetId.value = 'openai'; applyPreset('openai') }
		const close = () => { if (!saving.value) { draft.value = null; key.value = '' } }
		const save = async () => {
			if (saving.value || props.locked || !draft.value) return
			const row = { ...draft.value, model: draft.value.model.trim() }
			if (needsKey.value && !key.value && !keySaved.value) { error.value = t('Enter an API key for this provider.'); return }
			saving.value = true; error.value = ''; notice.value = ''
			try {
				const payload = { chat_provider: row.id }
				if (row.id === 'ollama') payload.chat_model = row.model
				else if (row.id === 'groq') { payload.groq_model = row.model; if (key.value) payload.groq_api_key = key.value }
				else {
					row.name = row.name.trim(); row.url = row.url.trim(); row.auth = needsKey.value ? 'api_key' : 'none'
					const rows = profiles.value.filter(item => item.id !== row.id)
					payload.provider_profiles = JSON.stringify([...rows, row])
					if (key.value && needsKey.value) payload.custom_provider_api_key = key.value
				}
				await api('PUT', 'settings', payload)
				key.value = ''; draft.value = null
				notice.value = t('Provider saved and selected.')
				emit('saved')
			} catch (failure) { error.value = errMsg(failure) }
			finally { saving.value = false }
		}
		return { titleId, t, profiles, providers, active, presets, presetId, draft, editing, builtin, needsKey, key, keySaved, saving, error, notice, mediaFields, add, edit, applyPreset, close, save }
	},
}
</script>

<style scoped>
.provider-list { list-style: none; padding: 0; margin: 16px 0; }
.provider-row { display: flex; align-items: center; gap: 12px; min-height: 64px; border-bottom: 1px solid var(--color-border); }
.provider-description { flex: 1; min-width: 0; }
.provider-description strong, .provider-description small { display: block; overflow-wrap: anywhere; }
.provider-description small { color: var(--color-text-maxcontrast); margin-top: 4px; }
.provider-active { color: var(--color-primary-element); font-size: 12px; }
.provider-editor { display: grid; gap: 16px; padding: 24px; }
.provider-editor h2 { font-size: 22px; font-weight: 600; }
.provider-editor__actions { display: flex; justify-content: flex-end; gap: 8px; }
.provider-extra { display: grid; gap: 12px; }
.provider-extra summary { cursor: pointer; padding: 8px 0; }
</style>
