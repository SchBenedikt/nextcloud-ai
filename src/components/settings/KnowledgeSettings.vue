<template>
<div class="settings-group">
<section id="settings-search" class="settings-section">
				<div class="section-heading">
					<div>
						<h3>{{ $t('Search & answer quality') }}</h3>
						<p>{{ $t('These settings control how much indexed context EVA retrieves and how creative its answers are.') }}</p>
					</div>
				</div>
				<div class="field-grid field-grid-three">
					<div class="field">
						<NcTextField id="top-k" :label="$t('Sources per answer')" v-model="f.top_k" type="number" />
						<p class="field-help">{{ $t('Maximum number of relevant text snippets sent to the chat model. Default: 6.') }}</p>
					</div>
					<div class="field">
						<NcTextField id="context-size" :label="$t('Model context size')" v-model="f.context_size" type="number" />
						<p class="field-help">{{ $t('Token window passed to Ollama. Match the model’s supported context size. Default: 12,288.') }}</p>
					</div>
					<div class="field">
						<NcTextField id="temperature" :label="$t('Answer creativity')" v-model="f.temperature" type="number" />
						<p class="field-help">{{ $t('0 is deterministic and factual; higher values are more varied. Default: 0.1.') }}</p>
					</div>
				</div>
			</section>
<section id="settings-indexing" class="settings-section">
				<div class="section-heading">
					<div>
						<h3>{{ $t('Indexing & scope') }}</h3>
						<p>{{ $t('Define which files become part of EVA’s private knowledge base and how they are split for search.') }}</p>
					</div>
				</div>
				<div class="field-grid field-grid-three">
					<div class="field">
						<NcTextField id="scope-path" :label="$t('Folder to index')" v-model="f.scope_path" :placeholder="$t('Everything in Files')" />
						<p class="field-help">{{ $t('Relative to your Files root. Empty indexes all files; for example use {folder} for one folder.', { folder: 'Documents/Notes' }) }}</p>
					</div>
					<div class="field">
						<NcTextField id="max-file-size" :label="$t('Maximum file size (MB)')" v-model="maxFileSizeMb" type="number" />
						<p class="field-help">{{ $t('Larger files are skipped during indexing. Default: 20 MB.') }}</p>
					</div>
					<div class="field">
						<NcTextField id="max-files" :label="$t('Files per indexing run')" v-model="f.max_files_per_run" type="number" />
						<p class="field-help">{{ $t('Limits work per run so large accounts remain responsive. Default: 40.') }}</p>
					</div>
					<div class="field">
						<NcTextField id="embed-batch" :label="$t('Embeddings per batch')" v-model="f.embed_batch_size" type="number" />
						<p class="field-help">{{ $t('Text chunks are embedded in batches to keep memory bounded. Default: 24.') }}</p>
					</div>
				</div>
				<div class="field-grid field-grid-three">
					<div class="field">
						<NcTextField id="chunk-size" :label="$t('Chunk size (characters)')" v-model="f.chunk_size" type="number" />
						<p class="field-help">{{ $t('Target length of each searchable text section. Default: 900.') }}</p>
					</div>
					<div class="field">
						<NcTextField id="chunk-overlap" :label="$t('Chunk overlap (characters)')" v-model="f.chunk_overlap" type="number" />
						<p class="field-help">{{ $t('Repeated context between sections so sentences are not split abruptly. Default: 120.') }}</p>
					</div>
					<div class="field">
						<NcTextField id="mail-index-max" :label="$t('Emails per indexing run')" v-model="f.mail_index_max" type="number" />
						<p class="field-help">{{ $t('Only used when Mail indexing is enabled. Default: 25.') }}</p>
					</div>
					<div class="field">
						<NcTextField id="talk-index-max-rooms" :label="$t('Nextcloud Talk chats per indexing run')" v-model="f.talk_index_max_rooms" type="number" />
						<p class="field-help">{{ $t('Only used when Talk indexing is enabled. Default: 20.') }}</p>
					</div>
					<div class="field">
						<NcTextField id="talk-index-max-messages" :label="$t('Messages per Nextcloud Talk chat')" v-model="f.talk_index_max_messages" type="number" />
						<p class="field-help">{{ $t('How far back in each chat to index. Default: 200.') }}</p>
					</div>
				</div>
				<NcCheckboxRadioSwitch v-model="ocrEnabled" type="switch" :disabled="busy">
					{{ $t('Read scanned documents with local OCR') }}
				</NcCheckboxRadioSwitch>
				<NcTextField v-if="ocrEnabled" v-model="f.ocr_language" :label="$t('OCR languages (for example eng or deu+eng)')" :disabled="busy" />
				<p v-if="ocrEnabled && !status?.dependencies?.tesseract" class="field-help">{{ $t('OCR is unavailable: ask your administrator to install Tesseract and its language data.') }}</p>
				<p v-if="ocrEnabled && (!status?.dependencies?.pdftoppm || !status?.dependencies?.pdfinfo)" class="field-help">{{ $t('Scanned PDFs also require Poppler (pdfinfo and pdftoppm).') }}</p>
				<p class="field-help">{{ $t('OCR limits: 20 MiB, 30 PDF pages, 25 megapixels and 60 seconds per file. Failed extraction keeps the previous index.') }}</p>
				<NcCheckboxRadioSwitch v-model="mailIndexEnabled" type="switch" class="native-toggle compact-switch" :description="$t('Include subject, sender and message text from the Nextcloud Mail app in search results.')">{{ $t('Index Mail messages') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="talkIndexEnabled" type="switch" class="native-toggle compact-switch" :description="$t('Include the chat histories of your Nextcloud Talk conversations in search results, so older parts of a conversation can be quoted in an answer. Only chats you are a member of are indexed, and only your own chat becomes context in a Talk answer.')">{{ $t('Index Nextcloud Talk chat histories') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="talkWriteEnabled" type="switch" class="native-toggle compact-switch" :description="$t('Let EVA answer and write in your Nextcloud Talk conversations for you. A message posted this way appears under your name, exactly as if you had typed it, so EVA only posts when you explicitly ask it to. Enabling this also lets EVA read a chat on request.')">{{ $t('Let EVA post to Nextcloud Talk for me') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="indexEnrolled" type="switch" class="native-toggle compact-switch" :disabled="busy" :description="$t('Keep this account in the recurring background schedule, even when its index is currently empty. Starting indexing enables this automatically.')">{{ $t('Keep indexing this account in the background') }}
				</NcCheckboxRadioSwitch>

				<div class="exclude-paths">
					<div class="sub-heading"><strong>{{ $t('Excluded folders') }}</strong><span>{{ $t('These folders and their subfolders are skipped.') }}</span></div>
					<div v-if="excludeList.length" class="exclude-chips">
						<span v-for="(path, index) in excludeList" :key="path" class="exclude-chip">
							{{ path }}
							<NcButton class="chip-remove" variant="tertiary-no-background" :aria-label="$t('Remove {path}', { path })" :disabled="busy" @click="removeExclude(index)">×</NcButton>
						</span>
					</div>
					<p v-else class="empty-help">{{ $t('No folders are excluded.') }}</p>
					<div class="exclude-add-row">
						<NcTextField v-model="newExcludePath" :label="$t('Folder path')" :placeholder="$t('e.g. Photos or Documents/Archive')" @keydown.enter.prevent="addExclude" />
						<NcButton variant="secondary" :disabled="busy" @click="addExclude">{{ $t('Add folder') }}</NcButton>
					</div>
					<p v-if="excludeError" class="inline-error" role="alert">{{ excludeError }}</p>
					<p class="field-help">{{ $t('Changes take effect the next time you start indexing. Use paths relative to your Files root.') }}</p>
				</div>

				<div class="index-actions">
					<div>
						<strong>{{ $t('Apply indexing settings') }}</strong>
						<p>{{ $t('Save first, then rebuild the knowledge base with the current scope.') }}</p>
					</div>
					<div class="button-group">
						<NcButton variant="primary" :loading="indexing" :disabled="settingsLocked || indexingActive" @click="startIndex">{{ $t('Save & start indexing') }}</NcButton>
						<NcButton variant="secondary" :disabled="settingsLocked || indexingActive" @click="startMailIndex">{{ $t('Only index emails') }}</NcButton>
						<NcButton variant="secondary" :disabled="settingsLocked || indexingActive" @click="startTalkIndex">{{ $t('Only index Nextcloud Talk chats') }}</NcButton>
						<NcButton variant="tertiary-no-background" :disabled="settingsLocked || indexingActive" @click="resetConfirm = true">{{ $t('Delete index') }}</NcButton>
					</div>
				</div>
				<div v-if="resetConfirm" class="confirm-panel" role="alertdialog" aria-modal="true" aria-labelledby="reset-title">
					<strong id="reset-title">{{ $t('Delete the complete index?') }}</strong>
					<p>{{ $t('This removes indexed documents and vectors. Your original Nextcloud files stay untouched. You will need to start indexing again.') }}</p>
					<div class="button-group">
						<NcButton variant="tertiary-no-background" @click="resetConfirm = false">{{ $t('Cancel') }}</NcButton>
						<NcButton variant="primary" class="danger-button" :loading="resetting" @click="resetIndex">{{ $t('Delete index') }}</NcButton>
					</div>
				</div>
			</section>
</div>
</template>

<script>
import { inject } from 'vue'
import { NcButton, NcCheckboxRadioSwitch, NcTextField } from '@nextcloud/vue'

export default {
 name: 'KnowledgeSettings',
 components: { NcButton, NcCheckboxRadioSwitch, NcTextField },
 setup() { return inject('eva-settings') },
}
</script>
