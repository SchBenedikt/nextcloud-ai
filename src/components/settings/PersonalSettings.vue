<template>
<div class="settings-group">
<NcSettingsSection class="settings-section" :name="$t('Chat history')" :description="$t('Manage the conversations stored for your Nextcloud account. This does not affect indexed files.')">

				<div class="field">
					<label class="native-label" for="chat-retention">{{ $t('Automatically delete old chats') }}</label>
					<NcSelect input-id="chat-retention" v-model="f.chat_retention_days" :options="chatRetentionOptions" label="label" :reduce="option => option.value" :input-label="$t('Automatically delete old chats')" :label-outside="true" />
					<p class="field-help">{{ $t('Chats that have not been used for this many days are deleted automatically by the background job. 0 keeps everything.') }}</p>
				</div>
				<div class="index-actions chat-history-actions">
					<div>
						<strong>{{ $t('Delete all chats') }}</strong>
						<p>{{ $t('Permanently removes your saved EVA conversations and messages.') }}</p>
					</div>
					<NcButton variant="tertiary-no-background" :disabled="settingsLocked" @click="chatsDeleteConfirm = true">{{ $t('Delete all chats') }}</NcButton>
				</div>
				<div v-if="chatsDeleteConfirm" class="confirm-panel" role="alertdialog" aria-modal="true" aria-labelledby="chats-delete-title">
					<strong id="chats-delete-title">{{ $t('Delete all chat history?') }}</strong>
					<p>{{ $t('This cannot be undone. Your indexed documents and files will remain untouched.') }}</p>
					<div class="button-group">
						<NcButton variant="tertiary-no-background" @click="chatsDeleteConfirm = false">{{ $t('Cancel') }}</NcButton>
						<NcButton variant="primary" class="danger-button" :loading="deletingChats" @click="deleteAllChats">{{ $t('Delete all chats') }}</NcButton>
					</div>
				</div>
			</NcSettingsSection>
<NcSettingsSection class="settings-section" :name="$t('Personal knowledge')" :description="$t('Edit the facts EVA remembers about you. This file is read before every answer to personalise responses.')">

				<p v-if="knowledgeLoading" class="field-help" role="status">{{ $t('Loading personal knowledge…') }}</p>
				<div v-else-if="knowledgeLoadError" class="load-error" role="alert"><span>{{ $t('Personal knowledge could not be loaded. Check the file in Nextcloud Files and try again.') }}</span><NcButton variant="tertiary" @click="loadKnowledge">{{ $t('Try again') }}</NcButton></div>
				<div v-if="knowledgeReady" class="field field-wide">
					<NcTextArea
						v-model="knowledgeContent"
						id="knowledge-editor"
						:label="$t('Personal knowledge')"
						:label-outside="true"
						:resize="'vertical'"
						class="knowledge-editor"
						:placeholder="$t('No knowledge file yet. EVA will create one with your profile on first use.')"
						:disabled="settingsLocked || !knowledgeReady"
					/>
					<p class="field-help">
						{{ $t('{count} of {max} characters', { count: formatNumber(knowledgeContent.length), max: '60,000' }) }}
					</p>
				</div>
				<div v-if="knowledgeReady" class="inline-actions">
					<NcButton variant="primary" :loading="savingKnowledge" :disabled="settingsLocked || !knowledgeReady || knowledgeContent === knowledgeOriginal" @click="saveKnowledgeContent">
						{{ $t('Save knowledge') }}
					</NcButton>
					<span v-if="knowledgeSaved" class="action-hint" style="color: var(--color-success);">{{ $t('Saved') }}</span>
				</div>
			</NcSettingsSection>
<NcSettingsSection id="settings-privacy" class="settings-section" :name="$t('Privacy & data')" :description="$t('Review where EVA stores information and which services receive it.')">

				<div class="privacy-flow">
					<strong>{{ $t('How your data is processed') }}</strong>
					<p>{{ $t('Your file index is stored on the Nextcloud server. During indexing, extracted text is sent to the configured Ollama endpoint to create embeddings. During chat, your question, selected excerpts and relevant personal knowledge are sent to {provider}.', { provider: selectedChatProvider }) }}</p>
					<p>{{ $t('When EVA uses tools, web search or external connectors, the data needed for that request may also be sent to the connected service.') }}</p>
					<p>{{ $t('Check the hosting location and privacy terms of your configured endpoints and connected services.') }}</p>
				</div>
				<div class="index-actions">
					<div>
						<strong>{{ $t('Download my data') }}</strong>
						<p>{{ $t('Your chats, personal knowledge and a metadata list of indexed documents as one JSON file (GDPR export).') }}</p>
					</div>
					<NcButton variant="secondary" :disabled="exporting" :loading="exporting" @click="downloadExport">{{ $t('Download') }}</NcButton>
				</div>
			</NcSettingsSection>
</div>
</template>

<script>
import { inject } from 'vue'
import { NcSettingsSection, NcButton, NcSelect, NcTextArea } from '@nextcloud/vue'

export default {
 name: 'PersonalSettings',
 components: { NcSettingsSection, NcButton, NcSelect, NcTextArea },
 setup() { return inject('eva-settings') },
}
</script>
