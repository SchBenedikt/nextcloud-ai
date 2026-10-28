<template>
	<div class="settings-group">
		<NcSettingsSection class="settings-section" :name="$t('Chat providers')" :description="$t('Choose where your questions and conversation context are sent.')">
			<ProviderSettings :status="status" :locked="settingsLocked || !formReady" @saved="loadStatus(true)" />
		</NcSettingsSection>
		<NcSettingsSection class="settings-section" :name="$t('Document search')" :description="$t('Ollama creates the embeddings used to search your documents, independently of the chat provider.')">
			<div class="field-grid">
				<NcTextField id="ollama-url" v-model="f.ollama_url" :label="$t('Ollama server URL')" type="url" placeholder="http://127.0.0.1:11434" />
				<div class="field"><NcSelect input-id="embedding-model" v-model="f.embedding_model" :options="embeddingModelOptions" :input-label="$t('Embedding model')" :label-outside="true" :disabled="modelLoading" /></div>
			</div>
			<p v-if="modelError" class="field-help field-error" role="alert">{{ modelError }}</p>
			<p v-else-if="modelLoading" class="field-help" role="status">{{ $t('Discovering models from Ollama…') }}</p>
			<p v-else-if="embeddingInstalledHint" class="field-help">{{ embeddingInstalledHint }}</p>
			<details class="settings-details"><summary>{{ $t('Advanced model options') }}</summary>
				<div class="field-grid">
					<NcTextField v-if="f.chat_provider === 'ollama'" v-model="f.chat_model_fallback" :label="$t('Chat model fallbacks')" :placeholder="$t('Optional, comma-separated')" />
					<NcTextField v-model="f.embedding_model_fallback" :label="$t('Embedding model fallbacks')" :placeholder="$t('Optional, comma-separated')" />
					<div v-if="f.chat_provider === 'ollama'" class="field"><NcSelect input-id="summary-model" v-model="f.summary_model" :options="summaryModelOptions" :input-label="$t('Heavy task model (optional)')" :label-outside="true" :disabled="modelLoading" /></div>
				</div>
			</details>
			<NcButton :loading="checking" :disabled="busy" @click="checkOllama">{{ $t('Check connection') }}</NcButton>
			<NcNoteCard v-if="checkOut" :type="checkOut.type === 'success' ? 'success' : 'error'"><p v-for="line in checkOut.lines" :key="line.label"><strong>{{ line.label }}</strong> · {{ line.detail }}</p></NcNoteCard>
		</NcSettingsSection>
	</div>
</template>

<script>
import { inject } from 'vue'
import { NcSettingsSection, NcButton, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import ProviderSettings from './ProviderSettings.vue'

export default {
	name: 'GeneralSettings',
	components: { NcSettingsSection, NcButton, NcNoteCard, NcSelect, NcTextField, ProviderSettings },
	setup() { return inject('eva-settings') },
}
</script>
