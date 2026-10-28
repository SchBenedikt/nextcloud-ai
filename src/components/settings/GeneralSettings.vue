<template>
<div class="settings-group">
<section id="settings-connection" class="settings-section">
				<div class="section-heading">
					<div>
						<h3>{{ $t('Connection & models') }}</h3>
						<p>{{ $t('Tell EVA where Ollama is running and which models should answer questions.') }}</p>
					</div>
				</div>

<ProviderSettings :status="status" :locked="settingsLocked || !formReady" @saved="loadStatus(true)" />

				<details class="settings-details"><summary>{{ $t('Ollama connection & indexing models') }}</summary>
<div class="field-grid field-grid-wide">
					<div class="field field-wide">
						<NcTextField id="ollama-url" :label="$t('Ollama server URL')" v-model="f.ollama_url" type="url" :placeholder="$t('http://127.0.0.1:11434')" />
						<p class="field-help">{{ $t('The address of your Ollama HTTP API. The default works when Ollama runs on this same server.') }}</p>
					</div>
					<div class="field">
						<label class="native-label" for="embedding-model">{{ $t('Embedding model') }}</label>
						<NcSelect input-id="embedding-model" v-model="f.embedding_model" :options="embeddingModelOptions" :input-label="$t('Embedding model')" :label-outside="true" :disabled="modelLoading" />
						<p class="field-help">{{ $t('EVA discovers installed models automatically from the Ollama endpoint and separates embedding from chat models by their declared capabilities. Embedding models turn file text into searchable vectors.') }}</p>
						<div v-if="!modelLoading && embeddingInstalledHint" class="model-hint">{{ embeddingInstalledHint }}</div>
					</div>
					<div v-if="f.chat_provider === 'ollama'" class="field">
						<label class="native-label" for="chat-model">{{ $t('Chat model') }}</label>
						<NcSelect input-id="chat-model" v-model="f.chat_model" :options="chatModelOptions" :input-label="$t('Chat model')" :label-outside="true" :disabled="modelLoading" />
						<p class="field-help">{{ $t('EVA discovers installed chat models automatically from the Ollama endpoint.') }}</p>
						<div v-if="!modelLoading && chatInstalledHint" class="model-hint">{{ chatInstalledHint }}</div>
					</div>
					<div v-if="f.chat_provider === 'ollama'" class="field">
						<label class="native-label" for="chat-model-fallback">{{ $t('Chat model fallbacks') }}</label>
						<NcTextField id="chat-model-fallback" :label-outside="true" v-model="f.chat_model_fallback" :placeholder="$t('Optional, comma-separated')" />
						<p class="field-help">{{ $t('If the chat model above is not installed, EVA tries these models in order before failing. (E.g. llama3.1, qwen2.5)') }}</p>
					</div>
					<div class="field">
						<label class="native-label" for="embedding-model-fallback">{{ $t('Embedding model fallbacks') }}</label>
						<NcTextField id="embedding-model-fallback" :label-outside="true" v-model="f.embedding_model_fallback" :placeholder="$t('Optional, comma-separated')" />
						<p class="field-help">{{ $t('If the embedding model above is not installed, EVA tries these models in order before failing.') }}</p>
					</div>
					<div v-if="f.chat_provider === 'ollama'" class="field">
						<label class="native-label" for="summary-model">{{ $t('Heavy task model (optional)') }}</label>
						<NcSelect input-id="summary-model" v-model="f.summary_model" :options="summaryModelOptions" :input-label="$t('Heavy task model (optional)')" :label-outside="true" :disabled="modelLoading" />
						<p class="field-help">{{ $t('Optionally use a separate, usually larger model for summaries, translations and proofreading. Leave empty to reuse the chat model.') }}</p>
					</div>
				</div>
				<div class="inline-actions">
					<NcButton variant="secondary" :loading="checking" :disabled="busy" @click="checkOllama">{{ $t('Check connection') }}</NcButton>
					<span v-if="modelError" class="action-hint action-error">{{ modelError }}</span>
					<span v-else-if="modelLoading" class="action-hint">{{ $t('Discovering models from Ollama…') }}</span>
					<span v-else class="action-hint">{{ $t('Models are loaded automatically from the configured endpoint.') }}</span>
				</div>
				<div v-if="checkOut" class="check-panel" :class="'check-' + checkOut.type" role="status">
					<div v-for="line in checkOut.lines" :key="line.label" class="check-line">
						<span class="check-mark" aria-hidden="true">{{ line.ok ? '✓' : '!' }}</span>
						<div><strong>{{ line.label }}</strong><span>{{ line.detail }}</span></div>
					</div>
				</div>
</details>
			</section>
</div>
</template>

<script>
import { inject } from 'vue'
import { NcButton, NcSelect, NcTextField } from '@nextcloud/vue'
import ProviderSettings from './ProviderSettings.vue'

export default {
 name: 'GeneralSettings',
 components: { NcButton, NcSelect, NcTextField, ProviderSettings },
 setup() { return inject('eva-settings') },
}
</script>
