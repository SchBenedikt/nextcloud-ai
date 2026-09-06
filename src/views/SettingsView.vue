<template>
	<div class="settings-view">
		<header class="page-header">
			<div class="header-copy">
				<p class="eyebrow">EVA AI</p>
				<h2 class="settings-title">{{ $t('Settings') }}</h2>
				<p class="page-intro">{{ $t('Control how EVA connects to Ollama, searches your files and is allowed to act in Nextcloud.') }}</p>
			</div>
			<div class="header-actions">
				<span v-if="saved" class="saved-label" role="status">{{ $t('Saved') }}</span>
				<NcButton type="primary" :loading="saving" :disabled="settingsLocked" @click="save">
					{{ $t('Save changes') }}
				</NcButton>
			</div>
		</header>

		<div v-if="loadError" class="callout callout-error" role="alert">
			<strong>{{ $t('Settings could not be loaded.') }}</strong>
			<span>{{ loadError }}</span>
			<NcButton type="tertiary-no-background" @click="loadStatus(true)">{{ $t('Try again') }}</NcButton>
		</div>

		<div class="summary-grid" :aria-label="$t('EVA status')">
			<div class="summary-card" :class="status && status.ollamaOnline ? 'is-ok' : 'is-muted'">
				<span class="status-dot" aria-hidden="true"></span>
				<div>
					<span class="summary-label">{{ $t('Ollama') }}</span>
					<strong>{{ status ? (status.ollamaOnline ? $t('Connected') : $t('Not connected')) : $t('Checking…') }}</strong>
					<small>{{ status?.ollamaError || status?.ollamaUrl || $t('Local AI server') }}</small>
				</div>
			</div>
			<div class="summary-card">
				<div>
					<span class="summary-label">{{ $t('Knowledge base') }}</span>
					<strong>{{ $t('{count} documents', { count: formatNumber(status?.documents) }) }}</strong>
					<small>{{ $t('{count} searchable text chunks', { count: formatNumber(status?.chunks) }) }}</small>
				</div>
			</div>
			<div class="summary-card" :class="status?.indexing ? 'is-working' : ''">
				<div>
					<span class="summary-label">{{ $t('Indexing') }}</span>
					<strong>{{ status?.indexing ? $t('In progress') : $t('Ready') }}</strong>
					<small>{{ status?.lastFinished ? $t('Last finished {time}', { time: status.lastFinished }) : $t('Run indexing after changing scope') }}</small>
				</div>
			</div>
		</div>

		<div v-if="message.text" class="callout" :class="'callout-' + message.type" :role="message.type === 'error' ? 'alert' : 'status'">
			<strong>{{ message.type === 'error' ? $t('Something went wrong') : message.type === 'success' ? $t('Done') : $t('Notice') }}</strong>
			<span>{{ message.text }}</span>
		</div>
		<div v-if="validationErrors.length" class="callout callout-error validation-summary" role="alert">
			<strong>{{ $t('Check these values') }}</strong>
			<ul><li v-for="error in validationErrors" :key="error">{{ error }}</li></ul>
		</div>

		<main class="settings-body">
			<div v-if="indexingActive" class="indexing-banner" role="status">
				<div>
					<strong>{{ status?.indexStopping ? $t('Stopping indexing…') : status?.indexMode === 'mail' ? $t('Email indexing is running') : $t('Indexing is running') }}</strong>
					<span>{{ $t('You can leave this page; the background job continues on the server. Settings stay locked until it finishes.') }}</span>
				</div>
				<NcButton type="secondary" :loading="stopping" :disabled="status?.indexStopping" @click="stopIndex">{{ $t('Stop indexing') }}</NcButton>
			</div>
			<fieldset class="settings-fieldset" :disabled="settingsLocked">
			<section class="settings-section">
				<div class="section-heading">
					<div>
						<h3>{{ $t('Connection & models') }}</h3>
						<p>{{ $t('Tell EVA where Ollama is running and which models should answer questions.') }}</p>
					</div>
				</div>

				<div class="field-grid field-grid-wide">
					<div class="field field-wide">
						<NcTextField id="ollama-url" :label="$t('Ollama server URL')" :label-outside="true" v-model="f.ollama_url" type="url" :placeholder="$t('http://127.0.0.1:11434')" />
						<p class="field-help">{{ $t('The address of your Ollama HTTP API. The default works when Ollama runs on this same server.') }}</p>
					</div>
					<div class="field">
						<label class="native-label" for="embedding-model">{{ $t('Embedding model') }}</label>
						<select id="embedding-model" v-model="f.embedding_model" class="native-select" :disabled="modelLoading || !embeddingModels.length">
							<option v-if="!embeddingModels.length" :value="f.embedding_model">{{ modelLoading ? $t('Loading models…') : $t('No embedding model found') }}</option>
							<option v-for="model in embeddingModels" :key="model" :value="model">{{ model }}</option>
						</select>
						<p class="field-help">{{ $t('EVA discovers installed models automatically from the Ollama endpoint. Embedding models turn file text into searchable vectors.') }}</p>
					</div>
					<div class="field">
						<label class="native-label" for="chat-model">{{ $t('Chat model') }}</label>
						<select id="chat-model" v-model="f.chat_model" class="native-select" :disabled="modelLoading || !chatModels.length">
							<option v-if="!chatModels.length" :value="f.chat_model">{{ modelLoading ? $t('Loading models…') : $t('No chat model found') }}</option>
							<option v-for="model in chatModels" :key="model" :value="model">{{ model }}</option>
						</select>
						<p class="field-help">{{ $t('EVA discovers installed chat models automatically from the Ollama endpoint.') }}</p>
					</div>
				</div>
				<div class="inline-actions">
					<NcButton type="secondary" :loading="checking" :disabled="busy" @click="checkOllama">{{ $t('Check connection') }}</NcButton>
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
			</section>

			<section class="settings-section">
				<div class="section-heading">
					<div>
						<h3>{{ $t('Safety & actions') }}</h3>
						<p>{{ $t('Choose what EVA may do beyond answering questions. Recommended defaults are shown below.') }}</p>
					</div>
				</div>
				<NcCheckboxRadioSwitch v-model="actionsEnabled" type="switch" class="native-toggle" :description="$t('Let EVA create, read, rename and search files, plus work with supported contacts and notes.')">{{ $t('Allow file actions') }}
					</NcCheckboxRadioSwitch>
				<div class="warning-note" :class="{ 'is-disabled': actionsDisabled }">
					<strong>{{ actionsDisabled ? $t('Actions are disabled') : $t('Actions can change your files') }}</strong>
					<span>{{ actionsDisabled ? $t('The fields below are inactive until you enable file actions.') : $t('EVA still requires confirmation for mutating or destructive operations.') }}</span>
				</div>

				<div class="field-grid" :class="{ 'is-disabled': actionsDisabled }">
					<div class="field">
						<NcTextField id="write-types" :label="$t('File types EVA may write')" :label-outside="true" v-model="f.exec_write_types" :disabled="actionsDisabled" :placeholder="$t('md, txt, csv')" />
						<p class="field-help">{{ $t('Comma-separated extensions, for example {types}. Empty means any supported text file.', { types: 'md, txt' }) }}</p>
					</div>
					<div class="field">
						<NcTextField id="write-max-chars" :label="$t('Maximum characters per file')" :label-outside="true" v-model="f.exec_write_max_chars" type="number" :disabled="actionsDisabled" />
						<p class="field-help">{{ $t('A size guard for files created or overwritten by EVA.') }}</p>
					</div>
				</div>

				<div class="choice-group" :class="{ 'is-disabled': actionsDisabled }">
					<strong class="choice-label">{{ $t('Delete permission') }}</strong>
					<NcCheckboxRadioSwitch v-model="f.exec_delete_mode" type="radio" name="delete-mode" value="off" :disabled="actionsDisabled" :description="$t('EVA cannot delete files.')">
						{{ $t('Never') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="f.exec_delete_mode" type="radio" name="delete-mode" value="own" :disabled="actionsDisabled" :description="$t('EVA may delete files it created itself.')">
						{{ $t('Only EVA-created files') }} <em>{{ $t('Recommended') }}</em>
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="f.exec_delete_mode" type="radio" name="delete-mode" value="all" :disabled="actionsDisabled" class="choice-danger" :description="$t('Allows deletion of any file after explicit confirmation. Use with care.')">
						{{ $t('Any file in my Files') }}
					</NcCheckboxRadioSwitch>
				</div>
				<NcCheckboxRadioSwitch v-model="notificationsEnabled" type="switch" class="native-toggle compact-switch" :description="$t('Uses Nextcloud Notifications when background or Talk work finishes.')">
					{{ $t('Notify me when a long answer is ready') }}
				</NcCheckboxRadioSwitch>
			</section>

			<section class="settings-section">
				<div class="section-heading">
					<div>
						<h3>{{ $t('Search & answer quality') }}</h3>
						<p>{{ $t('These settings control how much indexed context EVA retrieves and how creative its answers are.') }}</p>
					</div>
				</div>
				<div class="field-grid field-grid-three">
					<div class="field">
						<NcTextField id="top-k" :label="$t('Sources per answer')" :label-outside="true" v-model="f.top_k" type="number" />
						<p class="field-help">{{ $t('Maximum number of relevant text snippets sent to the chat model. Default: 6.') }}</p>
					</div>
					<div class="field">
						<NcTextField id="context-size" :label="$t('Model context size')" :label-outside="true" v-model="f.context_size" type="number" />
						<p class="field-help">{{ $t('Token window passed to Ollama. Match the model’s supported context size. Default: 12,288.') }}</p>
					</div>
					<div class="field">
						<NcTextField id="temperature" :label="$t('Answer creativity')" :label-outside="true" v-model="f.temperature" type="number" />
						<p class="field-help">{{ $t('0 is deterministic and factual; higher values are more varied. Default: 0.1.') }}</p>
					</div>
				</div>
			</section>

			<section class="settings-section">
				<div class="section-heading">
					<div>
						<h3>{{ $t('Indexing & scope') }}</h3>
						<p>{{ $t('Define which files become part of EVA’s private knowledge base and how they are split for search.') }}</p>
					</div>
				</div>
				<div class="field-grid field-grid-three">
					<div class="field">
						<NcTextField id="scope-path" :label="$t('Folder to index')" :label-outside="true" v-model="f.scope_path" :placeholder="$t('Everything in Files')" />
						<p class="field-help">{{ $t('Relative to your Files root. Empty indexes all files; for example use {folder} for one folder.', { folder: 'Documents/Notes' }) }}</p>
					</div>
					<div class="field">
						<NcTextField id="max-file-size" :label="$t('Maximum file size (MB)')" :label-outside="true" v-model="maxFileSizeMb" type="number" />
						<p class="field-help">{{ $t('Larger files are skipped during indexing. Default: 20 MB.') }}</p>
					</div>
					<div class="field">
						<NcTextField id="max-files" :label="$t('Files per indexing run')" :label-outside="true" v-model="f.max_files_per_run" type="number" />
						<p class="field-help">{{ $t('Limits work per run so large accounts remain responsive. Default: 40.') }}</p>
					</div>
				</div>
				<div class="field-grid field-grid-three">
					<div class="field">
						<NcTextField id="chunk-size" :label="$t('Chunk size (characters)')" :label-outside="true" v-model="f.chunk_size" type="number" />
						<p class="field-help">{{ $t('Target length of each searchable text section. Default: 900.') }}</p>
					</div>
					<div class="field">
						<NcTextField id="chunk-overlap" :label="$t('Chunk overlap (characters)')" :label-outside="true" v-model="f.chunk_overlap" type="number" />
						<p class="field-help">{{ $t('Repeated context between sections so sentences are not split abruptly. Default: 120.') }}</p>
					</div>
					<div class="field">
						<NcTextField id="mail-index-max" :label="$t('Emails per indexing run')" :label-outside="true" v-model="f.mail_index_max" type="number" />
						<p class="field-help">{{ $t('Only used when Mail indexing is enabled. Default: 25.') }}</p>
					</div>
				</div>
				<NcCheckboxRadioSwitch v-model="mailIndexEnabled" type="switch" class="native-toggle compact-switch" :description="$t('Include subject, sender and message text from the Nextcloud Mail app in search results.')">{{ $t('Index Mail messages') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="indexEnrolled" type="switch" class="native-toggle compact-switch" :disabled="busy" :description="$t('Keep this account in the recurring background schedule, even when its index is currently empty. Starting indexing enables this automatically.')">{{ $t('Keep indexing this account in the background') }}
				</NcCheckboxRadioSwitch>

				<div class="exclude-paths">
					<div class="sub-heading"><strong>{{ $t('Excluded folders') }}</strong><span>{{ $t('These folders and their subfolders are skipped.') }}</span></div>
					<div v-if="excludeList.length" class="exclude-chips">
						<span v-for="(path, index) in excludeList" :key="path" class="exclude-chip">
							{{ path }}
							<NcButton class="chip-remove" type="tertiary-no-background" :aria-label="$t('Remove {path}', { path })" :disabled="busy" @click="removeExclude(index)">×</NcButton>
						</span>
					</div>
					<p v-else class="empty-help">{{ $t('No folders are excluded.') }}</p>
					<div class="exclude-add-row">
						<NcTextField v-model="newExcludePath" :label="$t('Folder path')" :label-outside="true" :placeholder="$t('e.g. Photos or Documents/Archive')" @keydown.enter.prevent="addExclude" />
						<NcButton type="secondary" :disabled="busy" @click="addExclude">{{ $t('Add folder') }}</NcButton>
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
						<NcButton type="primary" :loading="indexing" :disabled="settingsLocked" @click="startIndex">{{ $t('Save & start indexing') }}</NcButton>
						<NcButton type="secondary" :disabled="settingsLocked" @click="startMailIndex">{{ $t('Only index emails') }}</NcButton>
						<NcButton type="tertiary-no-background" :disabled="settingsLocked" @click="resetConfirm = true">{{ $t('Delete index') }}</NcButton>
					</div>
				</div>
				<div v-if="resetConfirm" class="confirm-panel" role="alertdialog" aria-modal="true" aria-labelledby="reset-title">
					<strong id="reset-title">{{ $t('Delete the complete index?') }}</strong>
					<p>{{ $t('This removes indexed documents and vectors. Your original Nextcloud files stay untouched. You will need to start indexing again.') }}</p>
					<div class="button-group">
						<NcButton type="tertiary-no-background" @click="resetConfirm = false">{{ $t('Cancel') }}</NcButton>
						<NcButton type="primary" class="danger-button" :loading="resetting" @click="resetIndex">{{ $t('Delete index') }}</NcButton>
					</div>
				</div>
			</section>

			<section class="settings-section">
				<div class="section-heading">
					<div>
						<h3>{{ $t('Chat history') }}</h3>
						<p>{{ $t('Manage the conversations stored for your Nextcloud account. This does not affect indexed files.') }}</p>
					</div>
				</div>
				<div class="index-actions chat-history-actions">
					<div>
						<strong>{{ $t('Delete all chats') }}</strong>
						<p>{{ $t('Permanently removes your saved EVA conversations and messages.') }}</p>
					</div>
					<NcButton type="tertiary-no-background" :disabled="settingsLocked" @click="chatsDeleteConfirm = true">{{ $t('Delete all chats') }}</NcButton>
				</div>
				<div v-if="chatsDeleteConfirm" class="confirm-panel" role="alertdialog" aria-modal="true" aria-labelledby="chats-delete-title">
					<strong id="chats-delete-title">{{ $t('Delete all chat history?') }}</strong>
					<p>{{ $t('This cannot be undone. Your indexed documents and files will remain untouched.') }}</p>
					<div class="button-group">
						<NcButton type="tertiary-no-background" @click="chatsDeleteConfirm = false">{{ $t('Cancel') }}</NcButton>
						<NcButton type="primary" class="danger-button" :loading="deletingChats" @click="deleteAllChats">{{ $t('Delete all chats') }}</NcButton>
					</div>
				</div>
			</section>

			<section class="settings-section">
				<div class="section-heading">
					<div>
						<h3>{{ $t('Talk & notifications') }}</h3>
						<p>{{ $t('Configure how EVA behaves when she is used from Nextcloud Talk.') }}</p>
					</div>
				</div>
				<div class="field-grid">
					<div class="field">
						<NcTextField id="talk-history" :label="$t('Talk history size')" :label-outside="true" v-model="f.talk_history_size" type="number" />
						<p class="field-help">{{ $t('Number of recent Talk messages sent as context. Default: 50.') }}</p>
					</div>
					<div class="field">
						<NcTextField id="talk-trigger" :label="$t('Trigger name')" :label-outside="true" v-model="f.talk_bot_trigger" :placeholder="$t('Eva')" />
						<p class="field-help">{{ $t('The name people mention to address EVA in Talk. Example: {mention}.', { mention: '@Eva' }) }}</p>
					</div>
				</div>
				<div class="help-box">
					<strong>{{ $t('Privacy reminder') }}</strong>
					<span>{{ $t('Indexed content stays in Nextcloud and is sent to the Ollama server configured above. Review your indexing scope before enabling Mail or Talk features.') }}</span>
				</div>
			</section>
            <section class="settings-section">
                <h3>{{ $t('Privacy and extraction') }}</h3>
                <label v-for="option in privacyOptions" :key="option.key" class="field-help" style="display:block">
                    <input type="checkbox" :checked="f[option.key] === '1'" @change="f[option.key] = $event.target.checked ? '1' : '0'"> {{ option.label }}
                </label>
                <p>{{ $t('Unknown model capabilities require an explicit choice. Known incompatible model roles are rejected.') }}</p>
                <label>{{ $t('Fallback chat models (in listed order)') }}
                    <select multiple :value="f.chat_fallback_models.split(',').filter(Boolean)" @change="f.chat_fallback_models = Array.from($event.target.selectedOptions).map(o => o.value).slice(0, 3).join(',')">
                        <option v-for="model in chatModels" :key="model" :value="model">{{ model }}</option>
                    </select>
                </label>
                <label>{{ $t('Summary model') }}
                    <select v-model="f.summary_model"><option value="">{{ $t('Default chat model') }}</option><option v-for="model in chatModels" :key="model" :value="model">{{ model }}</option></select>
                </label>
                <label>{{ $t('Tool model') }}
                    <select v-model="f.tool_model"><option value="">{{ $t('Default chat model') }}</option><option v-for="model in chatModels" :key="model" :value="model">{{ model }}</option></select>
                </label>
                <div v-if="extraction">
                    <h4>{{ $t('Local extraction tools') }}</h4>
                    <p v-for="(available, name) in extraction.tools" :key="name">{{ name }}: {{ available ? $t('Available') : $t('Not installed') }}</p>
                    <p>{{ extraction.office.join(', ') }}</p>
                    <p>{{ $t('Office and PDF sources are limited to 32 MiB. OCR supports up to 20 pages.') }}</p>
                </div>
            </section>
			</fieldset>
		</main>
	</div>
</template>

<script>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { api, errMsg } from '../lib/api'
import { translate as t } from '../lib/i18n'

export default {
	name: 'SettingsView',
	components: { NcCheckboxRadioSwitch },
	setup() {
		const f = ref({
            weather_enabled: '0', talk_classification_enabled: '0', personalization_enabled: '1', ocr_enabled: '0',
            chat_fallback_models: '', summary_model: '', tool_model: '',
			ollama_url: 'http://127.0.0.1:11434',
			embedding_model: 'nomic-embed-text',
			chat_model: 'gemma4:cloud',
			temperature: '0.1',
			actions_enabled: '1',
			notify_on_complete: '1',
			exec_write_types: '',
			exec_write_max_chars: '100000',
			exec_delete_mode: 'own',
			top_k: '6',
			context_size: '12288',
			chunk_size: '900',
			chunk_overlap: '120',
			max_file_size: '20971520',
			max_files_per_run: '40',
			mail_index_max: '25',
			mail_index_enabled: '1',
			index_enrolled: '0',
			scope_path: '',
			talk_history_size: '50',
			talk_bot_trigger: 'Eva',
			exclude_paths: '',
		})
		const status = ref(null)
		const limits = ref({})
		const availableModels = ref([])
        const modelDetails = ref([])
        const extraction = ref(null)
        const privacyOptions = [
            { key: 'weather_enabled', label: t('Allow external weather requests') },
            { key: 'talk_classification_enabled', label: t('Allow AI classification of unmentioned Talk messages') },
            { key: 'personalization_enabled', label: t('Use personal knowledge in answers') },
            { key: 'ocr_enabled', label: t('Enable local OCR for scans and images') },
        ]
		const modelLoading = ref(false)
		const modelError = ref('')
		const checkOut = ref(null)
		const saving = ref(false)
		const checking = ref(false)
		const indexing = ref(false)
		const resetting = ref(false)
		const deletingChats = ref(false)
		const stopping = ref(false)
		const saved = ref(false)
		const loadError = ref('')
		const message = ref({ type: '', text: '' })
		const validationErrors = ref([])
		const resetConfirm = ref(false)
		const chatsDeleteConfirm = ref(false)
		const newExcludePath = ref('')
		const excludeError = ref('')

		const excludeList = computed(() => {
			const raw = (f.value.exclude_paths || '').trim()
			return raw ? raw.split(',').map(path => path.trim()).filter(Boolean) : []
		})
		const actionsEnabled = computed({
			get: () => f.value.actions_enabled === '1',
			set: value => { f.value.actions_enabled = value ? '1' : '0' },
		})
		const notificationsEnabled = computed({
			get: () => f.value.notify_on_complete === '1',
			set: value => { f.value.notify_on_complete = value ? '1' : '0' },
		})
		const mailIndexEnabled = computed({
			get: () => f.value.mail_index_enabled === '1',
			set: value => { f.value.mail_index_enabled = value ? '1' : '0' },
		})
		const indexEnrolled = computed({
			get: () => f.value.index_enrolled === '1',
			set: value => { f.value.index_enrolled = value ? '1' : '0' },
		})
		const actionsDisabled = computed(() => f.value.actions_enabled !== '1')
        const supports = (name, role) => {
            const detail = modelDetails.value.find((m) => m.name === name)
            return !Array.isArray(detail?.capabilities) || detail.capabilities.includes(role)
        }
        const embeddingModels = computed(() => availableModels.value.filter((name) => supports(name, 'embedding')))
        const chatModels = computed(() => availableModels.value.filter((name) => supports(name, 'completion')))

		const indexingActive = computed(() => indexing.value || status.value?.indexing === true)
		const busy = computed(() => saving.value || checking.value || indexing.value || resetting.value || deletingChats.value || stopping.value)
		const settingsLocked = computed(() => busy.value || indexingActive.value)
		const maxFileSizeMb = computed({
			get: () => {
				const bytes = Number(f.value.max_file_size) || 0
				return String(Math.max(1, Math.round(bytes / (1024 * 1024))) )
			},
			set: value => {
				const mb = Math.max(1, Math.min(2048, Number(value) || 1))
				f.value.max_file_size = String(Math.round(mb * 1024 * 1024))
			},
		})

		function formatNumber(value) {
			return Number(value || 0).toLocaleString()
		}

		function setMessage(type, text) {
			message.value = { type, text }
		}

		function validate() {
			const errors = []
			const effective = (key, fallback) => limits.value[key] || fallback
			const numberRules = [
				['top_k', 'Sources per answer', ...effective('top_k', [1, 8])],
				['context_size', 'Model context size', ...effective('context_size', [256, 131072])],
				['temperature', 'Answer creativity', ...effective('temperature', [0, 2])],
				['chunk_size', 'Chunk size', ...effective('chunk_size', [128, 10000])],
				['chunk_overlap', 'Chunk overlap', ...effective('chunk_overlap', [0, 5000])],
				['max_files_per_run', 'Files per indexing run', ...effective('max_files_per_run', [1, 10000])],
				['mail_index_max', 'Emails per indexing run', ...effective('mail_index_max', [1, 500])],
				['talk_history_size', 'Talk history size', ...effective('talk_history_size', [1, 500])],
				['exec_write_max_chars', 'Maximum characters per file', ...effective('exec_write_max_chars', [1, 10000000])],
			]
			if (!/^https?:\/\//i.test(f.value.ollama_url.trim())) errors.push('Ollama server URL must start with http:// or https://.')
			if (!f.value.embedding_model.trim()) errors.push('Embedding model is required.')
			if (!f.value.chat_model.trim()) errors.push('Chat model is required.')
			for (const [key, label, min, max] of numberRules) {
				const value = Number(f.value[key])
				if (!Number.isFinite(value) || value < min || value > max) errors.push(`${label} must be between ${min} and ${max}.`)
			}
			const fileSizeMb = Number(maxFileSizeMb.value)
			if (!Number.isFinite(fileSizeMb) || fileSizeMb < 1 || fileSizeMb > 2048) errors.push('Maximum file size must be between 1 and 2048 MB.')
			if (Number(f.value.chunk_overlap) > Number(f.value.chunk_size)) errors.push('Chunk overlap cannot be larger than chunk size.')
			return errors
		}

		function applyModelDiscovery(names) {
			availableModels.value = [...new Set((names || []).map((name) => String(name || '').trim()).filter(Boolean))]
			const embeddings = embeddingModels.value
			const chats = chatModels.value
			if (embeddings.length && !embeddings.includes(f.value.embedding_model)) {
				f.value.embedding_model = embeddings[0]
			}
			if (chats.length && !chats.includes(f.value.chat_model)) {
				f.value.chat_model = chats[0]
			}
		}

		async function discoverModels(endpoint = f.value.ollama_url) {
			const url = String(endpoint || '').trim()
			if (!/^https?:\/\//i.test(url)) return
			modelLoading.value = true
			modelError.value = ''
			try {
				const data = await api('GET', 'models', { endpoint: url })
                modelDetails.value = data?.details || []
                extraction.value = data?.extraction || null
				applyModelDiscovery(data?.models || [])
				if (!availableModels.value.length) modelError.value = t('No models are installed in this Ollama endpoint.')
			} catch (error) {
				modelError.value = t('Models could not be loaded: {error}', { error: errMsg(error) })
			} finally {
				modelLoading.value = false
			}
		}

		function fill(settings = status.value?.settings) {
			if (!settings) return
			Object.keys(f.value).forEach(key => {
				if (settings[key] !== undefined && settings[key] !== null) {
					f.value[key] = String(settings[key])
				}
			})
		}

		async function loadStatus(syncForm = false) {
			loadError.value = ''
			try {
				status.value = await api('GET', 'status')
				limits.value = status.value?.limits || {}
				if (syncForm) fill()
				if (Array.isArray(status.value?.models)) applyModelDiscovery(status.value.models)
				if (syncForm) await discoverModels(f.value.ollama_url)
			} catch (error) {
				loadError.value = errMsg(error)
			}
		}

		async function save() {
			if (saving.value) return false
			validationErrors.value = validate()
			if (validationErrors.value.length) {
				setMessage('error', t('Please correct the highlighted settings before saving.'))
				return false
			}
			saving.value = true
			saved.value = false
			message.value = { type: '', text: '' }
			try {
				const settings = await api('PUT', 'settings', { ...f.value })
				validationErrors.value = []
				fill(settings)
				saved.value = true
				setMessage('success', t('Your settings were saved.'))
				window.setTimeout(() => { saved.value = false }, 3000)
				return true
			} catch (error) {
				setMessage('error', t('The settings could not be saved: {error}', { error: errMsg(error) }))
				return false
			} finally {
				saving.value = false
			}
		}

		async function checkOllama() {
			if (checking.value) return
			checking.value = true
			checkOut.value = null
			const savedSuccessfully = await save()
			if (!savedSuccessfully) {
				checkOut.value = { type: 'error', lines: [{ label: t('Connection test'), ok: false, detail: t('Correct the settings above before testing the connection.') }] }
				checking.value = false
				return
			}
			try {
				const data = await api('POST', 'check')
				const lines = []
				const server = data.server || {}
				lines.push({ label: t('Server'), ok: !!server.ok, detail: server.ok ? (server.url || t('Reachable')) : (server.error || t('Not reachable')) })
				if (data.embedding) {
					const embedding = data.embedding
					lines.push({ label: t('Embedding model'), ok: !!embedding.ok, detail: embedding.ok ? t('{model} · {count} dimensions', { model: embedding.model, count: embedding.len }) : `${embedding.model || f.value.embedding_model}: ${embedding.error || t('Not available')}` })
				}
				if (data.chat) {
					const chat = data.chat
					lines.push({ label: t('Chat model'), ok: !!chat.ok, detail: chat.ok ? t('{model} · responded in {seconds}s', { model: chat.model, seconds: chat.seconds }) : `${chat.model || f.value.chat_model}: ${chat.error || t('Not available')}` })
				}
				checkOut.value = { type: lines.every(line => line.ok) ? 'success' : 'error', lines }
			} catch (error) {
				checkOut.value = { type: 'error', lines: [{ label: t('Connection test'), ok: false, detail: errMsg(error) }] }
			} finally {
				checking.value = false
			}
		}

		async function persistExcludeList(list, previous) {
			f.value.exclude_paths = list.join(', ')
			const savedSuccessfully = await save()
			if (!savedSuccessfully) {
				f.value.exclude_paths = previous
				excludeError.value = t('The folder exclusion could not be saved. Your previous exclusions were restored.')
				return false
			}
			return true
		}

		async function addExclude() {
			excludeError.value = ''
			const path = newExcludePath.value.trim().replace(/^\/+|\/+$/g, '')
			if (!path) {
				excludeError.value = t('Enter a folder path first.')
				return
			}
			if (path.split('/').some(part => part === '.' || part === '..')) {
				excludeError.value = t('Use a path inside your Files folder; relative traversal is not allowed.')
				return
			}
			const list = excludeList.value.slice()
			if (list.includes(path)) {
				excludeError.value = t('This folder is already excluded.')
				return
			}
			const previous = f.value.exclude_paths
			list.push(path)
			if (await persistExcludeList(list, previous)) newExcludePath.value = ''
		}

		async function removeExclude(index) {
			excludeError.value = ''
			const list = excludeList.value.slice()
			list.splice(index, 1)
			await persistExcludeList(list, f.value.exclude_paths)
		}

		async function deleteAllChats() {
			if (deletingChats.value) return
			chatsDeleteConfirm.value = false
			deletingChats.value = true
			setMessage('info', t('Deleting all chat history…'))
			try {
				const response = await api('DELETE', 'chats')
				window.dispatchEvent(new CustomEvent('eva-ai:chats-cleared'))
				setMessage('success', t('{count} chats deleted.', { count: formatNumber(response?.deleted) }))
			} catch (error) {
				setMessage('error', t('The chat history could not be deleted: {error}', { error: errMsg(error) }))
			} finally {
				deletingChats.value = false
			}
		}

		async function startIndex() {
			if (settingsLocked.value) return
			const savedSuccessfully = await save()
			if (!savedSuccessfully) {
				setMessage('error', t('Indexing was not started because the settings could not be saved.'))
				return
			}
			indexing.value = true
			setMessage('info', t('Indexing is running. This can take a while for large file collections.'))
			try {
				const response = await api('POST', 'index')
				status.value = response?.status || status.value
				setMessage('info', t('Indexing was queued in the background. You can leave this page safely.'))
			} catch (error) {
				setMessage('error', t('Indexing could not be queued: {error}', { error: errMsg(error) }))
			} finally {
				indexing.value = false
			}
		}

		async function startMailIndex() {
			if (settingsLocked.value) return
			const savedSuccessfully = await save()
			if (!savedSuccessfully) {
				setMessage('error', t('Email indexing was not started because the settings could not be saved.'))
				return
			}
			indexing.value = true
			setMessage('info', t('Email indexing is being queued in the background.'))
			try {
				const response = await api('POST', 'mailIndex')
				status.value = response?.status || status.value
				setMessage('info', t('Email indexing was queued. You can leave this page safely.'))
			} catch (error) {
				setMessage('error', t('Email indexing could not be queued: {error}', { error: errMsg(error) }))
			} finally {
				indexing.value = false
			}
		}

		async function stopIndex() {
			if (stopping.value) return
			stopping.value = true
			try {
				const response = await api('POST', 'indexStop')
				status.value = response?.status || status.value
				setMessage('info', response?.stopping ? t('Stop requested. Indexing will finish the current cancellable request and then release its lock.') : t('Indexing is stopped.'))
			} catch (error) {
				setMessage('error', t('Indexing could not be stopped: {error}', { error: errMsg(error) }))
			} finally {
				stopping.value = false
				await loadStatus()
			}
		}

		async function resetIndex() {
			if (resetting.value) return
			resetConfirm.value = false
			resetting.value = true
			setMessage('info', t('Deleting the index…'))
			try {
				const response = await api('POST', 'indexReset')
				const result = response.result || {}
				setMessage('success', t('Index deleted: {documents} documents and {chunks} chunks removed.', { documents: formatNumber(result.documents), chunks: formatNumber(result.chunks) }))
				await loadStatus()
			} catch (error) {
				setMessage('error', t('The index could not be deleted: {error}', { error: errMsg(error) }))
			} finally {
				resetting.value = false
			}
		}

		let statusTimer = null
		let modelTimer = null
		watch(() => f.value.ollama_url, (value) => {
			window.clearTimeout(modelTimer)
			modelTimer = window.setTimeout(() => discoverModels(value), 500)
		})
		onMounted(async () => {
			await loadStatus(true)
			statusTimer = window.setInterval(loadStatus, 3000)
		})
		onUnmounted(() => {
			if (statusTimer !== null) window.clearInterval(statusTimer)
			if (modelTimer !== null) window.clearTimeout(modelTimer)
		})

		return {
			f, status, limits, modelDetails, extraction, privacyOptions, availableModels, embeddingModels, chatModels, modelLoading, modelError, checkOut, saving, checking, indexing, resetting, deletingChats, stopping, saved, loadError, message, validationErrors, resetConfirm, chatsDeleteConfirm,
			newExcludePath, excludeError, excludeList, actionsEnabled, notificationsEnabled, mailIndexEnabled, indexEnrolled, actionsDisabled, busy, indexingActive, settingsLocked, maxFileSizeMb,
			formatNumber, loadStatus, save, checkOllama, addExclude, removeExclude, startIndex, startMailIndex, stopIndex, resetIndex, deleteAllChats,
		}
	},
}
</script>

<style scoped lang="scss">
.settings-view {
	width: 100%;
	max-width: var(--eva-content-width, 1180px);
	margin: 0 auto;
	padding: 24px clamp(16px, 3vw, 36px) 48px;
	box-sizing: border-box;
}

.page-header {
	display: flex;
	align-items: flex-end;
	justify-content: space-between;
	gap: 24px;
	margin-bottom: 24px;
}

.header-copy { max-width: 700px; }
.eyebrow {
	margin: 0 0 4px;
	color: var(--color-primary-element);
	font-size: 12px;
	font-weight: 700;
	letter-spacing: .08em;
	text-transform: uppercase;
}
.settings-title { margin: 0; font-size: clamp(24px, 3vw, 32px); font-weight: 700; letter-spacing: -.02em; }
.page-intro { margin: 8px 0 0; color: var(--color-text-maxcontrast); font-size: 14px; line-height: 1.55; }
.header-actions { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
.saved-label { color: var(--color-success); font-size: 13px; font-weight: 600; }

.summary-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
.summary-card {
	display: flex; align-items: flex-start; gap: 12px; min-height: 76px; padding: 14px;
	border: 1px solid var(--color-border); border-radius: 12px; background: var(--color-main-background);
	box-sizing: border-box;
}
.summary-card.is-ok { border-color: color-mix(in srgb, var(--color-success) 40%, var(--color-border)); }
.summary-card.is-working { border-color: color-mix(in srgb, var(--color-primary-element) 45%, var(--color-border)); }
.status-dot { width: 10px; height: 10px; margin-top: 5px; border-radius: 50%; background: var(--color-warning); flex: 0 0 auto; }
.is-ok .status-dot { background: var(--color-success); }
.summary-icon { width: 22px; color: var(--color-primary-element); font-size: 22px; line-height: 1; text-align: center; }
.summary-label { display: block; color: var(--color-text-maxcontrast); font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
.summary-card strong { display: block; margin-top: 3px; font-size: 15px; }
.summary-card small { display: block; margin-top: 4px; color: var(--color-text-maxcontrast); font-size: 12px; overflow-wrap: anywhere; }

.callout { display: flex; align-items: center; flex-wrap: wrap; gap: 8px 12px; margin: 0 0 16px; padding: 12px 14px; border: 1px solid var(--color-border); border-radius: 10px; font-size: 13px; }
.callout strong { font-weight: 700; }
.callout span { color: var(--color-text-maxcontrast); }
.callout-success { border-color: color-mix(in srgb, var(--color-success) 48%, var(--color-border)); background: color-mix(in srgb, var(--color-success) 8%, var(--color-main-background)); }
.callout-success strong { color: var(--color-success); }
.callout-error { border-color: color-mix(in srgb, var(--color-error) 50%, var(--color-border)); background: color-mix(in srgb, var(--color-error) 8%, var(--color-main-background)); }
.callout-error strong { color: var(--color-error); }
.callout-info { border-color: color-mix(in srgb, var(--color-primary-element) 45%, var(--color-border)); background: color-mix(in srgb, var(--color-primary-element) 7%, var(--color-main-background)); }
.callout-info strong { color: var(--color-primary-element); }
.validation-summary ul { margin: 0; padding-left: 18px; color: var(--color-text-maxcontrast); }
.validation-summary li { margin: 2px 0; }

.settings-body { display: flex; flex-direction: column; gap: 16px; }
.settings-fieldset { min-inline-size: 0; margin: 0; padding: 0; border: 0; }
.settings-fieldset:disabled { opacity: .72; }
.indexing-banner { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 4px; padding: 13px 14px; border: 1px solid color-mix(in srgb, var(--color-primary-element) 42%, var(--color-border)); background: color-mix(in srgb, var(--color-primary-element) 7%, var(--color-main-background)); }
.indexing-banner strong, .indexing-banner span { display: block; }
.indexing-banner span { margin-top: 3px; color: var(--color-text-maxcontrast); font-size: 12px; }
.settings-section { padding: 20px 0; border-bottom: 1px solid var(--color-border); }
.settings-section:first-child { padding-top: 0; }
.settings-section:last-child { border-bottom: 0; }
.section-heading { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 20px; }
.section-heading h3 { margin: 0; font-size: 17px; }
.section-heading p { margin: 4px 0 0; color: var(--color-text-maxcontrast); font-size: 13px; line-height: 1.5; }
.field-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px 16px; }
.field-grid-three { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.field-grid-wide { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.field-wide { grid-column: 1 / -1; }
.field { min-width: 0; }
.native-label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 600; }
.native-select { width: 100%; min-height: 42px; padding: 8px 34px 8px 10px; border: 2px solid var(--color-border); border-radius: var(--border-radius-large, 8px); background: var(--color-main-background); color: var(--color-main-text); font: inherit; }
.native-select:focus { border-color: var(--color-primary-element); outline: 2px solid color-mix(in srgb, var(--color-primary-element) 25%, transparent); outline-offset: 1px; }
.native-select:disabled { opacity: .65; }
.sub-heading strong { display: block; margin-bottom: 4px; font-size: 14px; font-weight: 600; }
.field-help { margin: 6px 0 0; color: var(--color-text-maxcontrast); font-size: 12px; line-height: 1.5; }
.field-help code, .help-box code { padding: 1px 4px; border-radius: 4px; background: var(--color-background-dark); font-family: var(--font-family-monospace, monospace); }
.inline-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 12px; margin-top: 18px; }
.action-hint { color: var(--color-text-maxcontrast); font-size: 12px; }
.action-error { color: var(--color-error); }

.check-panel { display: grid; gap: 8px; margin-top: 14px; padding: 12px; border: 1px solid var(--color-border); border-radius: 10px; }
.check-success { border-color: color-mix(in srgb, var(--color-success) 45%, var(--color-border)); background: color-mix(in srgb, var(--color-success) 6%, var(--color-main-background)); }
.check-error { border-color: color-mix(in srgb, var(--color-error) 45%, var(--color-border)); background: color-mix(in srgb, var(--color-error) 6%, var(--color-main-background)); }
.check-line { display: flex; gap: 9px; align-items: flex-start; font-size: 13px; }
.check-mark { display: grid; place-items: center; width: 18px; height: 18px; border-radius: 50%; background: var(--color-error); color: #fff; font-weight: 700; flex: 0 0 auto; }
.check-success .check-mark { background: var(--color-success); }
.check-line strong { display: block; }
.check-line div span { display: block; margin-top: 2px; color: var(--color-text-maxcontrast); overflow-wrap: anywhere; }

.native-toggle { display: flex; margin-top: 8px; }
.native-toggle :deep(.checkbox-radio-switch__text) { font-weight: 600; }
.native-toggle :deep(.checkbox-content__description) { color: var(--color-text-maxcontrast); font-size: 12px; line-height: 1.45; }
.warning-note { display: flex; flex-wrap: wrap; gap: 5px 9px; margin: 12px 0 16px; padding: 10px 12px; border-left: 3px solid var(--color-warning); background: color-mix(in srgb, var(--color-warning) 9%, var(--color-main-background)); font-size: 12px; }
.warning-note strong { color: var(--color-warning-text, var(--color-main-text)); }
.warning-note span { color: var(--color-text-maxcontrast); }
.warning-note.is-disabled { border-left-color: var(--color-text-maxcontrast); background: var(--color-background-hover); }
.is-disabled { opacity: .7; }
.choice-group { display: grid; gap: 8px; margin: 18px 0 0; padding: 0; }
.choice-label { font-size: 14px; font-weight: 600; }
.choice-danger :deep(.checkbox-radio-switch__text) { color: var(--color-error); }
.choice-group em { color: var(--color-success); font-size: 11px; font-style: normal; font-weight: 600; }
.compact-switch { margin-top: 18px; }

.exclude-paths { margin-top: 22px; padding-top: 18px; border-top: 1px solid var(--color-border); }
.sub-heading span { display: block; margin-top: -2px; color: var(--color-text-maxcontrast); font-size: 12px; }
.exclude-chips { display: flex; flex-wrap: wrap; gap: 7px; margin: 14px 0 8px; }
.exclude-chip { display: inline-flex; align-items: center; gap: 6px; max-width: 100%; padding: 5px 8px 5px 10px; border: 1px solid var(--color-border); border-radius: 999px; background: var(--color-background-hover); font-size: 12px; overflow-wrap: anywhere; }
.chip-remove { min-width: 24px; margin: -4px -5px -4px 0; padding: 0; }
.empty-help { margin: 14px 0 8px; color: var(--color-text-maxcontrast); font-size: 12px; }
.exclude-add-row { display: flex; align-items: center; gap: 8px; margin-top: 10px; }
.exclude-add-row > :first-child { flex: 1; min-width: 0; }
.inline-error { margin: 7px 0 0; color: var(--color-error); font-size: 12px; }

.index-actions { display: flex; align-items: center; justify-content: space-between; gap: 18px; margin-top: 24px; padding: 16px; border: 1px solid color-mix(in srgb, var(--color-primary-element) 28%, var(--color-border)); border-radius: 11px; background: color-mix(in srgb, var(--color-primary-element) 5%, var(--color-main-background)); }
.index-actions strong { font-size: 14px; }
.index-actions p { margin: 4px 0 0; color: var(--color-text-maxcontrast); font-size: 12px; }
.button-group { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 8px; }
.confirm-panel { margin-top: 12px; padding: 14px; border: 1px solid var(--color-error); border-radius: 10px; background: color-mix(in srgb, var(--color-error) 7%, var(--color-main-background)); }
.confirm-panel strong { color: var(--color-error); }
.confirm-panel p { margin: 5px 0 12px; color: var(--color-text-maxcontrast); font-size: 13px; line-height: 1.5; }
.danger-button { --color-primary-element: var(--color-error); }
.help-box { display: flex; flex-wrap: wrap; gap: 6px 10px; margin-top: 18px; padding: 12px; border-radius: 9px; background: var(--color-background-hover); font-size: 12px; line-height: 1.5; }
.help-box strong { color: var(--color-primary-element); }
.help-box span { color: var(--color-text-maxcontrast); }

@media (max-width: 800px) {
	.page-header { align-items: flex-start; flex-direction: column; }
	.header-actions { width: 100%; justify-content: space-between; }
	.summary-grid, .field-grid-three, .field-grid-wide { grid-template-columns: 1fr; }
	.field-wide { grid-column: auto; }
	.indexing-banner { align-items: flex-start; flex-direction: column; }
	.index-actions { align-items: flex-start; flex-direction: column; }
	.button-group { justify-content: flex-start; width: 100%; }
}

@media (max-width: 500px) {
	.settings-view { padding: 18px 12px 36px; }
	.settings-section { padding: 18px 0; }
	.exclude-add-row { align-items: stretch; flex-direction: column; }
	.exclude-add-row :deep(.button-vue) { width: 100%; }
}
</style>
